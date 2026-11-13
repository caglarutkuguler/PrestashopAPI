<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * Guards the 2.4.0 "waiting for you" rule.
 *
 * Runs without PrestaShop: `php tests/ThreadWaitingTest.php`.
 *
 * What it is here to catch: up to 2.3.0 a conversation went back to unread whenever its
 * thread row changed at all, so the seller's own replies and product renames piled up as
 * "975 conversations are waiting for you" on an account with nothing waiting. A conversation
 * may only wait when the buyer wrote last and the seller has not seen the message.
 */
if (PHP_SAPI === 'cli' && !defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0'); // standalone run outside PrestaShop
}
if (!defined('_PS_VERSION_')) {
    exit;
}

class Configuration
{
    public static $store = array();

    public static function get($k)
    {
        return array_key_exists($k, self::$store) ? self::$store[$k] : false;
    }

    public static function updateValue($k, $v)
    {
        self::$store[$k] = (string) $v;

        return true;
    }
}

class Shop
{
    public static function isFeatureActive()
    {
        return false;
    }
}

class Tools
{
    public static function substr($s, $start, $length = null)
    {
        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    }

    public static function strtolower($s)
    {
        return strtolower($s);
    }
}

/** An in-memory stand-in for the two tables, understanding only the statements used here. */
class Db
{
    /** @var array id_thread => array{fingerprint, is_read, is_pinned} */
    public static $state = array();
    public static $cache = array();
    public static $writes = 0;
    private static $instance;

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function executeS($sql)
    {
        $rows = array();

        foreach (self::$state as $id => $row) {
            $rows[] = array('id_thread' => $id) + $row;
        }

        return $rows;
    }

    public function getRow($sql)
    {
        if (preg_match('/`cache_key` = "([^"]+)"/', $sql, $m)) {
            return isset(self::$cache[$m[1]]) ? self::$cache[$m[1]] : false;
        }

        $unread = 0;
        $pinned = 0;

        foreach (self::$state as $row) {
            $unread += $row['is_read'] ? 0 : 1;
            $pinned += $row['is_pinned'] ? 1 : 0;
        }

        return array('total' => count(self::$state), 'unread' => $unread, 'pinned' => $pinned);
    }

    public function execute($sql)
    {
        if (strpos($sql, 'prestashopapi_cache') !== false) {
            if (preg_match('/VALUES \("([^"]+)",\s*"(.*)",\s*"([^"]*)",\s*"([^"]*)"\)\s*$/s', $sql, $m)) {
                self::$cache[$m[1]] = array('payload' => stripslashes($m[2]), 'date_add' => $m[3], 'expires' => $m[4]);
            }

            return true;
        }

        ++self::$writes;

        if (strpos($sql, 'REPLACE INTO') === 0) {
            preg_match_all('/\((\d+), "([0-9a-f]{32})", (\d), (\d), "[^"]*"\)/', $sql, $all, PREG_SET_ORDER);

            foreach ($all as $m) {
                self::$state[(int) $m[1]] = array('fingerprint' => $m[2], 'is_read' => (int) $m[3], 'is_pinned' => (int) $m[4]);
            }
        } elseif (preg_match('/^UPDATE .*WHERE `id_thread` = (\d+)/s', $sql, $m)) {
            $id = (int) $m[1];

            if (preg_match('/`fingerprint` = "([0-9a-f]{32})"/', $sql, $f)) {
                self::$state[$id]['fingerprint'] = $f[1];
            }

            if (preg_match('/`is_read` = (\d)/', $sql, $r)) {
                self::$state[$id]['is_read'] = (int) $r[1];
            }
        } elseif (preg_match('/^UPDATE .*SET `is_read` = (\d)\s*$/s', $sql, $m)) {
            foreach (self::$state as $id => $row) {
                self::$state[$id]['is_read'] = (int) $m[1];
            }
        } elseif (preg_match('/^DELETE .*IN \(([\d,]+)\)/s', $sql, $m)) {
            foreach (explode(',', $m[1]) as $id) {
                unset(self::$state[(int) $id]);
            }
        } else {
            throw new Exception('Unexpected statement: ' . $sql);
        }

        return true;
    }
}

function pSQL($s, $html_ok = false)
{
    return addslashes((string) $s);
}

define('_DB_PREFIX_', 'ps_');

require_once dirname(__FILE__) . '/../classes/SellerThreadState.php';
require_once dirname(__FILE__) . '/../classes/SellerApiClient.php';

$failures = 0;
$checks = 0;

function check($label, $condition)
{
    global $failures, $checks;
    ++$checks;

    if ($condition) {
        echo "  ok   $label\n";
    } else {
        ++$failures;
        echo "  FAIL $label\n";
    }
}

function unread()
{
    $ids = array();

    foreach (Db::$state as $id => $row) {
        if (!$row['is_read']) {
            $ids[] = $id;
        }
    }

    sort($ids);

    return $ids;
}

$now = strtotime('2026-11-13 12:00:00');

/* ------------------------------------------------------------------ *
 * Fixture, in the live shape of seller/messages and seller/threads.
 * ------------------------------------------------------------------ */

function thread($id, $nb, $name = 'Module')
{
    return array('id_community_thread' => $id, 'topic' => 'T' . $id, 'name' => $name,
        'date_add' => '2024-01-01 00:00:00', 'nb_messages' => $nb, 'token' => 'tok' . $id);
}

function message($thread, $from, $date, $seen)
{
    return array('id_community_thread' => $thread, 'from' => $from, 'date_add' => $date, 'seen' => $seen);
}

$threads = array(
    thread(1, 3),  // buyer wrote yesterday, not seen           -> waiting
    thread(2, 4),  // seller answered yesterday                 -> settled
    thread(3, 2),  // buyer wrote yesterday, already seen       -> settled
    thread(4, 5),  // buyer wrote last, unseen, 3 months ago    -> settled (old backlog)
    thread(5, 1),  // nothing on the messages page              -> settled
);

$messages = array(
    message(1, 'customer', '2026-11-12 10:00:00', 0),
    message(2, 'seller', '2026-11-12 09:00:00', 0),
    message(3, 'customer', '2026-11-12 08:00:00', 1),
    message(2, 'customer', '2026-11-11 09:00:00', 1),
    message(4, 'customer', '2026-08-01 09:00:00', 0),
);

$last = SellerThreadState::lastMessages($messages);

echo "last message\n";
check('the read receipt is kept', $last[1]['seen'] === 0 && $last[3]['seen'] === 1);
check('a row without the field reads as unknown', array_key_exists('seen', SellerThreadState::lastMessages(array(
    array('id_community_thread' => 9, 'from' => 'customer', 'date_add' => '2026-11-12 10:00:00'),
))[9]));
check('buyer last and unseen needs attention', SellerThreadState::needsAttention($last[1]) === true);
check('seller last does not', SellerThreadState::needsAttention($last[2]) === false);
check('buyer last but seen does not', SellerThreadState::needsAttention($last[3]) === false);
check('unknown last message does not', SellerThreadState::needsAttention(null) === false);
check('no seen field counts as unseen', SellerThreadState::needsAttention(array('from' => 'customer', 'date' => 'x', 'seen' => null)) === true);

echo "the 2.3.0 backlog\n";
// What 2.3.0 left behind: everything unread, fingerprints of the old whole-row kind, one pin.
foreach ($threads as $t) {
    Db::$state[$t['id_community_thread']] = array('fingerprint' => md5('old' . $t['id_community_thread']), 'is_read' => 0, 'is_pinned' => 0);
}
Db::$state[5]['is_pinned'] = 1;
// A conversation the marketplace no longer returns.
Db::$state[99] = array('fingerprint' => md5('gone'), 'is_read' => 0, 'is_pinned' => 0);

check('starts as the old model', SellerThreadState::isCurrentModel() === false);
$result = SellerThreadState::sync($threads, array(), $now);
check('no last-message data: nothing is decided', $result['skipped'] === true && count(unread()) === 6);

$result = SellerThreadState::sync($threads, $last, $now);
check('the first sync rebuilds', $result['rebuilt'] === true && SellerThreadState::isCurrentModel() === true);
check('only the unseen recent buyer message is waiting', unread() === array(1));
check('the pin survived the rebuild', Db::$state[5]['is_pinned'] === 1 && Db::$state[1]['is_pinned'] === 0);
check('a conversation the marketplace dropped is removed', !isset(Db::$state[99]));
$counts = SellerThreadState::counts();
check('counts: 1 waiting of 5', $counts['unread'] === 1 && $counts['total'] === 5);

echo "steady state\n";
Db::$writes = 0;
$result = SellerThreadState::sync($threads, $last, $now);
check('an unchanged sync writes nothing', Db::$writes === 0 && $result['rebuilt'] === false);

// The thread row changes without any message: product renamed, token rotated.
$renamed = $threads;
$renamed[1] = thread(2, 4, 'Module, new name');
$renamed[1]['token'] = 'rotated';
SellerThreadState::sync($renamed, $last, $now);
check('a renamed product reopens nothing', unread() === array(1) && Db::$writes === 0);

// The seller answers thread 1 on the marketplace.
$threads[0] = thread(1, 4);
array_unshift($messages, message(1, 'seller', '2026-11-13 09:00:00', 0));
$last = SellerThreadState::lastMessages($messages);
SellerThreadState::sync($threads, $last, $now);
check('answering on the marketplace settles it here', unread() === array());

// The buyer of thread 2 writes again.
$threads[1] = thread(2, 5);
array_unshift($messages, message(2, 'customer', '2026-11-13 10:00:00', 0));
$last = SellerThreadState::lastMessages($messages);
SellerThreadState::sync($threads, $last, $now);
check('a new buyer message is waiting', unread() === array(2));

// The seller opens it on the marketplace without answering.
$messages[0]['seen'] = 1;
$last = SellerThreadState::lastMessages($messages);
SellerThreadState::sync($threads, $last, $now);
check('reading it on the marketplace settles it here', unread() === array());

// An old conversation gets a buyer message after months: it must wait, whatever its age.
$threads[3] = thread(4, 6);
array_unshift($messages, message(4, 'customer', '2026-11-13 11:00:00', 0));
$last = SellerThreadState::lastMessages($messages);
SellerThreadState::sync($threads, $last, $now);
check('a new message in an old conversation is waiting', unread() === array(4));

echo "merchant overrides\n";
SellerThreadState::setRead(null, true);
SellerThreadState::sync($threads, $last, $now);
check('"mark all as read" holds while nothing changes', unread() === array());

Db::$state[3]['is_read'] = 0; // marked as unread by hand
SellerThreadState::sync($threads, $last, $now);
check('"mark as unread" holds while nothing changes', unread() === array(3));

echo "new conversations\n";
$threads[] = thread(6, 1);
$threads[] = thread(7, 2);
array_unshift($messages, message(6, 'customer', '2026-11-13 11:30:00', 0));
array_unshift($messages, message(7, 'seller', '2026-11-13 11:40:00', 0));
$last = SellerThreadState::lastMessages($messages);
$result = SellerThreadState::sync($threads, $last, $now);
check('a new buyer conversation is waiting', in_array(6, unread(), true));
check('a conversation the seller opened is not', !in_array(7, unread(), true) && $result['added'] === 2);

echo "signature\n";
$a = SellerThreadState::signature(thread(1, 3), $last[6]);
check('ignores fields that are not about messages', $a === SellerThreadState::signature(thread(1, 3, 'Other name'), $last[6]));
check('moves with the message count', $a !== SellerThreadState::signature(thread(1, 4), $last[6]));
check('moves with the read receipt', $a !== SellerThreadState::signature(thread(1, 3), array('seen' => 1) + $last[6]));

echo "cached map from 2.3.0\n";
Configuration::$store['PRESTASHOPAPI_KEY'] = 'k';

class RecordedClient extends SellerApiClient
{
    public $calls = 0;
    public $response;

    protected function call($path, array $params, $multipart = false, $page = 1)
    {
        ++$this->calls;

        return $this->response;
    }
}

$client = new RecordedClient(true, '', '');
$client->response = array('success' => true, 'messages' => array('last_page' => 1, 'data' => array(
    message(1, 'customer', '2026-11-12 10:00:00', 1),
)));
$map = $client->getLastMessages(true);
check('a fresh map carries the read receipt', $map[1]['seen'] === 1);

// Overwrite the cached map with the 2.3.0 shape (no "seen"), still fresh.
foreach (Db::$cache as $key => $row) {
    if (substr($key, -14) === 'last_by_thread') {
        Db::$cache[$key]['payload'] = json_encode(array('map' => array(1 => array('date' => '2026-11-12 10:00:00', 'from' => 'customer'))));
    }
}

$calls = $client->calls;
$map = $client->getLastMessages();
check('a map without the read receipt is rebuilt, not trusted', isset($map[1]) && $map[1]['seen'] === 1);
check('rebuilt from the cached messages, without a new request', $client->calls === $calls);

echo "\n$checks checks, $failures failure(s)\n";
exit($failures ? 1 : 0);
