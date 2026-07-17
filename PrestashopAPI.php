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

require_once dirname(__FILE__) . '/classes/SellerApiClient.php';
require_once dirname(__FILE__) . '/classes/SellerStats.php';

/**
 * Seller Dashboard: brings PrestaShop Addons marketplace sales into the shop's own back office.
 *
 * Two rules govern the whole module:
 *
 *  1. The storefront never calls the API. Marketplace data reaches the front office only
 *     through a small precomputed map in Configuration, refreshed from the back office or by
 *     cron. v1 made two uncached cURL calls per product page view, per visitor.
 *  2. Nothing marketplace-related may break a page. Every hook body is wrapped in
 *     catch (Throwable) because an undefined method is an Error, not an Exception, and would
 *     otherwise sail straight past a catch (Exception) guard.
 */
class PrestashopAPI extends Module
{
    /** @var string Kept in a constant so the API client can send it as a user agent. */
    const MODULE_VERSION = '2.0.0';

    /** @var string Query parameter for config-page actions. "action" is reserved by the admin dispatcher. */
    const ACTION_PARAM = 'psapi_action';

    /** @var array Reporting windows offered in the settings form. */
    const PERIODS = array('30d', '3m', '6m', '12m', 'ytd', 'all', 'custom');

    /** @var array Allowed cache lifetimes, in minutes. */
    const TTL_CHOICES = array(15, 30, 60, 180, 720, 1440);

    /** @var string Date the marketplace opened. Used as the lower bound for the "all time" window. */
    const EPOCH = '2008-01-01';

    /** @var int Largest reply attachment accepted, in bytes. */
    const ATTACHMENT_MAX = 8388608;

    /** @var array Extensions a buyer support reply may carry. */
    const ATTACHMENT_TYPES = array(
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'pdf', 'txt', 'log', 'csv', 'zip', 'doc', 'docx', 'xls', 'xlsx',
    );

    /** @var array Every hook the module needs. Checked on each visit to the config page. */
    const HOOKS = array(
        'actionFrontControllerSetMedia',
        'displayProductAdditionalInfo',
        'displayDashboardTop',
    );

    /** @var string */
    private $html = '';

    /** @var array */
    private $errors_list = array();

    public function __construct()
    {
        $this->name = 'PrestashopAPI';
        $this->tab = 'administration';
        $this->version = self::MODULE_VERSION;
        $this->author = 'MEG Venture';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('Seller Dashboard - Marketplace Sales, Messages & Payouts');
        $this->description = $this->l('See how your PrestaShop Addons marketplace products are selling without leaving your own back office. Revenue, units, refunds, buyer countries and monthly trends, combined with the sales of the same products in your own shop, plus your buyer messages and payouts.');
        $this->confirmUninstall = $this->l('Your API key, product links and cached marketplace data will be deleted. Your sales history on the marketplace is not affected. Do you confirm?');
    }

    /* ================================================================ *
     * Install / uninstall
     * ================================================================ */

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!SellerApiClient::installCache()) {
            return false;
        }

        $defaults = array(
            'PRESTASHOPAPI_KEY' => '',
            'PRESTASHOPAPI_PERIOD' => '12m',
            'PRESTASHOPAPI_DATE_FROM' => '',
            'PRESTASHOPAPI_DATE_TO' => '',
            'PRESTASHOPAPI_CURRENCY' => SellerStats::DEFAULT_MARKETPLACE_CURRENCY,
            'PRESTASHOPAPI_CACHE_TTL' => 60,
            'PRESTASHOPAPI_BADGE_ENABLED' => 0,
            'PRESTASHOPAPI_BADGE_MIN' => 10,
            'PRESTASHOPAPI_BADGE_SCOPE' => 'total',
            'PRESTASHOPAPI_LINKS' => '{}',
            'PRESTASHOPAPI_BADGE_MAP' => '{}',
            'PRESTASHOPAPI_SEEN' => '{}',
        );

        foreach ($defaults as $key => $value) {
            // Never clobber a value left behind by a previous install.
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }

        Configuration::updateValue('PRESTASHOPAPI_CRON_TOKEN', Tools::passwdGen(32, 'ALPHANUMERIC'));

        // The configuration page loads its own assets with tags in the template, so no
        // back-office media hook is needed.
        foreach (self::HOOKS as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    public function uninstall()
    {
        $keys = array(
            'PRESTASHOPAPI_KEY', 'PRESTASHOPAPI_PERIOD', 'PRESTASHOPAPI_DATE_FROM',
            'PRESTASHOPAPI_DATE_TO', 'PRESTASHOPAPI_CURRENCY', 'PRESTASHOPAPI_CACHE_TTL',
            'PRESTASHOPAPI_BADGE_ENABLED', 'PRESTASHOPAPI_BADGE_MIN', 'PRESTASHOPAPI_BADGE_SCOPE',
            'PRESTASHOPAPI_LINKS', 'PRESTASHOPAPI_BADGE_MAP', 'PRESTASHOPAPI_SEEN',
            'PRESTASHOPAPI_CRON_TOKEN', 'PRESTASHOPAPI_LAST_SYNC', 'PRESTASHOPAPI_LAST_ERROR',
            // v1 keys, unprefixed and liable to collide with other modules.
            'PRODUCT_PAGE_FRONT_ENABLE', 'API_DATE_FROM', 'API_DATE_TO',
        );

        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        SellerApiClient::uninstallCache();

        return parent::uninstall();
    }

    /* ================================================================ *
     * Period handling
     * ================================================================ */

    /**
     * Turns the saved reporting period into a concrete date window.
     *
     * v1 asked the merchant to type two YYYY-MM-DD strings and validated neither, so a typo
     * silently returned the API's default three-month window instead of what was asked for.
     *
     * @return array{0: string, 1: string} from, to (YYYY-MM-DD)
     */
    public static function resolvePeriod()
    {
        $period = (string) Configuration::get('PRESTASHOPAPI_PERIOD');
        $today = date('Y-m-d');

        switch ($period) {
            case '30d':
                return array(date('Y-m-d', strtotime('-30 days')), $today);
            case '3m':
                return array(date('Y-m-d', strtotime('-3 months')), $today);
            case '6m':
                return array(date('Y-m-d', strtotime('-6 months')), $today);
            case 'ytd':
                return array(date('Y-01-01'), $today);
            case 'all':
                return array(self::EPOCH, $today);
            case 'custom':
                $from = (string) Configuration::get('PRESTASHOPAPI_DATE_FROM');
                $to = (string) Configuration::get('PRESTASHOPAPI_DATE_TO');

                return array(
                    $from !== '' ? $from : date('Y-m-d', strtotime('-12 months')),
                    $to !== '' ? $to : $today,
                );
            case '12m':
            default:
                return array(date('Y-m-d', strtotime('-12 months')), $today);
        }
    }

    /**
     * @return SellerApiClient Configured for the saved reporting window.
     */
    private function client($allow_refresh = true)
    {
        $dates = self::resolvePeriod();

        return new SellerApiClient($allow_refresh, $dates[0], $dates[1]);
    }

    /* ================================================================ *
     * Configuration page
     * ================================================================ */

    public function getContent()
    {
        $this->html = '';
        $this->ensureHooks();

        if (Tools::isSubmit('submitPrestashopAPISettings')) {
            $this->saveSettings();
        } elseif (Tools::isSubmit('submitPrestashopAPILinks')) {
            $this->saveLinks();
        } elseif (Tools::isSubmit('submitPrestashopAPIReply')) {
            $this->sendReply();
        } else {
            $this->handleAction();
        }

        $this->html .= $this->renderFlash();

        if (!SellerApiClient::isCurlAvailable()) {
            $this->html .= $this->displayError($this->l('The PHP cURL extension is not enabled on this server, so this module cannot contact the marketplace. Please ask your hosting provider to enable it.'));
        }

        return $this->html . $this->renderPage();
    }

    /**
     * Registers any hook the installed copy is missing.
     *
     * install() runs exactly once, so a hook introduced in a later revision than the one a
     * merchant installed stays unregistered for ever, and whatever it powered silently does
     * nothing. That is what happened to the Dashboard notice. Reconciling here costs one
     * cached query per visit to this page and removes a whole class of "why is nothing
     * showing" reports, without asking anyone to reset the module and lose their settings.
     *
     * @return string[] Hooks that had to be repaired.
     */
    private function ensureHooks()
    {
        $repaired = array();

        foreach (self::HOOKS as $hook) {
            if (!$this->isRegisteredInHook($hook)) {
                $this->registerHook($hook);
                $repaired[] = $hook;
            }
        }

        return $repaired;
    }

    /**
     * Handles link-style actions. Everything redirects back so a refresh cannot replay them.
     */
    private function handleAction()
    {
        switch (Tools::getValue(self::ACTION_PARAM)) {
            case 'refresh':
                $this->refreshData();
                $this->redirectBack(2);
                break;

            case 'test':
                $result = $this->client()->testConnection();
                $this->redirectBack($result['success'] ? 6 : 0, $result['success'] ? '' : $result['message']);
                break;

            case 'clear':
                SellerApiClient::purgeCache();
                Configuration::updateValue('PRESTASHOPAPI_BADGE_MAP', '{}');
                $this->redirectBack(5);
                break;

            case 'export':
                $this->exportCsv();
                break;

            case 'readall':
                $threads = $this->client()->getThreads();
                $this->markSeen(is_array($threads) ? $threads : array());
                $this->redirectBack(7, '', 'messages');
                break;
        }
    }

    /**
     * Post/redirect/get so that the browser's reload button cannot resubmit an action.
     */
    private function redirectBack($conf, $error = '', $tab = '')
    {
        $url = $this->configUrl();

        if ($conf) {
            $url .= '&psapi_conf=' . (int) $conf;
        }

        if ($error !== '') {
            // Survives the redirect without needing a session flash bag.
            Configuration::updateValue('PRESTASHOPAPI_LAST_ERROR', Tools::substr($error, 0, 500));
            $url .= '&psapi_err=1';
        }

        if ($tab !== '') {
            $url .= '#psapi-' . $tab;
        }

        Tools::redirectAdmin($url);
    }

    private function configUrl()
    {
        return $this->context->link->getAdminLink('AdminModules', true)
            . '&configure=' . $this->name
            . '&module_name=' . $this->name
            . '&tab_module=' . $this->tab;
    }

    private function renderFlash()
    {
        $html = '';
        $messages = array(
            1 => $this->l('Settings saved.'),
            2 => $this->l('Marketplace data refreshed.'),
            3 => $this->l('Product links saved.'),
            4 => $this->l('Your reply has been sent to the buyer.'),
            5 => $this->l('Cached marketplace data cleared.'),
            6 => $this->l('Connection to the marketplace works.'),
            7 => $this->l('All conversations marked as read.'),
        );

        $conf = (int) Tools::getValue('psapi_conf');

        if (isset($messages[$conf])) {
            $html .= $this->displayConfirmation($messages[$conf]);
        }

        if (Tools::getValue('psapi_err')) {
            $error = Configuration::get('PRESTASHOPAPI_LAST_ERROR');
            Configuration::updateValue('PRESTASHOPAPI_LAST_ERROR', '');

            if ($error) {
                $html .= $this->displayError($error);
            }
        }

        foreach ($this->errors_list as $error) {
            $html .= $this->displayError($error);
        }

        return $html;
    }

    /* ================================================================ *
     * Actions
     * ================================================================ */

    /**
     * Pulls every endpoint we display and rebuilds the storefront badge map.
     */
    private function refreshData()
    {
        $client = $this->client();

        if (!$client->hasKey()) {
            return false;
        }

        $client->getProducts(true);
        $sales = $client->getOrders(true);
        $client->getThreads(true);
        $client->getInvoices(true);

        if ($sales === false) {
            $this->errors_list[] = $client->getLastError();

            return false;
        }

        $this->rebuildBadgeMap($sales);

        return true;
    }

    /**
     * Collapses the sales rows into "marketplace product id => units sold" and stores it in
     * Configuration, which PrestaShop already loads into memory on every request. That is
     * what lets the storefront badge cost zero queries and zero HTTP calls.
     */
    private function rebuildBadgeMap(array $sales)
    {
        $map = array();

        foreach ($sales as $sale) {
            $id = (string) (isset($sale['id_product']) ? $sale['id_product'] : '');

            if ($id === '') {
                continue;
            }

            $qty = (int) (isset($sale['product_quantity']) ? $sale['product_quantity'] : 0);
            $ref = (int) (isset($sale['product_quantity_refunded']) ? $sale['product_quantity_refunded'] : 0);

            if (!isset($map[$id])) {
                $map[$id] = 0;
            }

            $map[$id] += $qty - $ref;
        }

        Configuration::updateValue('PRESTASHOPAPI_BADGE_MAP', json_encode($map));
    }

    private function saveSettings()
    {
        $key = trim((string) Tools::getValue('PRESTASHOPAPI_KEY'));
        $period = (string) Tools::getValue('PRESTASHOPAPI_PERIOD');
        $from = trim((string) Tools::getValue('PRESTASHOPAPI_DATE_FROM'));
        $to = trim((string) Tools::getValue('PRESTASHOPAPI_DATE_TO'));
        $currency = Tools::strtoupper(trim((string) Tools::getValue('PRESTASHOPAPI_CURRENCY')));
        $ttl = (int) Tools::getValue('PRESTASHOPAPI_CACHE_TTL');
        $badge_min = (int) Tools::getValue('PRESTASHOPAPI_BADGE_MIN');
        $scope = (string) Tools::getValue('PRESTASHOPAPI_BADGE_SCOPE');

        // A key pasted out of the seller account page often arrives with stray whitespace,
        // or the merchant pastes the whole URL of the API page instead of the key.
        if ($key !== '') {
            if (Tools::strlen($key) < 8 || !preg_match('/^[A-Za-z0-9\-_]+$/', $key)) {
                $this->errors_list[] = $this->l('That does not look like an API key. Copy only the key itself from your seller account (letters, digits, dashes), without any surrounding text or URL.');
            }
        }

        if (!in_array($period, self::PERIODS, true)) {
            $period = '12m';
        }

        if ($period === 'custom') {
            if ($from === '' || !Validate::isDate($from)) {
                $this->errors_list[] = $this->l('The "from" date is not a valid date. Expected format: YYYY-MM-DD.');
            }

            if ($to !== '' && !Validate::isDate($to)) {
                $this->errors_list[] = $this->l('The "to" date is not a valid date. Expected format: YYYY-MM-DD.');
            }

            if ($from !== '' && $to !== '' && Validate::isDate($from) && Validate::isDate($to)
                && strtotime($from) > strtotime($to)) {
                $this->errors_list[] = $this->l('The "from" date is later than the "to" date.');
            }
        }

        if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            $this->errors_list[] = $this->l('The marketplace currency must be a three-letter ISO code, for example EUR.');
        }

        if (!in_array($ttl, self::TTL_CHOICES, true)) {
            $ttl = 60;
        }

        if ($badge_min < 0 || $badge_min > 1000000) {
            $this->errors_list[] = $this->l('The minimum sales figure for the badge must be between 0 and 1000000.');
        }

        if (!in_array($scope, array('addons', 'total'), true)) {
            $scope = 'total';
        }

        if ($this->errors_list) {
            return false;
        }

        $key_changed = $key !== (string) Configuration::get('PRESTASHOPAPI_KEY');
        $period_changed = $period !== (string) Configuration::get('PRESTASHOPAPI_PERIOD')
            || $from !== (string) Configuration::get('PRESTASHOPAPI_DATE_FROM')
            || $to !== (string) Configuration::get('PRESTASHOPAPI_DATE_TO');

        Configuration::updateValue('PRESTASHOPAPI_KEY', $key);
        Configuration::updateValue('PRESTASHOPAPI_PERIOD', $period);
        Configuration::updateValue('PRESTASHOPAPI_DATE_FROM', $from);
        Configuration::updateValue('PRESTASHOPAPI_DATE_TO', $to);
        Configuration::updateValue('PRESTASHOPAPI_CURRENCY', $currency);
        Configuration::updateValue('PRESTASHOPAPI_CACHE_TTL', $ttl);
        Configuration::updateValue('PRESTASHOPAPI_BADGE_ENABLED', (bool) Tools::getValue('PRESTASHOPAPI_BADGE_ENABLED'));
        Configuration::updateValue('PRESTASHOPAPI_BADGE_MIN', $badge_min);
        Configuration::updateValue('PRESTASHOPAPI_BADGE_SCOPE', $scope);

        // Cached rows belong to the old key or the old window, so they are now misleading.
        if ($key_changed || $period_changed) {
            SellerApiClient::purgeCache();
            $this->refreshData();
        }

        $this->redirectBack(1, '', 'settings');
    }

    private function saveLinks()
    {
        $submitted = Tools::getValue('psapi_link');
        $links = array();

        if (is_array($submitted)) {
            foreach ($submitted as $marketplace_id => $id_local) {
                if ((int) $id_local > 0) {
                    $links[(string) $marketplace_id] = (int) $id_local;
                }
            }
        }

        SellerStats::setManualLinks($links);
        $this->redirectBack(3, '', 'products');
    }

    private function sendReply()
    {
        $id_thread = (int) Tools::getValue('psapi_id_thread');
        $message = (string) Tools::getValue('psapi_message');

        if ($id_thread <= 0) {
            $this->errors_list[] = $this->l('Unknown conversation.');

            return false;
        }

        if (Tools::strlen(trim($message)) < 2) {
            $this->errors_list[] = $this->l('Please write a message before sending.');

            return false;
        }

        $path = null;
        $name = null;

        if (isset($_FILES['psapi_attachment']) && (int) $_FILES['psapi_attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
            $attachment = $this->validateAttachment($_FILES['psapi_attachment']);

            if ($attachment === false) {
                return false;
            }

            $path = $attachment['path'];
            $name = $attachment['name'];
        }

        $client = $this->client();

        if (!$client->sendMessage($id_thread, $message, $path, $name)) {
            $this->redirectBack(0, $client->getLastError(), 'messages');

            return false;
        }

        $this->redirectBack(4, '', 'messages');

        return true;
    }

    /**
     * Checks one uploaded reply attachment.
     *
     * @return array{path: string, name: string}|false False with errors_list populated.
     */
    private function validateAttachment(array $file)
    {
        $error = (int) $file['error'];

        if ($error !== UPLOAD_ERR_OK) {
            // A file larger than PHP accepts never reaches the size test below, so the upload
            // error codes have to be reported in their own right or the form fails silently.
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                $this->errors_list[] = sprintf(
                    $this->l('That file is larger than this server accepts (%s).'),
                    ini_get('upload_max_filesize')
                );
            } else {
                $this->errors_list[] = $this->l('The file could not be uploaded. Please try again.');
            }

            return false;
        }

        // Guards against a crafted path being passed off as an upload.
        if (!is_uploaded_file($file['tmp_name'])) {
            $this->errors_list[] = $this->l('The file could not be uploaded. Please try again.');

            return false;
        }

        if ((int) $file['size'] <= 0) {
            $this->errors_list[] = $this->l('That file is empty.');

            return false;
        }

        if ((int) $file['size'] > self::ATTACHMENT_MAX) {
            $this->errors_list[] = sprintf(
                $this->l('That file is too large. The limit is %d MB.'),
                self::ATTACHMENT_MAX / 1048576
            );

            return false;
        }

        // basename() strips any directory component the client put in the name.
        $name = basename(str_replace('\\', '/', (string) $file['name']));
        $extension = Tools::strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ATTACHMENT_TYPES, true)) {
            $this->errors_list[] = sprintf(
                $this->l('Files of type "%s" cannot be attached. Allowed: %s.'),
                $extension === '' ? '?' : $extension,
                implode(', ', self::ATTACHMENT_TYPES)
            );

            return false;
        }

        return array('path' => $file['tmp_name'], 'name' => $name);
    }

    /* ================================================================ *
     * Unread conversations
     * ================================================================ */

    /**
     * Conversations that have changed since the merchant last looked at them.
     *
     * The threads endpoint is undocumented, so rather than reading a "status" or "answered"
     * field that may not exist, each row is fingerprinted whole. A new buyer message has to
     * change something in the row it belongs to (a date, a counter), so the fingerprint moves
     * and the conversation resurfaces. This is why the feature says "new" and not
     * "unanswered": the latter is not knowable from what the API documents.
     *
     * @return int[] Thread ids.
     */
    private function unreadThreads(array $threads)
    {
        $seen = json_decode((string) Configuration::get('PRESTASHOPAPI_SEEN'), true);
        $seen = is_array($seen) ? $seen : array();

        // First run on an established account: a real seller has well over a thousand
        // conversations, and flagging every one of them as new would be noise, not a
        // notification. Start from "all read" and report only what moves from here.
        if (!$seen && $threads) {
            $this->markSeen($threads);

            return array();
        }

        $unread = array();

        foreach ($threads as $thread) {
            if (!is_array($thread)) {
                continue;
            }

            $id = self::threadId($thread);

            if (!$id) {
                continue;
            }

            if (!isset($seen[$id]) || $seen[$id] !== self::fingerprint($thread)) {
                $unread[] = $id;
            }
        }

        return $unread;
    }

    /**
     * A conversation's id.
     *
     * The threads endpoint calls it id_community_thread, not id_thread. Reading the wrong name
     * meant every row was skipped: no conversation could be opened and none could be flagged.
     * Both names are accepted because seller/threads/{id}/messages may well use the shorter one.
     *
     * @return int 0 when the row carries no id.
     */
    private static function threadId(array $thread)
    {
        foreach (array('id_community_thread', 'id_thread') as $field) {
            if (isset($thread[$field]) && (int) $thread[$field] > 0) {
                return (int) $thread[$field];
            }
        }

        return 0;
    }

    /**
     * Stable hash of a thread row's scalar fields.
     */
    private static function fingerprint(array $thread)
    {
        $flat = array();

        foreach ($thread as $field => $value) {
            if (is_scalar($value) || $value === null) {
                $flat[$field] = (string) $value;
            }
        }

        // Sorted so that a reordered response is not mistaken for a changed one.
        ksort($flat);

        return md5(json_encode($flat));
    }

    /**
     * @param int|null $id_thread Thread to mark, or null for every thread.
     */
    private function markSeen(array $threads, $id_thread = null)
    {
        $seen = json_decode((string) Configuration::get('PRESTASHOPAPI_SEEN'), true);
        $seen = is_array($seen) ? $seen : array();
        $current = array();

        foreach ($threads as $thread) {
            if (!is_array($thread)) {
                continue;
            }

            $id = self::threadId($thread);

            if (!$id) {
                continue;
            }

            $current[$id] = true;

            if ($id_thread === null || $id === (int) $id_thread) {
                $seen[$id] = self::fingerprint($thread);
            }
        }

        // Drop threads the marketplace no longer returns, so the value cannot grow forever.
        foreach (array_keys($seen) as $id) {
            if (!isset($current[$id])) {
                unset($seen[$id]);
            }
        }

        Configuration::updateValue('PRESTASHOPAPI_SEEN', json_encode($seen));
    }

    /**
     * Streams the sales rows as CSV.
     *
     * Downloads from getContent() only work while the admin layout has not been flushed, so
     * the state is checked rather than assumed; if it has, the merchant gets a message
     * instead of a corrupted page.
     */
    private function exportCsv()
    {
        $client = $this->client();
        $sales = $client->getOrders();

        if ($sales === false) {
            $this->redirectBack(0, $client->getLastError());

            return;
        }

        if (headers_sent()) {
            $this->errors_list[] = $this->l('The export could not start because the page had already begun rendering. Please try again.');

            return;
        }

        $columns = array(
            'order_date' => $this->l('Date'),
            'id_order' => $this->l('Order'),
            'id_product' => $this->l('Product ID'),
            'product_name' => $this->l('Product'),
            'product_type' => $this->l('Type'),
            'iso_code' => $this->l('Country'),
            'amount' => $this->l('Unit amount'),
            'product_quantity' => $this->l('Quantity'),
            'product_quantity_refunded' => $this->l('Refunded'),
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="marketplace-sales-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        // Excel needs the BOM to read UTF-8 product names correctly.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values($columns), ';');

        foreach ($sales as $sale) {
            $line = array();

            foreach (array_keys($columns) as $field) {
                $line[] = self::csvCell(isset($sale[$field]) ? $sale[$field] : '');
            }

            fputcsv($out, $line, ';');
        }

        fclose($out);
        exit;
    }

    /**
     * A cell starting with = + - or @ is executed as a formula when the file is opened in a
     * spreadsheet, so neutralise it with a leading tab.
     */
    private static function csvCell($value)
    {
        $value = (string) $value;

        return $value !== '' && strpos('=+-@', $value[0]) !== false ? "\t" . $value : $value;
    }

    /* ================================================================ *
     * Rendering
     * ================================================================ */

    private function renderPage()
    {
        $client = $this->client();
        $has_key = $client->hasKey();

        $products = $has_key ? $client->getProducts() : array();
        $sales = $has_key ? $client->getOrders() : array();
        $api_error = '';

        if ($products === false || $sales === false) {
            $api_error = $client->getLastError();
            $products = is_array($products) ? $products : array();
            $sales = is_array($sales) ? $sales : array();
        }

        $stats = new SellerStats($sales, $products);
        $link_health = $stats->getLinkHealth();
        $dates = self::resolvePeriod();
        $summary = $stats->getSummary();

        $threads = $has_key ? $this->safeList($client->getThreads()) : array();
        $unread = $this->unreadThreads($threads);

        $this->context->smarty->assign(array(
            'psapi_has_key' => $has_key,
            'psapi_api_error' => $api_error,
            'psapi_stale' => $client->isStale(),
            'psapi_curl' => SellerApiClient::isCurlAvailable(),
            'psapi_last_sync' => SellerApiClient::getLastSync(),
            'psapi_period_from' => $dates[0],
            'psapi_period_to' => $dates[1],

            'psapi_summary' => $summary,
            'psapi_money' => $this->formatSummary($summary),
            'psapi_products' => $this->decorateProducts($stats->getProductRows()),
            'psapi_sales' => array_slice($sales, 0, 500),
            'psapi_sales_total' => count($sales),
            'psapi_months' => $this->prepareChart($stats->getMonthlySeries(12)),
            'psapi_countries' => $stats->getCountryBreakdown(8),

            // Capped: this account has 1843 conversations, and every rendered row is markup the
            // browser has to parse. The filter box searches what is rendered.
            'psapi_threads' => array_slice($this->buildThreads($threads, $unread), 0, 300),
            'psapi_threads_total' => count($threads),
            'psapi_unread' => count($unread),
            'psapi_invoices' => $this->normalizeTable($has_key ? $this->safeList($client->getInvoices()) : array()),
            'psapi_thread' => $this->currentThread($client, $threads),
            'psapi_attachment_types' => implode(', ', self::ATTACHMENT_TYPES),
            'psapi_attachment_max' => (int) (self::ATTACHMENT_MAX / 1048576),

            'psapi_link_health' => $link_health,
            'psapi_currency_mismatch' => $stats->hasCurrencyMismatch(),
            'psapi_marketplace_iso' => $stats->getMarketplaceIso(),
            'psapi_shop_iso' => $stats->getShopIso(),
            'psapi_local_products' => $this->localProductChoices(),

            'psapi_diag' => $this->diagnostics($client, $threads, $sales, $products),
            'psapi_config_url' => $this->configUrl(),
            'psapi_action_param' => self::ACTION_PARAM,
            'psapi_cron_url' => $this->cronUrl(),
            'psapi_module_dir' => $this->_path,
            'psapi_version' => self::MODULE_VERSION,
            'psapi_settings_form' => $this->renderSettingsForm(),
        ));

        // fetch(), not display(): display() resolves templates through the front-office theme
        // override paths, which do not apply to a back-office template.
        return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
    }

    /**
     * @return array Always a list, even when the endpoint failed.
     */
    private function safeList($value)
    {
        return is_array($value) ? $value : array();
    }

    /**
     * Money is formatted here rather than in the template, because PrestaShop 9 removed
     * Tools::displayPrice() and Smarty has no equivalent that works across 1.7 to 9.
     *
     * Marketplace figures stay in the marketplace currency; anything combining marketplace
     * and local sales is shown in the shop currency, since that is the only currency both
     * halves can share.
     */
    private function formatSummary(array $summary)
    {
        $iso = Configuration::get('PRESTASHOPAPI_CURRENCY');

        return array(
            'addons_revenue' => SellerStats::formatMarketplacePrice($summary['addons_revenue'], $iso),
            'addons_last30' => SellerStats::formatMarketplacePrice($summary['addons_last30'], $iso),
            'addons_average' => SellerStats::formatMarketplacePrice($summary['addons_average'], $iso),
            'addons_refund_value' => SellerStats::formatMarketplacePrice($summary['addons_refund_value'], $iso),
            'local_revenue' => SellerStats::formatShopPrice($summary['local_revenue']),
            'total_revenue' => $summary['total_revenue'] === null
                ? null
                : SellerStats::formatShopPrice($summary['total_revenue']),
        );
    }

    /**
     * Adds display strings to the product rows.
     */
    private function decorateProducts(array $rows)
    {
        $iso = Configuration::get('PRESTASHOPAPI_CURRENCY');

        foreach ($rows as $index => $row) {
            $rows[$index]['price_display'] = SellerStats::formatMarketplacePrice($row['price'], $iso);
            $rows[$index]['addons_revenue_display'] = SellerStats::formatMarketplacePrice($row['addons_revenue'], $iso);
            $rows[$index]['local_revenue_display'] = SellerStats::formatShopPrice($row['local_revenue']);
            $rows[$index]['total_revenue_display'] = $row['total_revenue'] === null
                ? null
                : SellerStats::formatShopPrice($row['total_revenue']);
        }

        return $rows;
    }

    /**
     * Loads the messages of the thread the merchant opened, if any.
     */
    private function currentThread(SellerApiClient $client, array $threads)
    {
        $id_thread = (int) Tools::getValue('psapi_id_thread');

        if ($id_thread <= 0) {
            return null;
        }

        // Opening the conversation is what marks it read.
        $this->markSeen($threads, $id_thread);

        $messages = array();

        foreach ($this->safeList($client->getMessages($id_thread)) as $message) {
            if (!is_array($message)) {
                continue;
            }

            $meta = array();

            foreach (array('date_add', 'author', 'customer_name', 'employee_name', 'name') as $field) {
                if (isset($message[$field]) && is_scalar($message[$field]) && (string) $message[$field] !== '') {
                    // Escaped here because the joined string is printed with nofilter, the
                    // separator being markup.
                    $meta[] = htmlspecialchars((string) $message[$field], ENT_QUOTES, 'UTF-8');
                }
            }

            $body = '';

            foreach (array('message', 'content', 'text') as $field) {
                if (isset($message[$field]) && is_scalar($message[$field])) {
                    $body = (string) $message[$field];
                    break;
                }
            }

            $messages[] = array('meta' => implode(' &middot; ', $meta), 'body' => $body);
        }

        return array('id' => $id_thread, 'messages' => $messages);
    }

    /**
     * Builds the conversation list from the real thread fields.
     *
     * Purpose-built rather than run through normalizeTable(), now that the shape is known: the
     * generic renderer would print raw customer_hash and token columns and drop `zen` and
     * `quantity` entirely for being nested.
     *
     * @return array
     */
    private function buildThreads(array $threads, array $unread)
    {
        $rows = array();

        foreach ($threads as $thread) {
            if (!is_array($thread)) {
                continue;
            }

            $id = self::threadId($thread);

            if (!$id) {
                continue;
            }

            $zen = isset($thread['zen']) && is_array($thread['zen']) ? $thread['zen'] : array();
            $version = isset($thread['versionps']) ? trim((string) $thread['versionps']) : '';

            $rows[] = array(
                'id' => $id,
                'topic' => isset($thread['topic']) ? (string) $thread['topic'] : '',
                'product' => isset($thread['name']) ? (string) $thread['name'] : '',
                'id_product' => isset($thread['id_product']) ? (int) $thread['id_product'] : 0,
                'messages' => isset($thread['nb_messages']) ? (int) $thread['nb_messages'] : 0,
                'date' => isset($thread['date_add']) ? (string) $thread['date_add'] : '',
                // "" | presale | aftersale.
                'qualification' => isset($thread['qualification']) ? (string) $thread['qualification'] : '',
                // The API writes "none" when the buyer did not say.
                'version' => $version === 'none' ? '' : $version,
                'website' => isset($thread['website']) ? (string) $thread['website'] : '',
                // id_order 0 means the person has not bought: a pre-sales question.
                'is_buyer' => !empty($thread['id_order']),
                // zen is the Business Care entitlement. Negative days left means their free
                // support window has closed, which is worth knowing before you spend an hour.
                'support_days' => isset($zen['nb_days_left']) ? (int) $zen['nb_days_left'] : null,
                'unread' => in_array($id, $unread, true),
            );
        }

        return $rows;
    }

    /**
     * One sample row from each endpoint, pretty-printed.
     *
     * The Seller API is undocumented beyond products and orders, so several features here are
     * built against field names that are inferred rather than known. This panel shows exactly
     * what the account actually returns, which is the difference between implementing
     * "unanswered" properly and guessing at a status field.
     *
     * One row per endpoint, not the whole payload: enough to read the shape, without spilling
     * every buyer's details across the page.
     *
     * @return array|null endpoint => JSON, or null when not requested.
     */
    private function diagnostics(SellerApiClient $client, array $threads, array $sales, array $products)
    {
        if (!Tools::getValue('psapi_diag')) {
            return null;
        }

        $samples = array(
            'seller/products' => isset($products[0]) ? $products[0] : null,
            'seller/orders' => isset($sales[0]) ? $sales[0] : null,
            'seller/threads' => isset($threads[0]) ? $threads[0] : null,
        );

        if (isset($threads[0]['id_thread'])) {
            $messages = $this->safeList($client->getMessages((int) $threads[0]['id_thread']));
            $samples['seller/threads/{id}/messages'] = isset($messages[0]) ? $messages[0] : null;
        }

        $invoices = $this->safeList($client->getInvoices());
        $samples['seller/invoices'] = isset($invoices[0]) ? $invoices[0] : null;

        $bank = $this->safeList($client->getBank());
        $samples['seller/bank'] = isset($bank[0]) ? $bank[0] : null;

        $out = array();
        $flags = 128; // JSON_PRETTY_PRINT, named numerically for PHP 5.3 parsers.

        if (defined('JSON_UNESCAPED_SLASHES')) {
            $flags |= JSON_UNESCAPED_SLASHES;
        }

        if (defined('JSON_UNESCAPED_UNICODE')) {
            $flags |= JSON_UNESCAPED_UNICODE;
        }

        foreach ($samples as $endpoint => $row) {
            $out[$endpoint] = $row === null
                ? '// no rows returned for this account'
                : json_encode($row, $flags);
        }

        return $out;
    }

    /**
     * Turns an arbitrary list of API rows into columns plus scalar cells.
     *
     * The threads, messages, invoices and bank endpoints are not documented and their shapes
     * are not versioned, so rather than hard-coding field names that may not exist, whatever
     * scalar fields come back are displayed with humanised labels. A field that disappears
     * simply stops being a column instead of fataling a template.
     *
     * @param int[] $unread Thread ids to flag, when the rows are conversations.
     *
     * @return array{columns: array, rows: array}
     */
    private function normalizeTable(array $rows, array $unread = array())
    {
        $columns = array();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($row as $field => $value) {
                // Nested structures have no sensible column, and we cannot guess a renderer.
                if (is_scalar($value) || $value === null) {
                    $columns[$field] = Tools::ucfirst(str_replace('_', ' ', (string) $field));
                }
            }
        }

        $out = array();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cells = array();

            foreach (array_keys($columns) as $field) {
                $value = isset($row[$field]) && is_scalar($row[$field]) ? (string) $row[$field] : '';
                $cells[$field] = array(
                    'text' => $value,
                    'is_url' => (bool) preg_match('#^https?://#i', $value),
                );
            }

            $id_thread = isset($row['id_thread']) ? (int) $row['id_thread'] : 0;

            $out[] = array(
                'cells' => $cells,
                'id_thread' => $id_thread,
                'unread' => $id_thread > 0 && in_array($id_thread, $unread, true),
            );
        }

        return array('columns' => $columns, 'rows' => $out);
    }

    /**
     * Scales the monthly series to a 0-100 percentage so the template can draw bars with
     * plain CSS heights. Charting this server-side avoids shipping a JavaScript chart
     * library, which the back office would have to load from a CDN.
     */
    private function prepareChart(array $months)
    {
        $max = 0.0;

        foreach ($months as $month) {
            $max = max($max, (float) $month['revenue']);
        }

        foreach ($months as $index => $month) {
            $months[$index]['pct'] = $max > 0 ? round($month['revenue'] / $max * 100, 2) : 0;
            $months[$index]['revenue_display'] = SellerStats::formatMarketplacePrice(
                $month['revenue'],
                Configuration::get('PRESTASHOPAPI_CURRENCY')
            );
            $months[$index]['short'] = date('M y', strtotime($month['label'] . '-01'));
        }

        return $months;
    }

    /**
     * @return array id_product => "reference - name", for the manual link dropdowns.
     */
    private function localProductChoices()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT p.`id_product`, p.`reference`, pl.`name`
             FROM `' . _DB_PREFIX_ . 'product` p
             LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.`id_product` = p.`id_product`
               AND pl.`id_lang` = ' . (int) $this->context->language->id .
                Shop::addSqlRestrictionOnLang('pl') . '
             ORDER BY pl.`name` ASC
             LIMIT 1000'
        );

        $choices = array();

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $label = $row['name'] !== null && $row['name'] !== '' ? $row['name'] : ('#' . $row['id_product']);

                if ($row['reference'] !== '') {
                    $label .= ' (' . $row['reference'] . ')';
                }

                $choices[(int) $row['id_product']] = $label;
            }
        }

        return $choices;
    }

    private function cronUrl()
    {
        // Deliberately not forcing SSL: a shop without a certificate would be handed an
        // https URL its own cron could not call.
        return $this->context->link->getModuleLink(
            $this->name,
            'cron',
            array('token' => Configuration::get('PRESTASHOPAPI_CRON_TOKEN'))
        );
    }

    /* ================================================================ *
     * Settings form
     * ================================================================ */

    private function renderSettingsForm()
    {
        $ttl_options = array();

        foreach (self::TTL_CHOICES as $minutes) {
            $ttl_options[] = array(
                'id' => $minutes,
                'name' => $minutes < 60
                    ? sprintf($this->l('%d minutes'), $minutes)
                    : sprintf($this->l('%d hour(s)'), $minutes / 60),
            );
        }

        $fields = array(
            'form' => array(
                'legend' => array('title' => $this->l('Settings'), 'icon' => 'icon-cogs'),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('API key'),
                        'name' => 'PRESTASHOPAPI_KEY',
                        'desc' => $this->l('Seller account > Settings > API > "Get my API key". Paste only the key itself.'),
                        'autocomplete' => 'off',
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Reporting period'),
                        'name' => 'PRESTASHOPAPI_PERIOD',
                        'desc' => $this->l('How far back to pull marketplace sales. A wider window means a slower refresh.'),
                        'options' => array(
                            'query' => array(
                                array('id' => '30d', 'name' => $this->l('Last 30 days')),
                                array('id' => '3m', 'name' => $this->l('Last 3 months')),
                                array('id' => '6m', 'name' => $this->l('Last 6 months')),
                                array('id' => '12m', 'name' => $this->l('Last 12 months')),
                                array('id' => 'ytd', 'name' => $this->l('This year')),
                                array('id' => 'all', 'name' => $this->l('All time')),
                                array('id' => 'custom', 'name' => $this->l('Custom dates')),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('From'),
                        'name' => 'PRESTASHOPAPI_DATE_FROM',
                        'class' => 'psapi-custom-date',
                        'desc' => $this->l('Only used when the period is set to "Custom dates". Format: YYYY-MM-DD.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('To'),
                        'name' => 'PRESTASHOPAPI_DATE_TO',
                        'class' => 'psapi-custom-date',
                        'desc' => $this->l('Leave empty for today. Format: YYYY-MM-DD.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Marketplace currency'),
                        'name' => 'PRESTASHOPAPI_CURRENCY',
                        'desc' => $this->l('The currency your marketplace payouts are made in, as a three-letter ISO code. Used to convert marketplace revenue into your shop currency.'),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Refresh data every'),
                        'name' => 'PRESTASHOPAPI_CACHE_TTL',
                        'desc' => $this->l('Marketplace data is cached for this long. Shorter means fresher figures and more API calls.'),
                        'options' => array('query' => $ttl_options, 'id' => 'id', 'name' => 'name'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Show a sales badge on product pages'),
                        'name' => 'PRESTASHOPAPI_BADGE_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Displays how many times the product has been downloaded, as social proof for visitors of your own shop.'),
                        'values' => array(
                            array('id' => 'badge_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'badge_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Badge counts'),
                        'name' => 'PRESTASHOPAPI_BADGE_SCOPE',
                        'options' => array(
                            'query' => array(
                                array('id' => 'total', 'name' => $this->l('Marketplace + this shop')),
                                array('id' => 'addons', 'name' => $this->l('Marketplace only')),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Hide the badge below'),
                        'name' => 'PRESTASHOPAPI_BADGE_MIN',
                        'suffix' => $this->l('sales'),
                        'desc' => $this->l('Social proof works against you at low numbers. Products under this figure show no badge.'),
                    ),
                ),
                'submit' => array('title' => $this->l('Save')),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPrestashopAPISettings';
        $helper->currentIndex = $this->configUrl();
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($fields));
    }

    /**
     * v1 read these with Configuration::get($key, true). The second argument is $id_lang, not
     * a default value, so the lookup missed and the switch always rendered as "No" no matter
     * what had been saved.
     */
    private function getFormValues()
    {
        $values = array();

        $fields = array(
            'PRESTASHOPAPI_KEY', 'PRESTASHOPAPI_PERIOD', 'PRESTASHOPAPI_DATE_FROM',
            'PRESTASHOPAPI_DATE_TO', 'PRESTASHOPAPI_CURRENCY', 'PRESTASHOPAPI_CACHE_TTL',
            'PRESTASHOPAPI_BADGE_ENABLED', 'PRESTASHOPAPI_BADGE_SCOPE', 'PRESTASHOPAPI_BADGE_MIN',
        );

        foreach ($fields as $field) {
            // A successful save redirects, so a value is only in the request when validation
            // failed. Echoing it back means a rejected form does not also lose the typing.
            $values[$field] = Tools::getValue($field, Configuration::get($field));
        }

        $values['PRESTASHOPAPI_CACHE_TTL'] = (int) $values['PRESTASHOPAPI_CACHE_TTL'];
        $values['PRESTASHOPAPI_BADGE_MIN'] = (int) $values['PRESTASHOPAPI_BADGE_MIN'];
        $values['PRESTASHOPAPI_BADGE_ENABLED'] = (bool) $values['PRESTASHOPAPI_BADGE_ENABLED'];

        return $values;
    }

    /* ================================================================ *
     * Hooks
     * ================================================================ */

    /**
     * Tells the merchant on the back-office Dashboard that buyers are waiting.
     *
     * Reads the cache only: this hook fires on every Dashboard page load, and an admin page
     * must never block on the marketplace. If nothing has been downloaded yet, it says nothing.
     */
    public function hookDisplayDashboardTop()
    {
        try {
            $client = $this->client(false);

            if (!$client->hasKey()) {
                return '';
            }

            $threads = $client->getThreads();

            if (!is_array($threads) || !$threads) {
                return '';
            }

            $unread = $this->unreadThreads($threads);

            if (!$unread) {
                return '';
            }

            $this->context->smarty->assign(array(
                'psapi_unread' => count($unread),
                'psapi_messages_url' => $this->configUrl() . '#psapi-messages',
            ));

            return $this->context->smarty->fetch($this->local_path . 'views/templates/admin/dashboard.tpl');
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('PrestashopAPI dashboard: ' . $e->getMessage(), 2);

            return '';
        } catch (Exception $e) {
            PrestaShopLogger::addLog('PrestashopAPI dashboard: ' . $e->getMessage(), 2);

            return '';
        }
    }

    public function hookActionFrontControllerSetMedia()
    {
        if (!$this->badgeApplies()) {
            return;
        }

        $this->context->controller->registerStylesheet(
            'psapi-front',
            'modules/' . $this->name . '/views/css/front.css',
            array('media' => 'all', 'priority' => 150)
        );
    }

    /**
     * Renders the social-proof badge on the product page.
     *
     * v1 did this on displayFooter with a jQuery Growl toast that popped up unprompted on
     * every visit, and computed the number with two live API calls per page view.
     */
    public function hookDisplayProductAdditionalInfo($params)
    {
        $id_product = 0;

        try {
            if (!$this->badgeApplies()) {
                return '';
            }

            if (isset($params['product'])) {
                $product = $params['product'];

                // The classic theme passes a ProductLazyArray (ArrayAccess); other callers
                // pass a plain array or a Product object. Testing isset($x['k']) on an object
                // that does not implement ArrayAccess is a fatal Error, not a false, so the
                // type has to be established before subscripting.
                if (is_array($product) || $product instanceof ArrayAccess) {
                    $id_product = isset($product['id_product']) ? (int) $product['id_product'] : 0;
                } elseif (is_object($product) && isset($product->id)) {
                    $id_product = (int) $product->id;
                }
            }

            if (!$id_product) {
                $id_product = (int) Tools::getValue('id_product');
            }

            if (!$id_product) {
                return '';
            }

            $units = $this->badgeUnits($id_product);

            if ($units < (int) Configuration::get('PRESTASHOPAPI_BADGE_MIN')) {
                return '';
            }

            $this->smarty->assign(array(
                'psapi_units' => $units,
                'psapi_units_formatted' => number_format($units, 0, '.', ' '),
            ));

            return $this->display(__FILE__, 'views/templates/front/salesbadge.tpl');
        } catch (Throwable $e) {
            // An Error is not an Exception; without this the product page would white-screen.
            PrestaShopLogger::addLog('PrestashopAPI badge: ' . $e->getMessage(), 2, null, 'Product', $id_product);

            return '';
        } catch (Exception $e) {
            PrestaShopLogger::addLog('PrestashopAPI badge: ' . $e->getMessage(), 2);

            return '';
        }
    }

    private function badgeApplies()
    {
        return (bool) Configuration::get('PRESTASHOPAPI_BADGE_ENABLED')
            && $this->context->controller instanceof ProductControllerCore;
    }

    /**
     * Reads the precomputed badge map. No query, no HTTP call: Configuration is already in
     * memory by the time a hook runs.
     */
    private function badgeUnits($id_product)
    {
        $product = new Product($id_product);

        if (!Validate::isLoadedObject($product)) {
            return 0;
        }

        $marketplace_id = $this->marketplaceIdFor($product);
        $units = 0;

        if ($marketplace_id !== '') {
            $map = json_decode((string) Configuration::get('PRESTASHOPAPI_BADGE_MAP'), true);

            if (is_array($map) && isset($map[$marketplace_id])) {
                $units = (int) $map[$marketplace_id];
            }
        }

        if (Configuration::get('PRESTASHOPAPI_BADGE_SCOPE') === 'total') {
            $units += (int) Db::getInstance()->getValue(
                'SELECT SUM(od.`product_quantity` - od.`product_quantity_refunded`)
                 FROM `' . _DB_PREFIX_ . 'order_detail` od
                 INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = od.`id_order`
                 WHERE o.`valid` = 1 AND od.`product_id` = ' . (int) $id_product
            );
        }

        return $units;
    }

    /**
     * A product is matched to the marketplace by its reference, unless the merchant has
     * pinned it explicitly on the Products tab.
     */
    private function marketplaceIdFor(Product $product)
    {
        foreach (SellerStats::getManualLinks() as $marketplace_id => $id_local) {
            if ((int) $id_local === (int) $product->id) {
                return (string) $marketplace_id;
            }
        }

        return trim((string) $product->reference);
    }
}
