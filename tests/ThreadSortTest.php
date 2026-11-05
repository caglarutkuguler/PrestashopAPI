<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * Guards the 2.2.0 conversation order: last message first, not opening date.
 *
 * Runs without PrestaShop: `php tests/ThreadSortTest.php`. Configuration, Tools
 * and Db are stubbed, and the API client's network call is replaced by a test
 * double returning the recorded shape of seller/messages.
 *
 * What it is here to catch: a conversation opened long ago with a message
 * today must sort above one opened recently whose last message is older; the
 * bulk messages call must read page 1 only and cache no message bodies; the
 * "awaiting reply" flag must follow the author of the last message; and
 * passwords in a message must be masked for display.
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

class Db
{
    public static $rows = array();
    private static $instance;

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getRow($sql)
    {
        if (preg_match('/`cache_key` = "([^"]+)"/', $sql, $m) && isset(self::$rows[$m[1]])) {
            return self::$rows[$m[1]];
        }

        return false;
    }

    public function execute($sql)
    {
        if (preg_match('/VALUES \("([^"]+)",\s*"(.*)",\s*"([^"]*)",\s*"([^"]*)"\)\s*$/s', $sql, $m)) {
            self::$rows[$m[1]] = array('payload' => stripslashes($m[2]), 'date_add' => $m[3], 'expires' => $m[4]);
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

/** Stands in for the network: answers seller/messages like the live account did. */
class RecordedClient extends SellerApiClient
{
    public $calls = array();
    public $response;

    protected function call($path, array $params, $multipart = false, $page = 1)
    {
        $this->calls[] = $path . '#' . $page;

        return $this->response;
    }
}

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

/* ------------------------------------------------------------------ *
 * Fixture: the live shape of seller/messages (newest first) and of
 * seller/threads rows (date_add = opening date).
 * ------------------------------------------------------------------ */

$messages = array(
    // Thread 100: opened in 2024, buyer wrote today.
    array('id_community_message' => 9, 'id_community_thread' => 100, 'from' => 'customer',
        'message' => '<p>Still spam</p>', 'date_add' => '2026-11-05 14:20:41', 'seen' => 0,
        'marked_as_seen' => '0000-00-00 00:00:00'),
    // Thread 200: opened last month, seller answered two weeks ago.
    array('id_community_message' => 8, 'id_community_thread' => 200, 'from' => 'seller',
        'message' => '<p>Done</p>', 'date_add' => '2026-10-20 09:00:00', 'seen' => 1,
        'marked_as_seen' => '2026-10-20 10:00:00'),
    array('id_community_message' => 7, 'id_community_thread' => 200, 'from' => 'customer',
        'message' => '<p>Help</p>', 'date_add' => '2026-10-15 09:00:00', 'seen' => 1,
        'marked_as_seen' => '2026-10-15 10:00:00'),
    array('id_community_message' => 6, 'id_community_thread' => 100, 'from' => 'seller',
        'message' => '<p>Old answer</p>', 'date_add' => '2024-10-26 09:00:00', 'seen' => 1,
        'marked_as_seen' => '2024-10-26 10:00:00'),
);

$threads = array(
    array('id_community_thread' => 200, 'topic' => 'New thread', 'date_add' => '2026-10-15 09:00:00', 'nb_messages' => 2),
    array('id_community_thread' => 100, 'topic' => 'Spam in Forms', 'date_add' => '2024-10-25 08:00:00', 'nb_messages' => 2),
    // Thread 300: nothing on page 1 of messages, falls back to its opening date.
    array('id_community_thread' => 300, 'topic' => 'Ancient', 'date_add' => '2023-01-01 00:00:00', 'nb_messages' => 1),
);

echo "lastMessages()\n";
$last = SellerThreadState::lastMessages($messages);
check('one entry per conversation', count($last) === 2);
check('first (newest) row wins for thread 100', $last[100]['date'] === '2026-11-05 14:20:41' && $last[100]['from'] === 'customer');
check('first (newest) row wins for thread 200', $last[200]['date'] === '2026-10-20 09:00:00' && $last[200]['from'] === 'seller');
$reversed = SellerThreadState::lastMessages(array_reverse($messages));
check('still the newest when the order is reversed', $reversed[100]['date'] === '2026-11-05 14:20:41');

echo "ordering\n";
$rows = array();

foreach ($threads as $thread) {
    $date = SellerThreadState::lastDate($thread, $last);
    $rows[] = array(
        'id' => SellerThreadState::threadId($thread),
        'rank' => SellerThreadState::rank(false, $date),
        'date' => $date,
        'awaiting' => SellerThreadState::isAwaiting($thread, $last),
    );
}

usort($rows, array('SellerThreadState', 'compareRank'));
$order = array();

foreach ($rows as $row) {
    $order[] = $row['id'];
}

check('opened old / last message new ranks before opened new / last message old', $order === array(100, 200, 300));
check('a thread with no message on page 1 falls back to its opening date', $rows[2]['date'] === '2023-01-01 00:00:00');
check('pinned still comes first', strcmp(SellerThreadState::rank(true, '2020-01-01 00:00:00'), SellerThreadState::rank(false, '2026-11-05 00:00:00')) < 0);

echo "awaiting reply\n";
check('buyer wrote last: awaiting', $rows[0]['awaiting'] === true);
check('seller wrote last: not awaiting', $rows[1]['awaiting'] === false);
check('no last message known: not awaiting', $rows[2]['awaiting'] === false);

echo "bulk messages call\n";
Configuration::$store['PRESTASHOPAPI_KEY'] = 'test-key';
$client = new RecordedClient(true, '', '');
$client->response = array('success' => true, 'messages' => array(
    'total' => 21678, 'per_page' => 5000, 'current_page' => 1, 'last_page' => 5,
    'data' => $messages,
));
$map = $client->getLastMessages(true);
check('only page 1 of seller/messages is requested', $client->calls === array('seller/messages#1'));
check('map built from the response', isset($map[100]) && $map[100]['from'] === 'customer');
$cached = $client->getAllMessages();
check('cached rows keep their fields', isset($cached[0]['from'], $cached[0]['date_add'], $cached[0]['id_community_thread']));
check('cached rows carry no message body', !isset($cached[0]['message']));
$client->calls = array();
$again = $client->getLastMessages();
check('map served from cache without a call', $client->calls === array() && $again == $map);

echo "credential masking\n";
$dot = str_repeat("\xE2\x80\xA2", 6);
check('password: value', SellerThreadState::maskSecrets("Login admin\npassword: S3cret!x") === "Login admin\npassword: $dot");
check('case and = separator', SellerThreadState::maskSecrets('Password=abc123 thanks') === "Password: $dot thanks");
check('Turkish parola', SellerThreadState::maskSecrets('parola : xyz') === "parola: $dot");
check('French mot de passe', SellerThreadState::maskSecrets('Mot de passe: zz') === "Mot de passe: $dot");
check('Spanish contraseña', SellerThreadState::maskSecrets("contrase\xC3\xB1a: qq") === "contrase\xC3\xB1a: $dot");
check('one line only', SellerThreadState::maskSecrets("password:\nnext line") === "password:\nnext line");
check('text without credentials is untouched', SellerThreadState::maskSecrets('I forgot my password, help') === 'I forgot my password, help');

echo "\n$checks checks, $failures failed\n";
exit($failures ? 1 : 0);
