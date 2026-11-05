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
 * 2.1.2 -> 2.2.0: conversations sorted by their last message.
 *
 * No table changes. The cached conversations and the cached bulk messages are
 * dropped so the next refresh reads page 1 of the messages endpoint in its new
 * form (no bodies) and builds the last-message table from it. The compiled
 * templates are cleared because configure.tpl changed and a shop set never to
 * recompile would keep serving the old one.
 *
 * @param Module $module
 *
 * @return bool
 */
function upgrade_module_2_2_0($module)
{
    require_once dirname(__FILE__) . '/../classes/SellerApiClient.php';

    SellerApiClient::purgeCache('threads');
    SellerApiClient::purgeCache('allmessages');

    if (method_exists('Tools', 'clearSmartyCache')) {
        Tools::clearSmartyCache();
    }

    return true;
}
