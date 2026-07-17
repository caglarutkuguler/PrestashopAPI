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
 * Talks to the PrestaShop Addons Seller API and caches every response in the database.
 *
 * Design notes:
 *  - Nothing here ever throws. Callers get false plus getLastError(), because this data
 *    is decoration: a marketplace outage must never take a shop's page down with it.
 *  - Responses are cached in a table rather than on disk so that hosts with a read-only
 *    modules/ directory, and shops that routinely flush var/cache, keep working.
 *  - When a refresh fails but expired data is still cached, the stale copy is returned and
 *    isStale() reports true. Yesterday's sales figure beats an error box.
 *  - The front office constructs this with $allowRefresh = false, so a customer's page view
 *    can never trigger an outbound HTTP call. See the class docblock on PrestashopAPI.
 */
class SellerApiClient
{
    /** @var string Endpoint root. Trailing slash is required, paths are appended raw. */
    const API_URL = 'https://api.addons.prestashop.com/request/';

    /** @var string Cache table, without _DB_PREFIX_. */
    const CACHE_TABLE = 'prestashopapi_cache';

    /** @var int Seconds to wait for the whole request before giving up. */
    const TIMEOUT = 25;

    /** @var int Seconds to wait for the connection alone. */
    const CONNECT_TIMEOUT = 10;

    /** @var int Rows requested per collection call. v1 asked for 10000 on every page view. */
    const PAGE_LIMIT = 5000;

    /** @var int Safety stop when walking a paginated endpoint. 10 x PAGE_LIMIT rows. */
    const MAX_PAGES = 10;

    /**
     * @var array Wrapper keys tried after an endpoint's own, in order.
     *
     * Confirmed shape of seller/threads (live account, 2026-07-17):
     *
     *   {"success":true,
     *    "threads":{"total":1843,"per_page":5000,"current_page":1,"last_page":1,
     *               "next_page_url":null,"prev_page_url":null,"from":1,"to":1843,
     *               "data":[ ...the rows... ]}}
     *
     * So the Laravel paginator sits INSIDE the named wrapper, and the rows are one level
     * below that again under "data". seller/products and seller/orders put their rows
     * directly under "products" / "sales" with no paginator. Both are handled: find the
     * wrapper, then take its "data" if it has one, else treat it as the list itself.
     */
    const LIST_KEYS = array('data');

    /** @var int Bytes of the response body kept in an error message for diagnostics. */
    const ERROR_SNIPPET = 300;

    /** @var string */
    private $api_key;

    /** @var string YYYY-MM-DD or empty. */
    private $date_from;

    /** @var string YYYY-MM-DD or empty. */
    private $date_to;

    /** @var int Cache lifetime in seconds. */
    private $ttl;

    /** @var bool Whether this instance may perform outbound HTTP calls. */
    private $allow_refresh;

    /** @var string */
    private $last_error = '';

    /** @var bool Whether the value last returned came from an expired cache entry. */
    private $stale = false;

    public function __construct($allow_refresh = true, $date_from = null, $date_to = null)
    {
        $this->allow_refresh = (bool) $allow_refresh;
        $this->api_key = trim((string) Configuration::get('PRESTASHOPAPI_KEY'));
        $this->date_from = $date_from === null ? (string) Configuration::get('PRESTASHOPAPI_DATE_FROM') : (string) $date_from;
        $this->date_to = $date_to === null ? (string) Configuration::get('PRESTASHOPAPI_DATE_TO') : (string) $date_to;

        $ttl = (int) Configuration::get('PRESTASHOPAPI_CACHE_TTL');
        $this->ttl = $ttl > 0 ? $ttl * 60 : 3600;
    }

    /* ---------------------------------------------------------------- *
     * Environment
     * ---------------------------------------------------------------- */

    /**
     * v1 threw an uncaught Exception from its constructor when cURL was missing, which
     * white-screened the configuration page instead of explaining the problem.
     */
    public static function isCurlAvailable()
    {
        return function_exists('curl_init') && function_exists('curl_exec');
    }

    public function hasKey()
    {
        return $this->api_key !== '';
    }

    public function getLastError()
    {
        return $this->last_error;
    }

    public function isStale()
    {
        return $this->stale;
    }

    /* ---------------------------------------------------------------- *
     * Endpoints
     * ---------------------------------------------------------------- */

    /**
     * @return array|false List of products, or false on failure.
     */
    public function getProducts($force = false)
    {
        return $this->fetchList('seller/products', array(), 'products', 'products', $force);
    }

    /**
     * @return array|false List of sales lines, or false on failure.
     */
    public function getOrders($force = false)
    {
        return $this->fetchList('seller/orders', $this->dateParams(), 'orders', 'sales', $force);
    }

    /**
     * @return array|false Support threads, or false on failure.
     */
    public function getThreads($force = false)
    {
        return $this->fetchList('seller/threads', array(), 'threads', array('threads', 'thread'), $force);
    }

    /**
     * @return array|false Messages inside one thread, or false on failure.
     */
    public function getMessages($id_thread, $force = false)
    {
        $id_thread = (int) $id_thread;

        return $this->fetchList(
            'seller/threads/' . $id_thread . '/messages',
            array(),
            'messages_' . $id_thread,
            array('messages', 'message'),
            $force
        );
    }

    /**
     * Every message across every conversation, in one call.
     *
     * This is what makes "awaiting a reply" affordable. Deciding it per conversation means
     * knowing who wrote last, and asking seller/threads/{id}/messages for each would be 1843
     * requests on this account. One call for the lot is the difference between a feature and
     * a denial-of-service against your own API key.
     *
     * @return array|false
     */
    public function getAllMessages($force = false)
    {
        return $this->fetchList('seller/messages', array(), 'allmessages', array('messages', 'message'), $force);
    }

    /**
     * @return array|false Invoices, or false on failure.
     */
    public function getInvoices($force = false)
    {
        return $this->fetchList('seller/invoices', $this->dateParams(), 'invoices', array('invoices', 'invoice'), $force);
    }

    /**
     * @return array|false Withdrawal / bank details, or false on failure.
     */
    public function getBank($force = false)
    {
        return $this->fetchList('seller/bank', array(), 'bank', array('bank', 'banks'), $force);
    }

    /**
     * Posts a reply into a support thread.
     *
     * v1 signed this call with self::$api_key, a private static that was never assigned, so
     * every reply was sent with an empty key. It also ran the message body through a
     * hand-rolled SQL escaper before putting it in an HTTP body, which corrupted apostrophes.
     *
     * @return bool
     */
    public function sendMessage($id_thread, $message, $file_path = null, $file_name = null)
    {
        $message = trim((string) $message);

        if ($message === '') {
            $this->last_error = 'Empty message.';

            return false;
        }

        $params = array('message' => $message);

        if ($file_path !== null && is_file($file_path)) {
            if (class_exists('CURLFile')) {
                // The third argument is the filename the API sees. Without it the upload is
                // named after the PHP temp file, so the buyer would receive "phpA1B2.tmp".
                $params['attachment'] = new CURLFile($file_path, self::detectMime($file_path), (string) $file_name);
            } else {
                // PHP 5.4 and below. CURLOPT_SAFE_UPLOAD defaults to true from 5.6 on, so this
                // branch only ever runs where the @ syntax is still honoured.
                $params['attachment'] = '@' . $file_path . ';filename=' . $file_name;
            }
        }

        $response = $this->call('seller/threads/' . (int) $id_thread . '/messages/add', $params, isset($params['attachment']));

        if ($response === false) {
            return false;
        }

        // The reply is now part of the thread, so the cached copy is wrong.
        self::purgeCache('messages_' . (int) $id_thread);
        self::purgeCache('threads');

        return true;
    }

    /**
     * Reads the real MIME type from the file's bytes rather than trusting whatever the
     * browser declared in the upload.
     *
     * @return string
     */
    private static function detectMime($path)
    {
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo) {
                $mime = @finfo_file($finfo, $path);
                @finfo_close($finfo);

                if ($mime) {
                    return $mime;
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);

            if ($mime) {
                return $mime;
            }
        }

        return 'application/octet-stream';
    }

    /**
     * Round-trips the smallest possible call so the merchant can tell a bad key apart from
     * a blocked outbound connection without reading a log.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection()
    {
        if (!self::isCurlAvailable()) {
            return array(
                'success' => false,
                'message' => 'The PHP cURL extension is not enabled on this server. Ask your host to enable it.',
            );
        }

        if (!$this->hasKey()) {
            return array('success' => false, 'message' => 'No API key saved yet.');
        }

        $response = $this->call('seller/products', array());

        if ($response === false) {
            return array('success' => false, 'message' => $this->last_error);
        }

        $count = count($this->extract($response, 'products'));

        return array(
            'success' => true,
            'message' => sprintf('Connected. Your seller account returned %d product(s).', $count),
        );
    }

    /* ---------------------------------------------------------------- *
     * Transport
     * ---------------------------------------------------------------- */

    /**
     * Returns an endpoint's rows: from cache when fresh, otherwise downloaded, paginated and
     * cached.
     *
     * Deliberately returns the ROWS rather than the raw response. The wrapper and paginator
     * are transport details; leaving them for each caller to unpick is what let the module
     * read the paginator as if it were a conversation.
     *
     * @param array|string $keys Wrapper key(s) this endpoint uses.
     *
     * @return array|false
     */
    private function fetchList($path, array $params, $cache_key, $keys, $force = false)
    {
        $this->stale = false;
        $entry = $this->readCache($cache_key);
        $cached = $entry !== false && isset($entry['payload']['rows']) ? $entry['payload']['rows'] : null;

        if (!$force && $cached !== null && $entry['fresh']) {
            return $cached;
        }

        if (!$this->allow_refresh) {
            // Front office: serve whatever we have and never call out.
            if ($cached !== null) {
                $this->stale = true;

                return $cached;
            }

            $this->last_error = 'No cached data available.';

            return false;
        }

        $first = $this->call($path, $params, false, 1);

        if ($first === false) {
            if ($cached !== null) {
                // Expired, but better than nothing while the marketplace is unreachable.
                $this->stale = true;

                return $cached;
            }

            return false;
        }

        $rows = $this->extract($first, $keys);
        $pages = min($this->pageCount($first, $keys), self::MAX_PAGES);

        // Reading page 1 only would silently truncate at PAGE_LIMIT rows: not an error, just
        // quietly wrong totals.
        for ($page = 2; $page <= $pages; ++$page) {
            $next = $this->call($path, $params, false, $page);

            if ($next === false) {
                break;
            }

            $more = $this->extract($next, $keys);

            if (!$more) {
                break;
            }

            $rows = array_merge($rows, $more);
        }

        $this->writeCache($cache_key, array(
            'rows' => $rows,
            // Kept for the diagnostics panel, so an unexpected shape can be seen rather than
            // deduced.
            'envelope' => $this->describeEnvelope($first),
        ));

        return $rows;
    }

    /**
     * How many pages the endpoint says it has.
     */
    private function pageCount(array $response, $keys)
    {
        $container = $this->container($response, $keys);

        if ($container !== null && isset($container['last_page'])) {
            return max(1, (int) $container['last_page']);
        }

        return isset($response['last_page']) ? max(1, (int) $response['last_page']) : 1;
    }

    /**
     * Performs one API call.
     *
     * Protected rather than private so a test double can stand in for the network and the
     * whole fetch/paginate/extract path can be exercised against a recorded response.
     *
     * @return array|false Decoded JSON, or false with last_error set.
     */
    protected function call($path, array $params, $multipart = false, $page = 1)
    {
        $this->last_error = '';

        if (!self::isCurlAvailable()) {
            $this->last_error = 'The PHP cURL extension is not available on this server.';

            return false;
        }

        if (!$this->hasKey()) {
            $this->last_error = 'No API key configured.';

            return false;
        }

        $params['api_key'] = $this->api_key;

        $ch = curl_init();

        if ($ch === false) {
            $this->last_error = 'Could not initialise cURL.';

            return false;
        }

        curl_setopt($ch, CURLOPT_URL, self::API_URL . $path . '?' . http_build_query(array(
            'limit' => self::PAGE_LIMIT,
            'sort' => 'desc',
            'page' => max(1, (int) $page),
        )));
        curl_setopt($ch, CURLOPT_POST, true);
        // A multipart body must stay an array; anything else is urlencoded so that cURL does
        // not silently reinterpret a leading "@" in a message as a file upload.
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $params : http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PrestaShop Seller Dashboard/' . PrestashopAPI::MODULE_VERSION);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            $this->last_error = sprintf('Could not reach the Addons API (cURL error %d: %s).', $errno, $error);

            return false;
        }

        if ($status !== 200) {
            $this->last_error = sprintf(
                'The Addons API answered with HTTP %d. %s',
                $status,
                $status === 403 || $status === 401
                    ? 'Your API key looks wrong or has been revoked.'
                    : 'Response: ' . Tools::substr(strip_tags($body), 0, self::ERROR_SNIPPET)
            );

            return false;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            // v1 fed this straight into foreach() and fataled. The usual cause is an HTML
            // error page or a hosting captcha, so show the merchant what actually arrived.
            $this->last_error = 'The Addons API returned a response that is not valid JSON: '
                . Tools::substr(trim(strip_tags($body)), 0, self::ERROR_SNIPPET);

            return false;
        }

        if (isset($decoded['error']) && $decoded['error']) {
            $this->last_error = is_scalar($decoded['error'])
                ? 'The Addons API rejected the request: ' . (string) $decoded['error']
                : 'The Addons API rejected the request.';

            return false;
        }

        return $decoded;
    }

    /**
     * The API wraps collections under a key that differs per endpoint, and returns a bare
     * associative row when there is exactly one result. Normalise both into a plain list.
     *
     * @param array|string $keys Candidate wrapper keys, first match wins.
     *
     * @return array
     */
    private function extract(array $response, $keys)
    {
        $container = $this->container($response, $keys);

        if ($container === null) {
            return array();
        }

        // A paginator: the rows are under "data". This is seller/threads.
        if (isset($container['data']) && is_array($container['data'])) {
            return array_values($container['data']);
        }

        // The wrapper is the list itself. This is seller/products and seller/orders.
        if ($container === array() || isset($container[0])) {
            return array_values($container);
        }

        // A map that is neither. Previously a guess here wrapped it as a single row, which
        // turned an unrecognised response into one phantom record that then failed silently
        // downstream. An empty list is honest: the caller reports "N items, none usable".
        return array();
    }

    /**
     * The value holding the rows, or the paginator holding them.
     *
     * @return array|null
     */
    private function container(array $response, $keys)
    {
        // The endpoint's own key first, so a named wrapper always wins over a stray "data".
        foreach (array_merge((array) $keys, self::LIST_KEYS) as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $response[$key];
            }
        }

        // An endpoint that paginates at the top level, with no named wrapper at all.
        return isset($response['data']) && is_array($response['data']) ? $response : null;
    }

    private function dateParams()
    {
        $params = array();

        if ($this->date_from !== '') {
            $params['date_from'] = $this->date_from;
        }

        if ($this->date_to !== '') {
            $params['date_to'] = $this->date_to;
        }

        return $params;
    }

    /* ---------------------------------------------------------------- *
     * Cache
     * ---------------------------------------------------------------- */

    /**
     * Cache entries are namespaced by key and by the date window, so switching the reporting
     * period cannot show the previous period's numbers.
     */
    private function cacheId($key)
    {
        return Tools::substr(md5($key . '|' . $this->date_from . '|' . $this->date_to . '|' . $this->api_key), 0, 32)
            . '_' . Tools::substr(preg_replace('/[^a-z0-9_]/', '', Tools::strtolower($key)), 0, 24);
    }

    /**
     * @return array{payload: array, fresh: bool, date_add: string}|false
     */
    private function readCache($key)
    {
        $row = Db::getInstance()->getRow(
            'SELECT `payload`, `expires`, `date_add` FROM `' . _DB_PREFIX_ . self::CACHE_TABLE . '`
             WHERE `cache_key` = "' . pSQL($this->cacheId($key)) . '"'
        );

        if (!$row) {
            return false;
        }

        $payload = json_decode($row['payload'], true);

        if (!is_array($payload)) {
            return false;
        }

        return array(
            'payload' => $payload,
            'fresh' => strtotime($row['expires']) > time(),
            'date_add' => $row['date_add'],
        );
    }

    private function writeCache($key, array $payload)
    {
        $now = date('Y-m-d H:i:s');

        // pSQL()'s second argument is $html_ok. Without it, Db::escape() runs
        // strip_tags(nl2br($string)) over the value: a JSON payload containing a "<" anywhere
        // (a buyer writing "price < 10" is enough) is truncated from that character to the end
        // of the string, so what gets stored will not decode and the cache never hits again.
        // Escaping is all a serialised blob needs; there is no markup here to sanitise.
        Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . self::CACHE_TABLE . '` (`cache_key`, `payload`, `date_add`, `expires`)
             VALUES ("' . pSQL($this->cacheId($key)) . '",
                     "' . pSQL(json_encode($payload), true) . '",
                     "' . pSQL($now) . '",
                     "' . pSQL(date('Y-m-d H:i:s', time() + $this->ttl)) . '")'
        );

        Configuration::updateValue('PRESTASHOPAPI_LAST_SYNC', $now);
    }

    /**
     * @param string|null $key Suffix to purge, or null for everything.
     */
    public static function purgeCache($key = null)
    {
        $where = '';

        if ($key !== null) {
            $suffix = Tools::substr(preg_replace('/[^a-z0-9_]/', '', Tools::strtolower($key)), 0, 24);
            $where = ' WHERE `cache_key` LIKE "%\\_' . pSQL($suffix) . '"';
        }

        return Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . self::CACHE_TABLE . '`' . $where);
    }

    /**
     * The top-level shape of whatever is cached under $key: each key, its type, and for a list
     * how many rows it holds.
     *
     * This is the view that matters when an endpoint "returns nothing": it shows the envelope
     * the rows are wrapped in. Had it existed sooner it would have shown {"total":1843,
     * "data":"array(1843 rows)"} and saved several rounds of guessing at the wrapper name.
     * Deliberately not the rows themselves, so it stays free of buyer details.
     *
     * @return array|string
     */
    public function getLastRaw($key)
    {
        $entry = $this->readCache($key);

        if ($entry === false || !isset($entry['payload']['envelope'])) {
            return '// nothing is cached for this endpoint: the request failed, or was never made';
        }

        $shape = $entry['payload']['envelope'];
        $shape['__rows_extracted'] = isset($entry['payload']['rows']) ? count($entry['payload']['rows']) : 0;
        $shape['__cached_at'] = $entry['date_add'];
        $shape['__fresh'] = $entry['fresh'];

        return $shape;
    }

    /**
     * Describes a response's shape without printing the rows themselves.
     *
     * The first version of this counted a map's KEYS and labelled them "rows", so a nested
     * paginator was reported as "threads: array(9 rows)" when it was really one map of nine
     * fields wrapping 1843 rows. A diagnostic that misreports the thing it exists to reveal is
     * worse than none, so lists and maps are now named differently, and maps are described one
     * level deep — which is exactly where the rows were hiding.
     *
     * @return array
     */
    private function describeEnvelope(array $response)
    {
        $shape = array();

        foreach ($response as $key => $value) {
            $shape[$key] = is_array($value) ? $this->describeValue($value, true) : $value;
        }

        return $shape;
    }

    /**
     * @param bool $descend Whether to describe a map's own members.
     *
     * @return string|array
     */
    private function describeValue(array $value, $descend)
    {
        if ($value === array()) {
            return 'empty array';
        }

        if (isset($value[0])) {
            return 'list of ' . count($value) . ' row(s)';
        }

        if (!$descend) {
            return 'map with keys: ' . implode(', ', array_keys($value));
        }

        $inner = array();

        foreach ($value as $key => $member) {
            $inner[$key] = is_array($member) ? $this->describeValue($member, false) : $member;
        }

        return $inner;
    }

    /**
     * @return string|false Datetime of the most recent successful refresh.
     */
    public static function getLastSync()
    {
        $value = Configuration::get('PRESTASHOPAPI_LAST_SYNC');

        return $value ? $value : false;
    }

    public static function installCache()
    {
        return Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::CACHE_TABLE . '` (
                `cache_key` VARCHAR(64) NOT NULL,
                `payload` LONGTEXT NULL,
                `date_add` DATETIME NOT NULL,
                `expires` DATETIME NOT NULL,
                PRIMARY KEY (`cache_key`),
                KEY `expires` (`expires`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        );
    }

    public static function uninstallCache()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::CACHE_TABLE . '`');
    }
}
