<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Read and pinned state for marketplace conversations, owned by the merchant.
 *
 * The marketplace exposes no read, answered or status field of its own, and no author on a
 * message, so "who owes a reply" cannot be derived from the API. This does not try. The
 * merchant says what is read; the module only says what has CHANGED since they said it.
 *
 * A conversation goes back to unread by itself whenever its fingerprint moves, and a new
 * buyer message necessarily moves it because nb_messages is part of the row. That is the one
 * inference here, and it rests on a field that is confirmed present rather than assumed.
 *
 * Kept in a table rather than a Configuration value: ps_configuration.value is TEXT, capped at
 * 64KB, and this account's 1843 conversations need roughly 83KB. It would have fitted right up
 * until it silently truncated.
 */
class SellerThreadState
{
    /** @var string Table name, without _DB_PREFIX_. */
    const TABLE = 'prestashopapi_thread';

    /** @var int Rows per INSERT. One statement for 1843 rows can exceed max_allowed_packet. */
    const CHUNK = 200;

    /* ---------------------------------------------------------------- *
     * Schema
     * ---------------------------------------------------------------- */

    public static function install()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE . '` (
                `id_thread` INT(11) UNSIGNED NOT NULL,
                `fingerprint` CHAR(32) NOT NULL,
                `is_read` TINYINT(1) NOT NULL DEFAULT 0,
                `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_thread`),
                KEY `is_read` (`is_read`),
                KEY `is_pinned` (`is_pinned`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );
    }

    public static function uninstall()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::TABLE . '`');
    }

    private static function table()
    {
        return '`' . _DB_PREFIX_ . self::TABLE . '`';
    }

    /* ---------------------------------------------------------------- *
     * Identity
     * ---------------------------------------------------------------- */

    /**
     * A conversation's id.
     *
     * The threads endpoint calls it id_community_thread, not id_thread. Both are accepted
     * because seller/threads/{id}/messages may well use the shorter name.
     *
     * @return int 0 when the row carries no id.
     */
    public static function threadId(array $thread)
    {
        foreach (array('id_community_thread', 'id_thread') as $field) {
            if (isset($thread[$field]) && (int) $thread[$field] > 0) {
                return (int) $thread[$field];
            }
        }

        return 0;
    }

    /**
     * Stable hash of a conversation's scalar fields.
     *
     * Whole-row rather than a chosen field: a new buyer message has to change something, and
     * nb_messages is the field that always moves. Hashing everything means we do not depend on
     * having guessed which field that is.
     */
    public static function fingerprint(array $thread)
    {
        $flat = array();

        foreach ($thread as $field => $value) {
            if (is_scalar($value) || $value === null) {
                $flat[$field] = (string) $value;
            }
        }

        // Sorted so a reordered response is not mistaken for a changed one.
        ksort($flat);

        return md5(json_encode($flat));
    }

    /* ---------------------------------------------------------------- *
     * Last message, ordering, display
     * ---------------------------------------------------------------- */

    /**
     * The latest message of each conversation, from the bulk seller/messages rows.
     *
     * A thread row carries only its OPENING date (date_add). The marketplace's own inbox sorts
     * by last message, so a buyer writing today in a thread opened in 2024 sits at the top
     * there and was buried two years down here. The messages endpoint has the missing date
     * and the author (from: "customer" | "seller").
     *
     * The endpoint answers newest first, so the first row seen for a conversation is its last
     * message. A later row only replaces it if it is strictly newer, which keeps the result
     * right even if the order ever changes.
     *
     * @return array id_community_thread => array{date: string, from: string}
     */
    public static function lastMessages(array $messages)
    {
        $last = array();

        foreach ($messages as $message) {
            if (!is_array($message) || !isset($message['id_community_thread'])) {
                continue;
            }

            $id = (int) $message['id_community_thread'];

            if ($id <= 0) {
                continue;
            }

            $date = isset($message['date_add']) ? (string) $message['date_add'] : '';

            if (isset($last[$id]) && strcmp($date, $last[$id]['date']) <= 0) {
                continue;
            }

            $last[$id] = array(
                'date' => $date,
                'from' => isset($message['from']) ? (string) $message['from'] : '',
            );
        }

        return $last;
    }

    /**
     * When a conversation last moved: its last message, or its opening date when the
     * message is not on the page of messages we read.
     *
     * @return string
     */
    public static function lastDate(array $thread, array $last)
    {
        $id = self::threadId($thread);
        $opened = isset($thread['date_add']) ? (string) $thread['date_add'] : '';

        if ($id && isset($last[$id]['date']) && $last[$id]['date'] !== '' && strcmp($last[$id]['date'], $opened) > 0) {
            return $last[$id]['date'];
        }

        return $opened;
    }

    /**
     * Whether the buyer wrote last, so the conversation is waiting on the seller.
     *
     * @return bool
     */
    public static function isAwaiting(array $thread, array $last)
    {
        $id = self::threadId($thread);

        return $id && isset($last[$id]['from']) && $last[$id]['from'] === 'customer';
    }

    /**
     * Sort key: pinned conversations first, then the most recent last message.
     *
     * A string so that a plain strcmp sorts on both keys at once.
     *
     * @return string
     */
    public static function rank($pinned, $date)
    {
        $time = $date !== '' ? strtotime($date) : false;

        if ($time === false || $time < 0) {
            $time = 0;
        }

        return ($pinned ? '0' : '1') . str_pad((string) (99999999999 - $time), 11, '0', STR_PAD_LEFT);
    }

    /**
     * usort() comparator for rows carrying rank and id. Equal ranks fall back to the newer id,
     * so the order is stable between page loads.
     *
     * @return int
     */
    public static function compareRank($a, $b)
    {
        $order = strcmp($a['rank'], $b['rank']);

        if ($order !== 0) {
            return $order;
        }

        return (int) $b['id'] - (int) $a['id'];
    }

    /**
     * Hides passwords buyers paste into a conversation, for display only.
     *
     * "password: hunter2", "Parola = x", "mot de passe : x" and the like become
     * "password: ••••••". Line by line: the value is whatever follows on the same line up to
     * the next space. The cached copy is left as it was; this only changes what is shown.
     *
     * @return string
     */
    public static function maskSecrets($text)
    {
        $text = (string) $text;
        $dot = str_repeat("\xE2\x80\xA2", 6);
        $words = "password|parola|mot de passe|passwort|contrase\xC3\xB1a|pwd";

        $masked = @preg_replace('/(' . $words . ')[^\S\r\n]*[:=][^\S\r\n]*\S+/iu', '$1: ' . $dot, $text);

        if ($masked === null) {
            // Not valid UTF-8: same pattern byte-wise, rather than showing the text unmasked.
            $masked = preg_replace('/(' . $words . ')[^\S\r\n]*[:=][^\S\r\n]*\S+/i', '$1: ' . $dot, $text);
        }

        return $masked === null ? $text : $masked;
    }

    /* ---------------------------------------------------------------- *
     * Sync
     * ---------------------------------------------------------------- */

    /**
     * Reconciles stored state against the conversations the marketplace just returned.
     *
     * Rules, in the merchant's words: everything starts unread; a conversation stays how they
     * left it until it changes; anything that changes goes back to unread.
     *
     * @return array{added: int, reopened: int, removed: int}
     */
    public static function sync(array $threads)
    {
        $existing = array();
        $rows = Db::getInstance()->executeS('SELECT `id_thread`, `fingerprint` FROM ' . self::table());

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $existing[(int) $row['id_thread']] = $row['fingerprint'];
            }
        }

        $now = date('Y-m-d H:i:s');
        $present = array();
        $insert = array();
        $reopened = 0;

        foreach ($threads as $thread) {
            if (!is_array($thread)) {
                continue;
            }

            $id = self::threadId($thread);

            if (!$id) {
                continue;
            }

            $present[$id] = true;
            $fingerprint = self::fingerprint($thread);

            if (!isset($existing[$id])) {
                // Never seen: starts unread, exactly like a new message would.
                $insert[] = '(' . $id . ', "' . pSQL($fingerprint) . '", 0, 0, "' . pSQL($now) . '")';
            } elseif ($existing[$id] !== $fingerprint) {
                // Changed since the merchant last looked. is_pinned is deliberately untouched:
                // pinning is their filing, not a read state.
                Db::getInstance()->execute(
                    'UPDATE ' . self::table() . '
                     SET `fingerprint` = "' . pSQL($fingerprint) . '", `is_read` = 0, `date_upd` = "' . pSQL($now) . '"
                     WHERE `id_thread` = ' . $id
                );
                ++$reopened;
            }
        }

        foreach (array_chunk($insert, self::CHUNK) as $chunk) {
            Db::getInstance()->execute(
                'INSERT IGNORE INTO ' . self::table() . '
                 (`id_thread`, `fingerprint`, `is_read`, `is_pinned`, `date_upd`)
                 VALUES ' . implode(',', $chunk)
            );
        }

        $removed = 0;

        // Conversations the marketplace stopped returning, so the table cannot grow for ever.
        if ($present) {
            Db::getInstance()->execute(
                'DELETE FROM ' . self::table() . ' WHERE `id_thread` NOT IN (' . implode(',', array_keys($present)) . ')'
            );
            $removed = (int) Db::getInstance()->Affected_Rows();
        }

        return array('added' => count($insert), 'reopened' => $reopened, 'removed' => $removed);
    }

    /* ---------------------------------------------------------------- *
     * Reading
     * ---------------------------------------------------------------- */

    /**
     * @return array id_thread => array{read: bool, pinned: bool}
     */
    public static function all()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_thread`, `is_read`, `is_pinned` FROM ' . self::table()
        );

        $state = array();

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $state[(int) $row['id_thread']] = array(
                    'read' => (bool) $row['is_read'],
                    'pinned' => (bool) $row['is_pinned'],
                );
            }
        }

        return $state;
    }

    /**
     * @return array{total: int, unread: int, pinned: int}
     */
    public static function counts()
    {
        $row = Db::getInstance()->getRow(
            'SELECT COUNT(*) AS `total`,
                    SUM(CASE WHEN `is_read` = 0 THEN 1 ELSE 0 END) AS `unread`,
                    SUM(CASE WHEN `is_pinned` = 1 THEN 1 ELSE 0 END) AS `pinned`
             FROM ' . self::table()
        );

        return array(
            'total' => $row ? (int) $row['total'] : 0,
            'unread' => $row ? (int) $row['unread'] : 0,
            'pinned' => $row ? (int) $row['pinned'] : 0,
        );
    }

    /* ---------------------------------------------------------------- *
     * Writing
     * ---------------------------------------------------------------- */

    /**
     * @param int|null $id_thread Null for every conversation.
     */
    public static function setRead($id_thread, $read)
    {
        $where = $id_thread === null ? '' : ' WHERE `id_thread` = ' . (int) $id_thread;

        return Db::getInstance()->execute(
            'UPDATE ' . self::table() . ' SET `is_read` = ' . ((int) (bool) $read) . $where
        );
    }

    public static function setPinned($id_thread, $pinned)
    {
        return Db::getInstance()->execute(
            'UPDATE ' . self::table() . '
             SET `is_pinned` = ' . ((int) (bool) $pinned) . '
             WHERE `id_thread` = ' . (int) $id_thread
        );
    }

    /**
     * Whether the table exists yet.
     *
     * The module is already installed on shops that predate this table, and install() only ever
     * runs once, so its absence has to be repairable rather than fatal.
     */
    public static function tableExists()
    {
        $found = Db::getInstance()->executeS(
            'SHOW TABLES LIKE "' . pSQL(_DB_PREFIX_ . self::TABLE) . '"',
            true,
            false
        );

        return is_array($found) && $found;
    }
}
