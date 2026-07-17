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
     * @var array Wrapper keys a collection can arrive under, tried in order.
     *
     * The endpoints are not consistent with each other: seller/products answers
     * {"products":[...]}, seller/orders answers {"sales":[...]}, but seller/threads answers a
     * Laravel paginator, {"total":1843,"per_page":5000,"last_page":1,"data":[...]}. Rather
     * than pin each endpoint to one key and break the moment another is migrated to the
     * paginator, every endpoint accepts its own key or "data".
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
        $response = $this->fetch('seller/products', array(), 'products', $force);

        return $response === false ? false : $this->extract($response, 'products');
    }

    /**
     * @return array|false List of sales lines, or false on failure.
     */
    public function getOrders($force = false)
    {
        $response = $this->fetch('seller/orders', $this->dateParams(), 'orders', $force);

        return $response === false ? false : $this->extract($response, 'sales');
    }

    /**
     * @return array|false Support threads, or false on failure.
     */
    public function getThreads($force = false)
    {
        $response = $this->fetch('seller/threads', array(), 'threads', $force);

        return $response === false ? false : $this->extract($response, array('threads', 'thread'));
    }

    /**
     * @return array|false Messages inside one thread, or false on failure.
     */
    public function getMessages($id_thread, $force = false)
    {
        $id_thread = (int) $id_thread;
        $response = $this->fetch('seller/threads/' . $id_thread . '/messages', array(), 'messages_' . $id_thread, $force);

        return $response === false ? false : $this->extract($response, array('messages', 'message'));
    }

    /**
     * @return array|false Invoices, or false on failure.
     */
    public function getInvoices($force = false)
    {
        $response = $this->fetch('seller/invoices', $this->dateParams(), 'invoices', $force);

        return $response === false ? false : $this->extract($response, array('invoices', 'invoice'));
    }

    /**
     * @return array|false Withdrawal / bank details, or false on failure.
     */
    public function getBank($force = false)
    {
        $response = $this->fetch('seller/bank', array(), 'bank', $force);

        return $response === false ? false : $this->extract($response, array('bank', 'banks'));
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

        $products = $this->extract($response, 'products');
        $count = is_array($products) ? count($products) : 0;

        return array(
            'success' => true,
            'message' => sprintf('Connected. Your seller account returned %d product(s).', $count),
        );
    }

    /* ---------------------------------------------------------------- *
     * Transport
     * ---------------------------------------------------------------- */

    /**
     * Cache-aware wrapper around call().
     *
     * @return array|false Decoded response, or false when nothing usable is available.
     */
    private function fetch($path, array $params, $cache_key, $force = false)
    {
        $this->stale = false;
        $entry = $this->readCache($cache_key);

        if (!$force && $entry !== false && $entry['fresh']) {
            return $entry['payload'];
        }

        if (!$this->allow_refresh) {
            // Front office: serve whatever we have and never call out.
            if ($entry !== false) {
                $this->stale = true;

                return $entry['payload'];
            }

            $this->last_error = 'No cached data available.';

            return false;
        }

        $response = $this->callPaged($path, $params);

        if ($response === false) {
            if ($entry !== false) {
                // Expired, but better than nothing while the marketplace is unreachable.
                $this->stale = true;

                return $entry['payload'];
            }

            return false;
        }

        $this->writeCache($cache_key, $response);

        return $response;
    }

    /**
     * Walks a paginated endpoint and returns one response with every page's rows merged in.
     *
     * seller/threads answers a Laravel paginator. Reading only page 1 silently truncates the
     * result at PAGE_LIMIT rows, which would quietly understate revenue on a busy account
     * rather than fail in any visible way.
     *
     * @return array|false
     */
    private function callPaged($path, array $params)
    {
        $first = $this->call($path, $params, false, 1);

        if ($first === false) {
            return false;
        }

        $last = isset($first['last_page']) ? (int) $first['last_page'] : 1;

        if ($last <= 1) {
            return $first;
        }

        $key = $this->listKey($first);

        if ($key === null) {
            return $first;
        }

        $last = min($last, self::MAX_PAGES);

        for ($page = 2; $page <= $last; ++$page) {
            $next = $this->call($path, $params, false, $page);

            // A partial result beats no result: keep whatever pages did arrive.
            if ($next === false || !isset($next[$key]) || !is_array($next[$key])) {
                break;
            }

            $first[$key] = array_merge($first[$key], $next[$key]);
        }

        return $first;
    }

    /**
     * Name of the key holding the rows, for a paginated response.
     *
     * @return string|null
     */
    private function listKey(array $response)
    {
        foreach ($response as $key => $value) {
            if (is_array($value) && (isset($value[0]) || $value === array())) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Performs one API call.
     *
     * @return array|false Decoded JSON, or false with last_error set.
     */
    private function call($path, array $params, $multipart = false, $page = 1)
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
        // "data" last, so an endpoint with a named wrapper keeps using it.
        foreach (array_merge((array) $keys, self::LIST_KEYS) as $key) {
            if (!isset($response[$key])) {
                continue;
            }

            $value = $response[$key];

            if (!is_array($value)) {
                return array();
            }

            // A single row arrives as a map of scalars rather than a list of rows.
            if ($value !== array() && !isset($value[0]) && !is_array(reset($value))) {
                return array($value);
            }

            return array_values($value);
        }

        return array();
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

        Db::getInstance()->execute(
            'REPLACE INTO `' . _DB_PREFIX_ . self::CACHE_TABLE . '` (`cache_key`, `payload`, `date_add`, `expires`)
             VALUES ("' . pSQL($this->cacheId($key)) . '",
                     "' . pSQL(json_encode($payload)) . '",
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
