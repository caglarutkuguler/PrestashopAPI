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
 * 2.2.0 -> 2.3.0: multistore support.
 *
 * The marketplace badge map and the last-sync date describe the seller account, not a
 * shop, and the cron URL used to write them for the shop it was called in only. From
 * 2.3.0 they are written installation-wide when multistore is active.
 *
 * Nothing is lost: when multistore is active and no installation-wide value exists yet
 * (or only the empty install default), the value a shop already holds is copied to the
 * installation-wide row. The shop-level rows are left where they are. With multistore off
 * nothing is touched. Safe to run twice: a usable installation-wide value is never
 * overwritten.
 *
 * @param Module $module
 *
 * @return bool
 */
function upgrade_module_2_3_0($module)
{
    require_once dirname(__FILE__) . '/../classes/SellerApiClient.php';

    if (!Shop::isFeatureActive()) {
        return true;
    }

    $default_shop = (int) Configuration::get('PS_SHOP_DEFAULT');
    $keys = array(
        'PRESTASHOPAPI_BADGE_MAP' => false,
        'PRESTASHOPAPI_LAST_SYNC' => true,
    );

    foreach ($keys as $key => $is_date) {
        $global = Configuration::get($key, null, 0, 0);

        if ($global !== false && $global !== '' && $global !== '{}') {
            continue;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT `id_shop`, `value` FROM `' . _DB_PREFIX_ . 'configuration`
             WHERE `name` = "' . pSQL($key) . '" AND `id_shop` IS NOT NULL AND `id_shop` > 0'
        );
        $by_shop = array();

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $by_shop[(int) $row['id_shop']] = (string) $row['value'];
            }
        }

        $value = SellerApiClient::choosePromotedValue($by_shop, $default_shop, $is_date);

        if ($value !== false) {
            Configuration::updateValue($key, $value, false, 0, 0);
        }
    }

    return true;
}
