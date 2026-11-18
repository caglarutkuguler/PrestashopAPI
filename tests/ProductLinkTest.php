<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * Guards the 2.5.0 product matching order: pinned link, then MPN, then reference.
 *
 * Runs without PrestaShop: `php tests/ProductLinkTest.php`. Db, Shop, Context and
 * Configuration are stubbed; the catalogue is an in-memory list of product rows.
 */
if (PHP_SAPI === 'cli' && !defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0');
}
if (!defined('_PS_VERSION_')) {
    exit;
}
define('_DB_PREFIX_', 'ps_');

function pSQL($v)
{
    return addslashes($v);
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
    public static function addSqlRestrictionOnLang($a)
    {
        return '';
    }
}

class Context
{
    public $language;

    public static function getContext()
    {
        $c = new self();
        $c->language = (object) array('id' => 1);

        return $c;
    }
}

class Db
{
    public static $hasMpn = true;
    public static $catalogue = array();

    public static function getInstance()
    {
        return new self();
    }

    public function executeS($sql)
    {
        if (strpos($sql, 'SHOW COLUMNS') === 0) {
            return self::$hasMpn ? array(array('Field' => 'mpn')) : array();
        }
        if (!preg_match('/WHERE p\.`(\w+)` IN \((.*)\)/s', $sql, $m)) {
            return array();
        }
        $field = $m[1];
        $wanted = array_map(function ($v) { return trim($v, '"'); }, explode(',', $m[2]));
        $out = array();
        foreach (self::$catalogue as $row) {
            if (in_array((string) $row[$field], $wanted, true)) {
                $out[] = $row + array('name' => 'P' . $row['id_product']);
            }
        }

        return $out;
    }

    public function getRow($sql)
    {
        preg_match('/id_product` = (\d+)/', $sql, $m);
        foreach (self::$catalogue as $row) {
            if ((int) $row['id_product'] === (int) $m[1]) {
                return $row + array('name' => 'P' . $row['id_product']);
            }
        }

        return false;
    }
}

require dirname(__FILE__) . '/../classes/SellerStats.php';

$failures = 0;
function check($label, $ok)
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        ++$failures;
    }
}

function links(array $marketplaceIds)
{
    $products = array();
    foreach ($marketplaceIds as $id) {
        $products[] = array('id_product' => $id);
    }
    $r = new ReflectionClass('SellerStats');
    $stats = $r->newInstanceWithoutConstructor();
    $p = $r->getProperty('products');
    $p->setAccessible(true);
    $p->setValue($stats, $products);
    $m = $r->getMethod('linkProducts');
    $m->setAccessible(true);
    $m->invoke($stats);
    $l = $r->getProperty('links');
    $l->setAccessible(true);
    $out = array();
    foreach ($l->getValue($stats) as $k => $row) {
        $out[$k] = (int) $row['id_product'];
    }

    return $out;
}

Db::$catalogue = array(
    array('id_product' => 31, 'reference' => 'MEG-PS-PRO-PRICEBYSURFACE', 'mpn' => '8972'),
    array('id_product' => 77, 'reference' => 'MEG-PS-PRO-COLORSWATCHES', 'mpn' => '4792'),
    array('id_product' => 3, 'reference' => '4792', 'mpn' => ''),
    array('id_product' => 52, 'reference' => '43699', 'mpn' => ''),
    array('id_product' => 99, 'reference' => 'X', 'mpn' => ''),
);

$l = links(array('8972', '4792', '43699', '11111'));
check('MPN match with a non-numeric reference', isset($l['8972']) && $l['8972'] === 31);
check('MPN wins over another product\'s reference', $l['4792'] === 77);
check('empty MPN still matches by reference', $l['43699'] === 52);
check('unknown marketplace id stays unmatched', !isset($l['11111']));

Configuration::$store['PRESTASHOPAPI_LINKS'] = json_encode(array('11111' => 99, '4792' => 3));
$l = links(array('4792', '11111'));
check('pinned link wins over MPN', $l['4792'] === 3);
check('pinned link for an id with no MPN/reference', $l['11111'] === 99);
Configuration::$store = array();

Db::$hasMpn = false;
$r = new ReflectionClass('SellerStats');
// hasMpnColumn() caches per request; a shop without the column is a fresh request.
$l = links(array('4792'));
check('MPN column check is cached per request (1.7.7+ shop)', $l['4792'] === 77);

echo $failures ? "\n$failures failure(s)\n" : "\nall passed\n";
exit($failures ? 1 : 0);
