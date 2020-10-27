<?php
/**
 * 2007-2020 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2020 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PrestashopAPI extends Module
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'PrestashopAPI';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'MEG Venture';
        $this->need_instance = 0;

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Prestashop Addons Seller Store API Module');
        $this->description = $this->l('This module is used to get the Prestashop Addons Store selling data exchange information.');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);

        $path = dirname(__FILE__);
        if (strpos(__FILE__, 'Module.php') !== false) {
            $path .= '/../modules/' . $this->name;
        }

        include_once $path . '/classes/SellerApi.php';
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     */
    public function install()
    {
        Configuration::updateValue('PRODUCT_PAGE_FRONT_ENABLE', true);
        return parent::install() &&
        $this->registerHook('footer') &&
        $this->registerHook('backOfficeHeader') &&
        $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall()
    {
        Configuration::deleteByName('PRODUCT_PAGE_FRONT_ENABLE');
        Configuration::deleteByName('PRESTASHOPAPI_KEY');
        Configuration::deleteByName('API_DATE_FROM');
        Configuration::deleteByName('API_DATE_TO');

        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool) Tools::isSubmit('submitPrestashopAPIModule')) == true) {
            $this->postProcess();
        }

        $api = new SellerApi();

        $options = array(
            'limit' => 10000,
            'sort' => 'asc',
            'page' => 1,
        );

        setlocale(LC_MONETARY, 'nl_NL.UTF-8');

        $orders = json_decode($api->getOrders($options), true);
        $products = json_decode($api->getProducts($options), true);

        /* Find the number of Prestashop Addons Store Sales*/
        $arraynumber = 0;
        foreach ($products['products'] as $product) {
            $sales = 0;
            $amount = 0;
            foreach ($orders['sales'] as $order) {
                if ($product['id_product'] == $order['id_product']) {
                    $sales = $sales + $order['product_quantity'] - 2 * $order['product_quantity_refunded'];
                    $amount = $amount + $order['product_quantity'] * $order['amount'] - 2 * $order['product_quantity_refunded'] * $order['amount'];
                }
            }
            $products['products'][$arraynumber]['numberofsalesaddons'] = $sales;
            $products['products'][$arraynumber]['turnover_addons'] = $amount;
            $arraynumber++;
        }

        /* Find the number of local sales per reference */
        $sql = 'SELECT a.id_order, a.product_id, a.product_quantity, a.product_price, a.total_price_tax_incl, b.reference
                FROM ' . _DB_PREFIX_ . 'order_detail a, ' . _DB_PREFIX_ . 'product b
                WHERE a.product_id = b.id_product';
        $local_sales = Db::getInstance()->executes($sql);
        $arraynumber = 0;
        foreach ($products['products'] as $product) {
            $sales = 0;
            $amount = 0;
            foreach ($local_sales as $order) {
                if ($product['id_product'] == $order['reference']) {
                    $sales = $sales + $order['product_quantity'] - $order['product_quantity_refunded'];
                    $amount = $amount + $order['product_quantity'] * $order['total_price_tax_incl'] - $order['product_quantity_refunded'] * $order['total_price_tax_incl'];
                }
            }
            $products['products'][$arraynumber]['numberofsaleslocal'] = $sales;
            $products['products'][$arraynumber]['turnover_local'] = $amount;
            $products['products'][$arraynumber]['totalsales'] = $products['products'][$arraynumber]['numberofsalesaddons'] + $products['products'][$arraynumber]['numberofsaleslocal'];
            $products['products'][$arraynumber]['turnover'] = $products['products'][$arraynumber]['turnover_addons'] + $products['products'][$arraynumber]['turnover_local'];

            $products['products'][$arraynumber]['turnover'] = money_format('%(#1n', $products['products'][$arraynumber]['turnover']);
            $arraynumber++;
        }

        $this->context->smarty->assign(array(
            'module_dir' => $this->_path,
            'orders' => $orders['sales'],
            'products' => $products['products'],
        ));

        $output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');
        $tabs = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/tabs.tpl');

        return $output . $tabs . $this->renderForm();
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitPrestashopAPIModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
        . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'desc' => $this->l('You can find your API key by visiting your seller account page, Settings tab, under the <> API tab. Click on Get my API key button.'),
                        'name' => 'PRESTASHOPAPI_KEY',
                        'label' => $this->l('API Key'),
                    ),
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'desc' => $this->l('Enter the date to get the queries starting from. If you do not specify dates, the API will provide data for the last 3 months. The date in format YYYY-MM-DD'),
                        'name' => 'API_DATE_FROM',
                        'label' => $this->l('Date From'),
                    ),
                    array(
                        'col' => 4,
                        'type' => 'text',
                        'desc' => $this->l('Enter the date to get the queries end. If you do not specify dates, the API will provide data for the last 3 months. The date in format YYYY-MM-DD'),
                        'name' => 'API_DATE_TO',
                        'label' => $this->l('Date To'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enable product page number of sales notification'),
                        'name' => 'PRODUCT_PAGE_FRONT_ENABLE',
                        'is_bool' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Yes'),
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('No'),
                            ),
                        ),
                        'desc' => $this->l('displays the total number of sales (Addons Store + local store) of a product with a notification.'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return array(
            'PRODUCT_PAGE_FRONT_ENABLE' => Configuration::get('PRODUCT_PAGE_FRONT_ENABLE', true),
            'PRESTASHOPAPI_KEY' => Configuration::get('PRESTASHOPAPI_KEY', ''),
            'API_DATE_FROM' => Configuration::get('API_DATE_FROM', ''),
            'API_DATE_TO' => Configuration::get('API_DATE_TO', ''),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be loaded in the BO.
     */
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJS($this->_path . 'views/js/back.js');
            $this->context->controller->addCSS($this->_path . 'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookDisplayFooter()
    {
        if (($this->context->controller instanceof ProductController) && (Configuration::get('PRODUCT_PAGE_FRONT_ENABLE'))) {
            $api = new SellerApi();
            $id_product = Tools::getValue('id_product');
            $sql = 'SELECT reference FROM ' . _DB_PREFIX_ . 'product WHERE id_product = ' . (int) $id_product;
            $reference = Db::getInstance()->getValue($sql);
            $options = array(
                'limit' => 10000,
                'sort' => 'asc',
                'page' => 1,
            );

            $orders = json_decode($api->getOrders($options), true);
            $product = json_decode($api->getProduct($reference), true);

            /* Find the number of Prestashop Addons Store Sales*/
            $sales = 0;
            foreach ($orders['sales'] as $order) {
                if ($product['product']['id_product'] == $order['id_product']) {
                    $sales = $sales + $order['product_quantity'] - 2 * $order['product_quantity_refunded'];
                }
                $product['product'][0]['numberofsalesaddons'] = $sales;
            }
            /* Find the number of local sales per reference */
            $sql = 'SELECT a.id_order, a.product_id, a.product_quantity, a.product_price, a.total_price_tax_incl, b.reference
                FROM ' . _DB_PREFIX_ . 'order_detail a, ' . _DB_PREFIX_ . 'product b
                WHERE a.product_id = ' . $id_product;
            $local_sales = Db::getInstance()->executes($sql);

            $sales = 0;
            foreach ($local_sales as $order) {
                if ($product['product']['id_product'] == $order['reference']) {
                    $sales = $sales + $order['product_quantity'] - $order['product_quantity_refunded'];
                }
            }
            $product['product'][0]['numberofsaleslocal'] = $sales;
            $product['product'][0]['totalsales'] = $product['product'][0]['numberofsalesaddons'] + $product['product'][0]['numberofsaleslocal'];

            $numberofsales = $product['product'][0]['totalsales'];

            $this->context->controller->addJS($this->_path . 'views/js/jquery.growl.js');
            $this->context->controller->addCSS($this->_path . '/views/css/jquery.growl.css');

            $this->smarty->assign('numberofsales', $numberofsales);
            if ($numberofsales > 0) {
                return $this->display(__FILE__, 'views/templates/front/header.tpl');
            }
        }
    }

    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('module_name') == $this->name) {
            $this->context->controller->addJS($this->_path . 'views/js/back.js');
            $this->context->controller->addCSS($this->_path . 'views/css/back.css');
        }
    }
}
