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
 * Refreshes the cached marketplace data from a scheduled task.
 *
 * The storefront badge deliberately never calls the API itself, so without a cron the figure
 * only moves when someone opens the module in the back office. This endpoint is what keeps it
 * current on a shop whose owner rarely logs in.
 */
class PrestashopAPICronModuleFrontController extends ModuleFrontController
{
    /**
     * Everything happens in postProcess, which the front controller runs before initContent,
     * so the response is emitted and the request ended without the theme ever rendering.
     */
    public function postProcess()
    {
        $expected = (string) Configuration::get('PRESTASHOPAPI_CRON_TOKEN');
        $given = (string) Tools::getValue('token');

        // hash_equals keeps the comparison time-independent, so the token cannot be guessed
        // byte by byte by timing the responses.
        if ($expected === '' || !hash_equals($expected, $given)) {
            header('HTTP/1.1 403 Forbidden');
            $this->respond(array('success' => false, 'error' => 'Invalid token.'));
        }

        $dates = PrestashopAPI::resolvePeriod();
        $client = new SellerApiClient(true, $dates[0], $dates[1]);

        if (!$client->hasKey()) {
            $this->respond(array('success' => false, 'error' => 'No API key configured.'));
        }

        $products = $client->getProducts(true);
        $sales = $client->getOrders(true);
        $client->getThreads(true);
        $client->getInvoices(true);

        if ($sales === false) {
            header('HTTP/1.1 502 Bad Gateway');
            $this->respond(array('success' => false, 'error' => $client->getLastError()));
        }

        $this->rebuildBadgeMap($sales);

        $this->respond(array(
            'success' => true,
            'products' => is_array($products) ? count($products) : 0,
            'sales' => count($sales),
            'date' => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * Mirrors PrestashopAPI::rebuildBadgeMap(). Kept here rather than shared because the
     * module class is not necessarily instantiable in a cron context on every PrestaShop
     * version, and this is a handful of lines.
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
            $refunded = (int) (isset($sale['product_quantity_refunded']) ? $sale['product_quantity_refunded'] : 0);

            if (!isset($map[$id])) {
                $map[$id] = 0;
            }

            $map[$id] += $qty - $refunded;
        }

        Configuration::updateValue('PRESTASHOPAPI_BADGE_MAP', json_encode($map));
    }

    private function respond(array $payload)
    {
        header('Content-Type: application/json');
        header('X-Robots-Tag: noindex, nofollow');
        echo json_encode($payload);
        exit;
    }
}
