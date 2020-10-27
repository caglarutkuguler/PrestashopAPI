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

class SellerApi extends ObjectModel
{
	private static $api_key = '';
	private static $api_url = 'https://api.addons.prestashop.com/request/';

	public function __construct() {
		// Check if curl is enabled.
		if (function_exists('curl_version') === false) {
			throw new Exception('Curl is not installed on your server');
		}
		return true;
	}

	/**
	 * Get all your threads
	 */
	public function getThreads($options) {
		$url = self::$api_url . 'seller/threads';
		$url = self::_handleOptions($url, $options);
		$api_key = Configuration::get('PRESTASHOPAPI_KEY');
		return self::_apiCall($url, false);
	}
	
	/**
	 * Get the info on one thread
	 */
	public function getThread($id_thread) {
		$url = self::$api_url . 'seller/thread/' . (int) $id_thread;
		$api_key = Configuration::get('PRESTASHOPAPI_KEY');
		return self::_apiCall($url, false);
	}
	
	/**
	 * Get the info on one product
	 */
	public function getProduct($id_product) {
		$url = self::$api_url . 'seller/product/' . (int) $id_product;
		$api_key = Configuration::get('PRESTASHOPAPI_KEY');
		return self::_apiCall($url, false);
	}
	
	/**
	 * Get all your products
	 */
	public function getProducts($options) {
		$url = self::$api_url . 'seller/products';
		$url = self::_handleOptions($url, $options);
		$post['api_key'] = Configuration::get('PRESTASHOPAPI_KEY');
		return self::_apiCall($url, false, $post);
	}
	
	/**
	 * Get all your orders
	 */
	public function getOrders($options) {
		$url = self::$api_url . 'seller/orders';
		$url = self::_handleOptions($url, $options);
		$post['api_key'] = Configuration::get('PRESTASHOPAPI_KEY');
		$post['date_from'] = Configuration::get('API_DATE_FROM');
		$post['date_to'] = Configuration::get('API_DATE_TO');
		return self::_apiCall($url, false, $post);
	}
	
	/**
	 * Get all your invoices
	 */
	public function getInvoices($options) {
		$url = self::$api_url . 'seller/invoices';
		$url = self::_handleOptions($url, $options);
		$api_key = Configuration::get('PRESTASHOPAPI_KEY');
		return self::_apiCall($url, false);
	}
	
	/**
	 * Get all your bank / withdrawal information
	 */
	public function getBank($options) {
		$url = self::$api_url . 'seller/bank';
		$url = self::_handleOptions($url, $options);
		$api_key = Configuration::get('PRESTASHOPAPI_KEY');
		return self::_apiCall($url, false);
	}

	/**
	 * Get all messages for one thread
	 * @param int $id_thread
	 */
	public function getMessages($id_thread, $options)	{
		$url = self::$api_url . 'seller/threads/' . (int) $id_thread . '/messages';
		$url = self::_handleOptions($url, $options);
		$api_key = Configuration::get('PRESTASHOPAPI_KEY');
		
		return self::_apiCall($url, false);
	}
	
	/**
	 * Get all messages no matter what the thread is
	 */
	public function getAllMessages($options)	{
		$url = self::$api_url . 'seller/messages';
		$url = self::_handleOptions($url, $options);
		$api_key = Configuration::get('PRESTASHOPAPI_KEY');
		
		return self::_apiCall($url, false);
	}

	/**
	 * Send your answer to a message
	 * @param string $message
	 * @param file $file
	 */
	public function sendMessage($id_thread, $message, $file = null)	{
		$url = self::$api_url . 'seller/threads/' . (int) $id_thread . '/messages/add';

		$post = array(
			'api_key' => self::$api_key,
			'message' => SellerApi::pSQL($message)
		);

		if (!is_null($file) && !empty($file)) {
			$cfile = new CURLFile($_FILES['attachment']['tmp_name']);
			$post['attachment'] = $cfile;
		}
		return self::_apiCall($url, true, $post);
	}

	/**
	 * Handles the API call for basic functions
	 * @param string $url
	 * @param array $postFields
	 */
	private static function _apiCall($url, $method, $postFields = null)	{
		$api_key = Configuration::get('PRESTASHOPAPI_KEY');
		$date_from = Configuration::get('API_DATE_FROM');
		$date_to = Configuration::get('API_DATE_TO');

		if (is_null($postFields)) {
			$postFields = array('api_key' => $api_key,
								'date_from' => $date_from,
								'date_to' => $date_to);
		}

		if (empty($postFields['date_to'])) {
			$postFields['date_to'] = date("Y/m/d");
		}

		$ch = curl_init();
		if ($ch === false) {
			return 'Can not initiate curl_init';
		}

		curl_setopt($ch, CURLOPT_URL, $url);
		
		if ($method === false) {
			$postFields = http_build_query($postFields);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);	
		} else {
			$postFields = $postFields;
			curl_setopt($ch, CURLOPT_POST, $method);
		}

		curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$results = curl_exec($ch);
		if ($results === false) {
			return 'curl_exec failed, error # : ' . curl_errno($ch) . ' - ' . curl_error($ch);
		}

		curl_close($ch);

		return $results;
	}

	private static function pSQL($string, $htmlOK = false) {
		if (!is_numeric($string)) {
			$search = array("\\", "\0", "\n", "\r", "\x1a", "'", '"');
			$replace = array("\\\\", "\\0", "\\n", "\\r", "\Z", "\'", '\"');
			$string = str_replace($search, $replace, $string);
			if (!$htmlOK) {
				$string = strip_tags(nl2br($string));
			}
		}

		return $string;
	}
	
	/**
	 * Handle parameters
	 * @param string $url
	 * @param array $options
	 */
	private static function _handleOptions($url, $options) {
		$build_query = array();
		
		if (isset($options['limit'])) {
			$build_query['limit'] = (int) $options['limit'];
		}
		
		if (isset($options['sort']) 
				&& (strtolower($options['sort']) == 'asc' || strtolower($options['sort']) == 'desc')
			) {
			$build_query['sort'] = $options['sort'];
		}
		
		if (isset($options['page'])) {
			$build_query['page'] = (int) $options['page'];
		}
		
		if (count($build_query) > 0) {
			$url .= '?' . http_build_query($build_query);
		}
		
		
		return $url;
	}

}