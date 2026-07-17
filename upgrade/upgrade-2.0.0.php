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
 * Migrates a 1.x install to 2.0.0.
 *
 * A module upgrade extracts the new archive over the old folder, so every 1.x file that no
 * longer exists in 2.0.0 is still sitting on disk at this point and has to be deleted here.
 *
 * @param PrestashopAPI $module
 *
 * @return bool
 */
function upgrade_module_2_0_0($module)
{
    /* ---------------------------------------------------------------- *
     * Settings: 1.x stored three of its four keys unprefixed, in the global Configuration
     * namespace, where they could collide with any other module.
     * ---------------------------------------------------------------- */
    $renames = array(
        'API_DATE_FROM' => 'PRESTASHOPAPI_DATE_FROM',
        'API_DATE_TO' => 'PRESTASHOPAPI_DATE_TO',
        'PRODUCT_PAGE_FRONT_ENABLE' => 'PRESTASHOPAPI_BADGE_ENABLED',
    );

    foreach ($renames as $old => $new) {
        $value = Configuration::get($old);

        if ($value !== false && Configuration::get($new) === false) {
            Configuration::updateValue($new, $value);
        }

        Configuration::deleteByName($old);
    }

    /* ---------------------------------------------------------------- *
     * New settings
     * ---------------------------------------------------------------- */
    $date_from = (string) Configuration::get('PRESTASHOPAPI_DATE_FROM');
    $date_to = (string) Configuration::get('PRESTASHOPAPI_DATE_TO');

    $defaults = array(
        // Keep honouring the dates the merchant typed in 1.x, otherwise show a year.
        'PRESTASHOPAPI_PERIOD' => ($date_from !== '' || $date_to !== '') ? 'custom' : '12m',
        'PRESTASHOPAPI_CURRENCY' => 'EUR',
        'PRESTASHOPAPI_CACHE_TTL' => 60,
        'PRESTASHOPAPI_BADGE_SCOPE' => 'total',
        // 1.x showed the toast at any figure above zero. Social proof reads as weak below
        // roughly ten, so start there rather than inheriting the old behaviour.
        'PRESTASHOPAPI_BADGE_MIN' => 10,
        'PRESTASHOPAPI_LINKS' => '{}',
        'PRESTASHOPAPI_BADGE_MAP' => '{}',
    );

    foreach ($defaults as $key => $value) {
        if (Configuration::get($key) === false) {
            Configuration::updateValue($key, $value);
        }
    }

    if (!Configuration::get('PRESTASHOPAPI_CRON_TOKEN')) {
        Configuration::updateValue('PRESTASHOPAPI_CRON_TOKEN', Tools::passwdGen(32, 'ALPHANUMERIC'));
    }

    /* ---------------------------------------------------------------- *
     * 1.x dates were validated nowhere, so anything could be in there. A value the API
     * cannot parse silently collapses the reporting window, so drop what will not parse.
     * ---------------------------------------------------------------- */
    foreach (array('PRESTASHOPAPI_DATE_FROM', 'PRESTASHOPAPI_DATE_TO') as $key) {
        $value = trim((string) Configuration::get($key));

        if ($value !== '' && !Validate::isDate($value)) {
            Configuration::updateValue($key, '');
        }
    }

    /* ---------------------------------------------------------------- *
     * Schema
     * ---------------------------------------------------------------- */
    // 1.x created an empty table holding nothing but an auto-increment id, and never used
    // it. Its mixed-case name also breaks on MySQL with lower_case_table_names enabled.
    Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'PrestashopAPI`');

    // An early 2.0.0 build kept conversation read-state in a Configuration value. It has its
    // own table now: ps_configuration.value is TEXT, and 1843 conversations overflow 64KB.
    Configuration::deleteByName('PRESTASHOPAPI_SEEN');

    require_once dirname(__FILE__) . '/../classes/SellerApiClient.php';
    require_once dirname(__FILE__) . '/../classes/SellerThreadState.php';

    if (!SellerApiClient::installCache() || !SellerThreadState::install()) {
        return false;
    }

    /* ---------------------------------------------------------------- *
     * Hooks: 1.x registered backOfficeHeader and displayBackOfficeHeader, which are aliases
     * of each other, so its assets were injected twice on every back-office page.
     * ---------------------------------------------------------------- */
    foreach (array('footer', 'displayFooter', 'backOfficeHeader', 'displayBackOfficeHeader') as $hook) {
        $module->unregisterHook($hook);
    }

    $module->registerHook('actionFrontControllerSetMedia');
    $module->registerHook('displayProductAdditionalInfo');
    $module->registerHook('displayDashboardTop');

    /* ---------------------------------------------------------------- *
     * Stale 1.x files
     * ---------------------------------------------------------------- */
    $files = array(
        // The jQuery Growl toast the storefront notice used to need.
        'views/js/jquery.growl.js',
        'views/css/jquery.growl.css',
        // Four assets that only ever contained a licence header.
        'views/js/back.js',
        'views/js/front.js',
        'views/css/back.css',
        'views/templates/front/header.tpl',
        'views/templates/admin/tabs.tpl',
        // The unused table's install/uninstall scripts, never called by 1.x anyway.
        'sql/install.php',
        'sql/uninstall.php',
        'sql/index.php',
        // An upgrade to a version that was never released, whose body was a return true.
        'upgrade/upgrade-1.1.0.php',
        // 1.5-era module icon.
        'logo.gif',
        // Replaced by classes/SellerApiClient.php.
        'classes/SellerApi.php',
    );

    foreach ($files as $file) {
        $path = dirname(__FILE__) . '/../' . $file;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    $sql_dir = dirname(__FILE__) . '/../sql';

    if (is_dir($sql_dir)) {
        @rmdir($sql_dir);
    }

    return true;
}
