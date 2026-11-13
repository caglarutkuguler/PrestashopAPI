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
 * 2.3.0 -> 2.4.0: "waiting for you" follows the last message, not any change.
 *
 * No table changes. The cached last-message map is dropped because it has no read receipt
 * in it, and the conversation states are rebuilt once from the cached data: a conversation
 * is left waiting only when the buyer wrote last, the message is unseen and it is at most
 * 30 days old. Pins are kept.
 *
 * The rebuild is not required to succeed here. When nothing is cached yet it is skipped and
 * runs by itself on the next visit to the module, the next cron call or the next Dashboard
 * view, whichever comes first.
 *
 * @param Module $module
 *
 * @return bool
 */
function upgrade_module_2_4_0($module)
{
    require_once dirname(__FILE__) . '/../classes/SellerApiClient.php';
    require_once dirname(__FILE__) . '/../classes/SellerThreadState.php';

    SellerApiClient::purgeCache('last_by_thread');

    try {
        if (SellerThreadState::tableExists()) {
            $dates = PrestashopAPI::resolvePeriod();
            // Cache only: an upgrade must not wait on the marketplace.
            $client = new SellerApiClient(false, $dates[0], $dates[1]);
            $threads = $client->getThreads();

            if (is_array($threads) && $threads) {
                SellerThreadState::sync($threads, $client->getLastMessages());
            }
        }
    } catch (Exception $e) {
        PrestaShopLogger::addLog('PrestashopAPI upgrade 2.4.0: ' . $e->getMessage(), 2);
    }

    if (method_exists('Tools', 'clearSmartyCache')) {
        Tools::clearSmartyCache();
    }

    return true;
}
