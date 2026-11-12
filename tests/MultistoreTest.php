<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * Multistore checks for 2.3.0. Runs without PrestaShop: `php tests/MultistoreTest.php`.
 * Configuration is stubbed as a [key][shop] table (shop 0 = the global row) and Shop as a
 * switch, so the account-level read/write rules and the upgrade promotion can be exercised;
 * the fixed queries are checked as source.
 */
if (PHP_SAPI === 'cli' && !defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0'); // standalone run outside PrestaShop
}
if (!defined('_PS_VERSION_')) {
    exit;
}
define('_DB_PREFIX_', 'ps_');

class Configuration
{
    public static $rows = array();
    public static $context_shop = 1;

    public static function get($k, $l = null, $g = null, $s = null, $d = false)
    {
        if ($s === null) {
            $s = self::$context_shop;
        }
        $s = (int) $s;

        if (isset(self::$rows[$k][$s])) {
            return self::$rows[$k][$s];
        }

        return isset(self::$rows[$k][0]) ? self::$rows[$k][0] : false;
    }

    public static function updateValue($k, $v, $html = false, $g = null, $s = null)
    {
        if ($s === null) {
            $s = self::$context_shop;
        }
        self::$rows[$k][(int) $s] = (string) $v;

        return true;
    }
}

class Shop
{
    public static $active = false;

    public static function isFeatureActive()
    {
        return self::$active;
    }
}

class Db
{
    public static $rows = array();

    public static function getInstance()
    {
        return new self();
    }

    public function executeS($sql)
    {
        return self::$rows;
    }
}

function pSQL($s)
{
    return addslashes($s);
}

class Tools
{
    public static function substr($s, $a, $b = null)
    {
        return $b === null ? substr($s, $a) : substr($s, $a, $b);
    }

    public static function strtolower($s)
    {
        return strtolower($s);
    }
}

$base = dirname(__FILE__) . '/..';
require_once $base . '/classes/SellerApiClient.php';
require_once $base . '/upgrade/upgrade-2.3.0.php';

$fail = 0;
function check($label, $ok)
{
    global $fail;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        ++$fail;
    }
}

// --- account-level read / write -------------------------------------------------------
Configuration::$rows = array();
Shop::$active = false;
SellerApiClient::setAccountValue('K', 'a');
check('single shop: write goes to the context shop row exactly as before',
    Configuration::$rows['K'] === array(1 => 'a'));
check('single shop: read returns it', SellerApiClient::getAccountValue('K') === 'a');

Configuration::$rows = array('K' => array(1 => 'shop1', 2 => 'shop2'));
Shop::$active = true;
Configuration::$context_shop = 2;
check('multistore: no global row yet, a shop keeps its own older value',
    SellerApiClient::getAccountValue('K') === 'shop2');

SellerApiClient::setAccountValue('K', 'fresh');
check('multistore: write lands on the global row (shop 0)', Configuration::$rows['K'][0] === 'fresh');
check('multistore: the shop rows are not touched', Configuration::$rows['K'][1] === 'shop1' && Configuration::$rows['K'][2] === 'shop2');
Configuration::$context_shop = 1;
check('multistore: every shop now reads the global value', SellerApiClient::getAccountValue('K') === 'fresh');
SellerApiClient::setAccountValue('K', '{}');
check('multistore: a reset to {} is not hidden behind a stale shop value', SellerApiClient::getAccountValue('K') === '{}');

// --- promotion choice -----------------------------------------------------------------
check('promote: default shop wins', SellerApiClient::choosePromotedValue(array(1 => '{"a":1}', 2 => '{"b":2}'), 2) === '{"b":2}');
check('promote: empty default falls to lowest other', SellerApiClient::choosePromotedValue(array(3 => '{"c":3}', 2 => '{"b":2}', 1 => '{}'), 1) === '{"b":2}');
check('promote: nothing usable gives false', SellerApiClient::choosePromotedValue(array(1 => '{}', 2 => ''), 1) === false);
check('promote: dates take the newest', SellerApiClient::choosePromotedValue(array(1 => '2026-01-01 10:00:00', 2 => '2026-03-01 10:00:00'), 1, true) === '2026-03-01 10:00:00');

// --- upgrade script -------------------------------------------------------------------
Shop::$active = false;
Configuration::$rows = array('PRESTASHOPAPI_BADGE_MAP' => array(1 => '{"9":4}'));
upgrade_module_2_3_0(null);
check('upgrade: multistore off moves nothing', !isset(Configuration::$rows['PRESTASHOPAPI_BADGE_MAP'][0]));

Shop::$active = true;
Configuration::$rows = array('PS_SHOP_DEFAULT' => array(0 => '1'), 'PRESTASHOPAPI_BADGE_MAP' => array(0 => '{}', 2 => '{"9":4}'), 'PRESTASHOPAPI_LAST_SYNC' => array(2 => '2026-05-05 10:00:00'));
Db::$rows = array(array('id_shop' => 2, 'value' => '{"9":4}'));
upgrade_module_2_3_0(null);
check('upgrade: promotes the shop value over the empty install default', Configuration::$rows['PRESTASHOPAPI_BADGE_MAP'][0] === '{"9":4}');
check('upgrade: shop rows stay', Configuration::$rows['PRESTASHOPAPI_BADGE_MAP'][2] === '{"9":4}');
Configuration::$rows['PRESTASHOPAPI_BADGE_MAP'][0] = '{"9":7}';
Db::$rows = array(array('id_shop' => 2, 'value' => '{"9":4}'));
upgrade_module_2_3_0(null);
check('upgrade: a usable global value is never overwritten (idempotent)', Configuration::$rows['PRESTASHOPAPI_BADGE_MAP'][0] === '{"9":7}');

// --- source assertions ----------------------------------------------------------------
$main = file_get_contents($base . '/PrestashopAPI.php');
$stats = file_get_contents($base . '/classes/SellerStats.php');
$cron = file_get_contents($base . '/controllers/front/cron.php');

check('badge total query carries the shop condition', (bool) preg_match('/od\.`product_id` = \' \. \(int\) \$product->id\s*\. \$this->badgeShopCondition\(\)/', $main));
check('badge shop condition filters o.id_shop on the context shop only when multistore is active',
    strpos($main, "return ' AND o.`id_shop` = ' . (int) \$this->context->shop->id;") !== false
    && (bool) preg_match('/function badgeShopCondition\(\)\s*\{\s*if \(!Shop::isFeatureActive\(\)\) \{\s*return \'\';/', $main));
check('both product_lang joins of SellerStats are shop-restricted', substr_count($stats, "Shop::addSqlRestrictionOnLang('pl')") === 2);
check('badge map is read through the account accessor', strpos($main, "SellerApiClient::getAccountValue('PRESTASHOPAPI_BADGE_MAP')") !== false);
check('badge map is never written per shop any more',
    strpos($main, "Configuration::updateValue('PRESTASHOPAPI_BADGE_MAP'") === false
    && strpos($cron, "Configuration::updateValue('PRESTASHOPAPI_BADGE_MAP'") === false
    && strpos($cron, "SellerApiClient::setAccountValue('PRESTASHOPAPI_BADGE_MAP'") !== false
    && substr_count($main, "SellerApiClient::setAccountValue('PRESTASHOPAPI_BADGE_MAP'") === 2);
check('notice only outside the single-shop context', (bool) preg_match('/function renderShopContextNotice\(\)\s*\{\s*if \(!Shop::isFeatureActive\(\) \|\| Shop::getContext\(\) == Shop::CONTEXT_SHOP\) \{\s*return \'\';/', $main));
check('notice is rendered on the configure page', strpos($main, '$this->html .= $this->renderShopContextNotice();') !== false);
check('version is 2.3.0', strpos($main, "const MODULE_VERSION = '2.3.0';") !== false);
check('upgrade-2.3.0.php ships', is_file($base . '/upgrade/upgrade-2.3.0.php'));

echo $fail ? "\nFAILED: $fail\n" : "\nOK - all passed\n";
exit($fail ? 1 : 0);
