<?php
/**
 * 2019-2026 MEG Venture
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 *  @author    MEG Venture
 *  @copyright 2019-2026 MEG Venture
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
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
