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
 * Turns raw Seller API rows plus this shop's own order lines into the figures shown on the
 * dashboard.
 *
 * Every method tolerates missing keys. The API is not versioned and we do not control it, so
 * a field that disappears must degrade to zero rather than fatal a back-office page.
 */
class SellerStats
{
    /** @var string Marketplace payouts are denominated in euros unless told otherwise. */
    const DEFAULT_MARKETPLACE_CURRENCY = 'EUR';

    /** @var string Base used to resolve any relative thumbnail path the API returns. */
    const MARKETPLACE_URL = 'https://addons.prestashop.com';

    /** @var array Raw sales rows from seller/orders. */
    private $sales;

    /** @var array Raw product rows from seller/products. */
    private $products;

    /** @var array id_product (marketplace) => local product row. */
    private $links = array();

    /** @var array Local sales keyed by local id_product. */
    private $local = array();

    /** @var float|null Rate to multiply marketplace amounts by, or null when not convertible. */
    private $rate = 1.0;

    /** @var bool True when marketplace and shop currencies differ and no rate exists. */
    private $currency_mismatch = false;

    /** @var string */
    private $marketplace_iso;

    /** @var string */
    private $shop_iso;

    public function __construct(array $sales = array(), array $products = array())
    {
        $this->sales = $sales;
        $this->products = $products;

        $this->resolveCurrency();
        $this->linkProducts();
        $this->loadLocalSales();
    }

    /* ---------------------------------------------------------------- *
     * Currency
     *
     * v1 added marketplace turnover to shop turnover unconditionally and printed a warning
     * telling the merchant to assume the currencies matched. It also called money_format(),
     * removed in PHP 8, so the figure was a fatal error on any current server.
     * ---------------------------------------------------------------- */

    private function resolveCurrency()
    {
        $configured = Tools::strtoupper(trim((string) Configuration::get('PRESTASHOPAPI_CURRENCY')));
        $this->marketplace_iso = $configured !== '' ? $configured : self::DEFAULT_MARKETPLACE_CURRENCY;

        $default = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $this->shop_iso = Validate::isLoadedObject($default) ? Tools::strtoupper($default->iso_code) : '';

        if ($this->shop_iso === '' || $this->shop_iso === $this->marketplace_iso) {
            $this->rate = 1.0;

            return;
        }

        $id_currency = (int) Currency::getIdByIsoCode($this->marketplace_iso);

        if ($id_currency) {
            $currency = new Currency($id_currency);

            // conversion_rate is expressed against the shop's default currency, which is 1.
            if (Validate::isLoadedObject($currency) && (float) $currency->conversion_rate > 0) {
                $this->rate = 1 / (float) $currency->conversion_rate;

                return;
            }
        }

        // The shop has no exchange rate for the marketplace currency, so the two figures
        // genuinely cannot be summed. Report it instead of quietly adding euros to dollars.
        $this->rate = null;
        $this->currency_mismatch = true;
    }

    public function hasCurrencyMismatch()
    {
        return $this->currency_mismatch;
    }

    public function getMarketplaceIso()
    {
        return $this->marketplace_iso;
    }

    public function getShopIso()
    {
        return $this->shop_iso;
    }

    /**
     * @return float|null Marketplace amount expressed in the shop's default currency.
     */
    public function toShopCurrency($amount)
    {
        return $this->rate === null ? null : (float) $amount * $this->rate;
    }

    /* ---------------------------------------------------------------- *
     * Product linking
     *
     * v1 required the merchant to set every local product's reference to the marketplace
     * product id, and said so in a blue info box. That is an instruction, not a control, so
     * a typo produced silently wrong totals. Now the match is reported and can be overridden.
     * ---------------------------------------------------------------- */

    private function linkProducts()
    {
        if (!$this->products) {
            return;
        }

        $overrides = self::getManualLinks();
        $ids = array();

        foreach ($this->products as $product) {
            $ids[] = (string) (isset($product['id_product']) ? $product['id_product'] : '');
        }

        $ids = array_filter(array_unique($ids), 'strlen');

        if (!$ids) {
            return;
        }

        $escaped = array();

        foreach ($ids as $id) {
            $escaped[] = '"' . pSQL($id) . '"';
        }

        $rows = Db::getInstance()->executeS(
            'SELECT p.`id_product`, p.`reference`, pl.`name`
             FROM `' . _DB_PREFIX_ . 'product` p
             LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.`id_product` = p.`id_product`
               AND pl.`id_lang` = ' . (int) Context::getContext()->language->id .
                Shop::addSqlRestrictionOnLang('pl') . '
             WHERE p.`reference` IN (' . implode(',', $escaped) . ')'
        );

        if (is_array($rows)) {
            foreach ($rows as $row) {
                // Match on the exact string. v1 used ==, which on PHP 5/7 made every
                // non-numeric reference equal to marketplace id 0.
                $this->links[(string) $row['reference']] = $row;
            }
        }

        foreach ($overrides as $marketplace_id => $id_local) {
            $row = Db::getInstance()->getRow(
                'SELECT p.`id_product`, p.`reference`, pl.`name`
                 FROM `' . _DB_PREFIX_ . 'product` p
                 LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                    ON pl.`id_product` = p.`id_product`
                   AND pl.`id_lang` = ' . (int) Context::getContext()->language->id . '
                 WHERE p.`id_product` = ' . (int) $id_local
            );

            if ($row) {
                $this->links[(string) $marketplace_id] = $row;
            }
        }
    }

    /**
     * @return array Marketplace product id => local id_product.
     */
    public static function getManualLinks()
    {
        $raw = Configuration::get('PRESTASHOPAPI_LINKS');
        $decoded = $raw ? json_decode($raw, true) : array();

        return is_array($decoded) ? $decoded : array();
    }

    public static function setManualLinks(array $links)
    {
        $clean = array();

        foreach ($links as $marketplace_id => $id_local) {
            $marketplace_id = trim((string) $marketplace_id);

            if ($marketplace_id !== '' && (int) $id_local > 0) {
                $clean[$marketplace_id] = (int) $id_local;
            }
        }

        return Configuration::updateValue('PRESTASHOPAPI_LINKS', json_encode($clean));
    }

    /**
     * @return array{linked: int, total: int} How many marketplace products resolve to a local one.
     */
    public function getLinkHealth()
    {
        $linked = 0;

        foreach ($this->products as $product) {
            $id = (string) (isset($product['id_product']) ? $product['id_product'] : '');

            if ($id !== '' && isset($this->links[$id])) {
                ++$linked;
            }
        }

        return array('linked' => $linked, 'total' => count($this->products));
    }

    /* ---------------------------------------------------------------- *
     * Local sales
     * ---------------------------------------------------------------- */

    /**
     * v1's version of this query was:
     *
     *   FROM order_detail a, product b WHERE a.product_id = <id>
     *
     * with no join condition, producing a cartesian product of every order line against
     * every product row. It then multiplied total_price_tax_incl (already a line total) by
     * the quantity again, and subtracted a product_quantity_refunded column it had never
     * selected. Sales counted invalid and abandoned orders too.
     */
    private function loadLocalSales()
    {
        $shops = Shop::getContextListShopID();
        $restriction = $shops ? ' AND o.`id_shop` IN (' . implode(',', array_map('intval', $shops)) . ')' : '';

        $rows = Db::getInstance()->executeS(
            'SELECT od.`product_id`,
                    SUM(od.`product_quantity` - od.`product_quantity_refunded`) AS `qty`,
                    SUM((od.`product_quantity` - od.`product_quantity_refunded`)
                        * od.`unit_price_tax_excl` / o.`conversion_rate`) AS `revenue`
             FROM `' . _DB_PREFIX_ . 'order_detail` od
             INNER JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = od.`id_order`
             WHERE o.`valid` = 1' . $restriction . '
             GROUP BY od.`product_id`'
        );

        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $this->local[(int) $row['product_id']] = array(
                'qty' => (int) $row['qty'],
                'revenue' => (float) $row['revenue'],
            );
        }
    }

    /* ---------------------------------------------------------------- *
     * Aggregation
     * ---------------------------------------------------------------- */

    /**
     * Marketplace totals per product id, derived from the sales rows.
     *
     * @return array id_product => array{units: int, refunded: int, revenue: float}
     */
    private function marketplaceTotals()
    {
        $totals = array();

        foreach ($this->sales as $sale) {
            $id = (string) (isset($sale['id_product']) ? $sale['id_product'] : '');

            if ($id === '') {
                continue;
            }

            if (!isset($totals[$id])) {
                $totals[$id] = array('units' => 0, 'refunded' => 0, 'revenue' => 0.0);
            }

            $qty = (int) (isset($sale['product_quantity']) ? $sale['product_quantity'] : 0);
            $refunded = (int) (isset($sale['product_quantity_refunded']) ? $sale['product_quantity_refunded'] : 0);
            $amount = (float) (isset($sale['amount']) ? $sale['amount'] : 0);

            $totals[$id]['units'] += $qty - $refunded;
            $totals[$id]['refunded'] += $refunded;
            $totals[$id]['revenue'] += ($qty - $refunded) * $amount;
        }

        return $totals;
    }

    /**
     * One row per marketplace product, with its local counterpart merged in.
     *
     * @return array
     */
    public function getProductRows()
    {
        $totals = $this->marketplaceTotals();
        $rows = array();

        foreach ($this->products as $product) {
            $id = (string) (isset($product['id_product']) ? $product['id_product'] : '');
            $marketplace = isset($totals[$id])
                ? $totals[$id]
                : array('units' => 0, 'refunded' => 0, 'revenue' => 0.0);

            $link = isset($this->links[$id]) ? $this->links[$id] : null;
            $local = array('qty' => 0, 'revenue' => 0.0);

            if ($link !== null && isset($this->local[(int) $link['id_product']])) {
                $local = $this->local[(int) $link['id_product']];
            }

            $marketplace_converted = $this->toShopCurrency($marketplace['revenue']);

            $rows[] = array(
                'id_product' => $id,
                'name' => isset($product['name']) ? $product['name'] : '',
                'pico' => self::normalizePicture(isset($product['pico']) ? $product['pico'] : ''),
                'price' => isset($product['price']) ? (float) $product['price'] : 0.0,
                'status' => isset($product['statut']) ? $product['statut'] : '',
                'addons_units' => $marketplace['units'],
                'addons_refunded' => $marketplace['refunded'],
                'addons_revenue' => $marketplace['revenue'],
                'local_units' => $local['qty'],
                'local_revenue' => $local['revenue'],
                'local_id' => $link !== null ? (int) $link['id_product'] : 0,
                'local_name' => $link !== null ? $link['name'] : '',
                'linked' => $link !== null,
                'total_units' => $marketplace['units'] + $local['qty'],
                // Null when the two currencies cannot be reconciled, so the template can say
                // so rather than print a number that means nothing.
                'total_revenue' => $marketplace_converted === null
                    ? null
                    : $marketplace_converted + $local['revenue'],
            );
        }

        usort($rows, array('SellerStats', 'sortByUnits'));

        return $rows;
    }

    public static function sortByUnits($a, $b)
    {
        if ($a['total_units'] === $b['total_units']) {
            return 0;
        }

        return $a['total_units'] < $b['total_units'] ? 1 : -1;
    }

    /**
     * Headline numbers for the dashboard cards.
     *
     * @return array
     */
    public function getSummary()
    {
        $units = 0;
        $refunded = 0;
        $revenue = 0.0;
        $refund_value = 0.0;
        $orders = array();
        $last_30 = 0.0;
        $cutoff = strtotime('-30 days');

        foreach ($this->sales as $sale) {
            $qty = (int) (isset($sale['product_quantity']) ? $sale['product_quantity'] : 0);
            $ref = (int) (isset($sale['product_quantity_refunded']) ? $sale['product_quantity_refunded'] : 0);
            $amount = (float) (isset($sale['amount']) ? $sale['amount'] : 0);
            $net = ($qty - $ref) * $amount;

            $units += $qty - $ref;
            $refunded += $ref;
            $revenue += $net;
            $refund_value += $ref * $amount;

            if (isset($sale['id_order']) && $sale['id_order'] !== '') {
                $orders[(string) $sale['id_order']] = true;
            }

            $timestamp = $this->saleTimestamp($sale);

            if ($timestamp !== null && $timestamp >= $cutoff) {
                $last_30 += $net;
            }
        }

        $local_units = 0;
        $local_revenue = 0.0;

        foreach ($this->links as $link) {
            if (isset($this->local[(int) $link['id_product']])) {
                $local_units += $this->local[(int) $link['id_product']]['qty'];
                $local_revenue += $this->local[(int) $link['id_product']]['revenue'];
            }
        }

        $order_count = count($orders);
        $converted = $this->toShopCurrency($revenue);

        return array(
            'addons_units' => $units,
            'addons_revenue' => $revenue,
            'addons_refunded' => $refunded,
            'addons_refund_value' => $refund_value,
            'addons_orders' => $order_count,
            'addons_last30' => $last_30,
            'addons_average' => $order_count > 0 ? $revenue / $order_count : 0.0,
            'local_units' => $local_units,
            'local_revenue' => $local_revenue,
            'total_units' => $units + $local_units,
            'total_revenue' => $converted === null ? null : $converted + $local_revenue,
            'product_count' => count($this->products),
        );
    }

    /**
     * Revenue and units per calendar month, oldest first, with empty months filled in so the
     * chart keeps a linear time axis.
     *
     * @return array
     */
    public function getMonthlySeries($months = 12)
    {
        $buckets = array();

        for ($i = $months - 1; $i >= 0; --$i) {
            $key = date('Y-m', strtotime('-' . $i . ' months'));
            $buckets[$key] = array('label' => $key, 'revenue' => 0.0, 'units' => 0);
        }

        foreach ($this->sales as $sale) {
            $timestamp = $this->saleTimestamp($sale);

            if ($timestamp === null) {
                continue;
            }

            $key = date('Y-m', $timestamp);

            if (!isset($buckets[$key])) {
                continue;
            }

            $qty = (int) (isset($sale['product_quantity']) ? $sale['product_quantity'] : 0);
            $ref = (int) (isset($sale['product_quantity_refunded']) ? $sale['product_quantity_refunded'] : 0);
            $amount = (float) (isset($sale['amount']) ? $sale['amount'] : 0);

            $buckets[$key]['revenue'] += ($qty - $ref) * $amount;
            $buckets[$key]['units'] += $qty - $ref;
        }

        return array_values($buckets);
    }

    /**
     * Units per buyer country, best first.
     *
     * @return array
     */
    public function getCountryBreakdown($limit = 10)
    {
        $countries = array();

        foreach ($this->sales as $sale) {
            $iso = Tools::strtoupper(trim((string) (isset($sale['iso_code']) ? $sale['iso_code'] : '')));

            if ($iso === '') {
                continue;
            }

            $qty = (int) (isset($sale['product_quantity']) ? $sale['product_quantity'] : 0);
            $ref = (int) (isset($sale['product_quantity_refunded']) ? $sale['product_quantity_refunded'] : 0);
            $amount = (float) (isset($sale['amount']) ? $sale['amount'] : 0);

            if (!isset($countries[$iso])) {
                $countries[$iso] = array('iso' => $iso, 'units' => 0, 'revenue' => 0.0);
            }

            $countries[$iso]['units'] += $qty - $ref;
            $countries[$iso]['revenue'] += ($qty - $ref) * $amount;
        }

        usort($countries, array('SellerStats', 'sortByUnitsSimple'));

        return array_slice($countries, 0, (int) $limit);
    }

    public static function sortByUnitsSimple($a, $b)
    {
        if ($a['units'] === $b['units']) {
            return 0;
        }

        return $a['units'] < $b['units'] ? 1 : -1;
    }

    /**
     * The API exposes both a sortable date and a display string, and older rows have been
     * seen with only one of them populated.
     *
     * @return int|null
     */
    private function saleTimestamp(array $sale)
    {
        foreach (array('order_date', 'order_date_display') as $field) {
            if (empty($sale[$field])) {
                continue;
            }

            $timestamp = strtotime((string) $sale[$field]);

            if ($timestamp !== false && $timestamp > 0) {
                return $timestamp;
            }
        }

        return null;
    }

    /**
     * Resolves the thumbnail the API returns in `pico` into a URL a browser will actually load.
     *
     * The field has no documented contract, and v1 dropped it into src="" untouched. Three
     * things break that:
     *  - a plain-http URL is blocked as mixed content before it is ever requested, because the
     *    back office is normally served over https;
     *  - a protocol-relative or root-relative path resolves against the shop's own domain,
     *    where the image does not exist;
     *  - anything that is not a URL at all still renders a broken-image icon.
     *
     * @return string Absolute https URL, or '' when the value cannot be one.
     */
    public static function normalizePicture($url)
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        if (Tools::substr($url, 0, 2) === '//') {
            $url = 'https:' . $url;
        } elseif (Tools::strtolower(Tools::substr($url, 0, 7)) === 'http://') {
            $url = 'https://' . Tools::substr($url, 7);
        } elseif (Tools::strtolower(Tools::substr($url, 0, 8)) !== 'https://') {
            $url = self::MARKETPLACE_URL . '/' . ltrim($url, '/');
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    /**
     * Total marketplace units for one marketplace product id. Used by the storefront badge.
     *
     * @return int
     */
    public static function unitsForProduct(array $sales, $id_product)
    {
        $units = 0;
        $id_product = (string) $id_product;

        foreach ($sales as $sale) {
            if (!isset($sale['id_product']) || (string) $sale['id_product'] !== $id_product) {
                continue;
            }

            $qty = (int) (isset($sale['product_quantity']) ? $sale['product_quantity'] : 0);
            $ref = (int) (isset($sale['product_quantity_refunded']) ? $sale['product_quantity_refunded'] : 0);
            $units += $qty - $ref;
        }

        return $units;
    }

    /**
     * Formats an amount in the marketplace currency.
     *
     * Tools::displayPrice() was removed in PrestaShop 9, so prefer the locale service and
     * only fall back when it is genuinely absent.
     */
    public static function formatMarketplacePrice($amount, $iso = null)
    {
        $iso = $iso === null ? self::DEFAULT_MARKETPLACE_CURRENCY : $iso;
        $context = Context::getContext();

        if (method_exists($context, 'getCurrentLocale') && $context->getCurrentLocale()) {
            return $context->getCurrentLocale()->formatPrice($amount, $iso);
        }

        if (method_exists('Tools', 'displayPrice')) {
            $id_currency = (int) Currency::getIdByIsoCode($iso);

            return Tools::displayPrice($amount, $id_currency ? $id_currency : null);
        }

        return number_format((float) $amount, 2) . ' ' . $iso;
    }

    /**
     * Formats an amount in the shop's default currency.
     */
    public static function formatShopPrice($amount)
    {
        $context = Context::getContext();
        $default = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        $iso = Validate::isLoadedObject($default) ? $default->iso_code : 'EUR';

        if (method_exists($context, 'getCurrentLocale') && $context->getCurrentLocale()) {
            return $context->getCurrentLocale()->formatPrice($amount, $iso);
        }

        if (method_exists('Tools', 'displayPrice')) {
            return Tools::displayPrice($amount, $default->id);
        }

        return number_format((float) $amount, 2) . ' ' . $iso;
    }
}
