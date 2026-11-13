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
 * Which marketplace conversations are waiting for the merchant, plus their pins.
 *
 * A conversation is waiting (stored as is_read = 0) when the buyer wrote the last message
 * and the seller has not seen it yet. Both facts come from the bulk seller/messages rows:
 * "from" names the author and "seen" is the marketplace's own read receipt. So answering a
 * buyer, or simply opening the message on the marketplace, settles the conversation here
 * too, without the merchant touching this module.
 *
 * The merchant can still override either way (mark as read, mark as unread). An override
 * holds until the conversation's last message changes.
 *
 * Up to 2.3.0 the rule was "anything whose thread row changed goes back to unread". The
 * seller's own replies change the row (nb_messages), and so does renaming a product, so a
 * merchant who answered everything on the marketplace still collected hundreds of "waiting"
 * conversations here. STATE_MODEL marks the one-time rebuild that clears that backlog.
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

    /** @var string Configuration key holding the state model the table was built with. */
    const STATE_KEY = 'PRESTASHOPAPI_STATE_MODEL';

    /** @var int Current state model. 2 = waiting follows the last message's author and read receipt. */
    const STATE_MODEL = 2;

    /**
     * @var int A conversation seen for the first time (or during the one-time rebuild) only
     *          counts as waiting when its last buyer message is at most this many days old.
     */
    const FRESH_DAYS = 30;

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
     * Hash of the only things that decide whether a conversation is waiting: how many
     * messages it has, when the last one was written, by whom, and whether it was seen.
     *
     * Deliberately NOT the whole thread row, which is what 2.3.0 hashed: a product rename or
     * any other marketplace-side edit moved that hash and reopened conversations nobody had
     * written in.
     *
     * @param array|null $entry The conversation's lastMessages() entry, when known.
     *
     * @return string
     */
    public static function signature(array $thread, $entry = null)
    {
        return md5(implode('|', array(
            isset($thread['nb_messages']) ? (int) $thread['nb_messages'] : 0,
            is_array($entry) && isset($entry['date']) ? (string) $entry['date'] : '',
            is_array($entry) && isset($entry['from']) ? (string) $entry['from'] : '',
            is_array($entry) && isset($entry['seen']) ? (int) (bool) $entry['seen'] : '',
        )));
    }

    /**
     * Whether a last message leaves the conversation waiting for the seller: the buyer wrote
     * it and the seller has not seen it on the marketplace.
     *
     * A row without a "seen" field counts as not seen, so a change in the API can only make
     * the module say "waiting" too often, never hide a buyer.
     *
     * @param array|null $entry
     *
     * @return bool
     */
    public static function needsAttention($entry)
    {
        return is_array($entry)
            && isset($entry['from']) && $entry['from'] === 'customer'
            && empty($entry['seen']);
    }

    /**
     * @param array|null $entry
     * @param int|null $now Unix time, for tests.
     *
     * @return bool Whether the last message is at most FRESH_DAYS old.
     */
    public static function isFresh($entry, $now = null)
    {
        $time = is_array($entry) && !empty($entry['date']) ? strtotime($entry['date']) : false;

        if ($time === false) {
            return false;
        }

        return $time >= ($now === null ? time() : (int) $now) - self::FRESH_DAYS * 86400;
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
                // The marketplace's read receipt: 1 once the other side has opened it.
                'seen' => isset($message['seen']) ? (int) (bool) $message['seen'] : null,
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
     * Decides what has to change in the table. Pure, so it can be tested without a database.
     *
     * - A conversation not stored yet starts waiting only if its last buyer message is unseen
     *   and recent (FRESH_DAYS); otherwise it starts settled. A first install on an account
     *   with years of history must not open with hundreds of "waiting" conversations.
     * - A stored one is touched only when its signature moved: waiting if the buyer wrote
     *   last and it is unseen, settled otherwise (the seller replied, or read it on the
     *   marketplace). When the last message is not known the read state is left alone.
     * - $rebuild re-decides every conversation like a new one, keeping the pins.
     *
     * @param array $threads Thread rows from the API.
     * @param array $last lastMessages() map.
     * @param array $existing id => array{fingerprint: string, pinned: bool}
     * @param bool $rebuild
     * @param int|null $now
     *
     * @return array{insert: array, update: array, present: array}
     *               insert: id => array{fingerprint, read, pinned}
     *               update: id => array{fingerprint, read (bool, or null = leave as it is)}
     */
    public static function plan(array $threads, array $last, array $existing, $rebuild = false, $now = null)
    {
        $insert = array();
        $update = array();
        $present = array();

        foreach ($threads as $thread) {
            if (!is_array($thread)) {
                continue;
            }

            $id = self::threadId($thread);

            if (!$id) {
                continue;
            }

            $present[$id] = true;
            $entry = isset($last[$id]) && is_array($last[$id]) ? $last[$id] : null;
            $signature = self::signature($thread, $entry);

            if ($rebuild || !isset($existing[$id])) {
                $insert[$id] = array(
                    'fingerprint' => $signature,
                    'read' => !(self::needsAttention($entry) && self::isFresh($entry, $now)),
                    'pinned' => isset($existing[$id]) && !empty($existing[$id]['pinned']),
                );
            } elseif ($existing[$id]['fingerprint'] !== $signature) {
                $update[$id] = array(
                    'fingerprint' => $signature,
                    'read' => $entry === null ? null : !self::needsAttention($entry),
                );
            }
        }

        return array('insert' => $insert, 'update' => $update, 'present' => $present);
    }

    /**
     * Reconciles stored state against the conversations and last messages just read.
     *
     * Does nothing without last-message data: with no author there is nothing to decide on,
     * and guessing is what filled the old "unread" count. The first run after an update from
     * 2.3.0 or older rebuilds every row once (see STATE_MODEL).
     *
     * @return array{added: int, changed: int, removed: int, rebuilt: bool, skipped: bool}
     */
    public static function sync(array $threads, array $last = array(), $now = null)
    {
        $result = array('added' => 0, 'changed' => 0, 'removed' => 0, 'rebuilt' => false, 'skipped' => true);

        if (!$threads || !$last) {
            return $result;
        }

        $db = Db::getInstance();
        $rebuild = !self::isCurrentModel();
        $existing = array();
        $rows = $db->executeS('SELECT `id_thread`, `fingerprint`, `is_pinned` FROM ' . self::table());

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $existing[(int) $row['id_thread']] = array(
                    'fingerprint' => $row['fingerprint'],
                    'pinned' => (bool) $row['is_pinned'],
                );
            }
        }

        $plan = self::plan($threads, $last, $existing, $rebuild, $now);

        if (!$plan['present']) {
            return $result;
        }

        $stamp = date('Y-m-d H:i:s');
        $values = array();

        foreach ($plan['insert'] as $id => $row) {
            $values[] = '(' . (int) $id . ', "' . pSQL($row['fingerprint']) . '", ' . (int) $row['read']
                . ', ' . (int) $row['pinned'] . ', "' . pSQL($stamp) . '")';
        }

        // REPLACE, so the one-time rebuild overwrites the rows it re-decides.
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $db->execute(
                'REPLACE INTO ' . self::table() . '
                 (`id_thread`, `fingerprint`, `is_read`, `is_pinned`, `date_upd`)
                 VALUES ' . implode(',', $chunk)
            );
        }

        foreach ($plan['update'] as $id => $row) {
            // is_pinned is deliberately untouched: pinning is the merchant's filing.
            $db->execute(
                'UPDATE ' . self::table() . '
                 SET `fingerprint` = "' . pSQL($row['fingerprint']) . '"'
                . ($row['read'] === null ? '' : ', `is_read` = ' . (int) $row['read'])
                . ', `date_upd` = "' . pSQL($stamp) . '"
                 WHERE `id_thread` = ' . (int) $id
            );
        }

        // Conversations the marketplace stopped returning, so the table cannot grow for ever.
        $gone = array_diff(array_keys($existing), array_keys($plan['present']));

        foreach (array_chunk($gone, self::CHUNK) as $chunk) {
            $db->execute(
                'DELETE FROM ' . self::table() . ' WHERE `id_thread` IN (' . implode(',', array_map('intval', $chunk)) . ')'
            );
        }

        if ($rebuild) {
            SellerApiClient::setAccountValue(self::STATE_KEY, self::STATE_MODEL);
        }

        return array(
            'added' => $rebuild ? 0 : count($plan['insert']),
            'changed' => count($plan['update']),
            'removed' => count($gone),
            'rebuilt' => $rebuild,
            'skipped' => false,
        );
    }

    /**
     * @return bool Whether the table was already built with the current state model.
     */
    public static function isCurrentModel()
    {
        return (int) SellerApiClient::getAccountValue(self::STATE_KEY) >= self::STATE_MODEL;
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
