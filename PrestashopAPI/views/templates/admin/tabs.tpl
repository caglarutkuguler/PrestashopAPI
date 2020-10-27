{*
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
*}

<div class="panel">
<div style="background-color: #eaebec; padding: 20px;">
<ul class="nav nav-tabs" id="myTab" role="tablist">
  <li class="nav-item active">
    <a class="nav-link active" data-toggle="tab" href="#products" role="tab">{l s='Products' mod='PrestashopAPI'}</a>
  </li>
  <li class="nav-item ">
    <a class="nav-link" data-toggle="tab" href="#orders" role="tab">{l s='Orders' mod='PrestashopAPI'}</a>
  </li>
</ul>
<div class="tab-content">
  <div class="tab-pane active" id="products" role="tabpanel">
    <table class="table">
      {assign var="productrow" value=1}
      <thead>
        <tr>
          <th>#</th>
          <th>{l s='Pic' mod='PrestashopAPI'}</th>
          <th>{l s='Product ID' mod='PrestashopAPI'}</th>
          <th>{l s='Product Name' mod='PrestashopAPI'}</th>
          <th>{l s='Price' mod='PrestashopAPI'}</th>
          {* <th>{l s='Lang ID' mod='PrestashopAPI'}</th> *}
          <th>{l s='Status' mod='PrestashopAPI'}</th>
        </tr>
      </thead>
      <tbody>
      {foreach from=$products item=product}
        <tr>
          <td>{$productrow}</td>
          <td>{if ($product.pico)}<img src="{$product.pico}" width="50px" height="50px"></img>{/if}</td>
          <td>{$product.id_product}</td>
          <td>{$product.name}</td>
          <td>{$product.price}</td>
          {* <td>{$order.id_lang}</td> *}
          <td>{$order.statut}</td>
        </tr>
        {$productrow = $productrow + 1}
        {/foreach}
      </tbody>
    </table>
  </div>
  <div class="tab-pane" id="orders" role="tabpanel">
    <table class="table">
      {assign var="orderrow" value=1}
      <thead>
        <tr>
          <th>#</th>
          <th>{l s='Pic' mod='PrestashopAPI'}</th>
          {* <th>{l s='ID Seller History' mod='PrestashopAPI'}</th> *}
          <th>{l s='Product ID' mod='PrestashopAPI'}</th>
          {* <th>{l s='Type' mod='PrestashopAPI'}</th> *}
          <th>{l s='Amount' mod='PrestashopAPI'}</th>
          <th>{l s='Order ID' mod='PrestashopAPI'}</th>
          {* <th>{l s='Detail' mod='PrestashopAPI'}</th> *}
          <th>{l s='Product Name' mod='PrestashopAPI'}</th>
          {* <th>{l s='Order Source' mod='PrestashopAPI'}</th> *}
          {* <th>{l s='Order Date' mod='PrestashopAPI'}</th> *}
          <th>{l s='ISO Code' mod='PrestashopAPI'}</th>
          {* <th>{l s='Role' mod='PrestashopAPI'}</th> *}
          {* <th>{l s='Role Detail' mod='PrestashopAPI'}</th> *}
          {* <th>{l s='Cname' mod='PrestashopAPI'}</th> *}
          <th>{l s='Product Type' mod='PrestashopAPI'}</th>
          {* <th>{l s='Zen Name' mod='PrestashopAPI'}</th> *}
          {* <th>{l s='ID Zen Product' mod='PrestashopAPI'}</th> *}
          {* <th>{l s='ID Pack' mod='PrestashopAPI'}</th> *}
          {* <th>{l s='Contributor Promo' mod='PrestashopAPI'}</th> *}
          {* <th>{l s='Zen Alone' mod='PrestashopAPI'}</th> *}
          {* <th>{l s='Customer ID' mod='PrestashopAPI'}</th> *}
          <th>{l s='Quantity' mod='PrestashopAPI'}</th>
          <th>{l s='Quantity Refunded' mod='PrestashopAPI'}</th>
          {* <th>{l s='Presto' mod='PrestashopAPI'}</th> *}
          {* <th>{l s='Customer Hash' mod='PrestashopAPI'}</th> *}
          {* <th>{l s='Transferred Customer Hash' mod='PrestashopAPI'}</th> *}
          {* <th>{l s='Discount Name' mod='PrestashopAPI'}</th> *}
          <th>{l s='Order Date' mod='PrestashopAPI'}</th>
        </tr>
      </thead>
      <tbody>
      {foreach from=$orders item=order}
        <tr>
          <td>{$orderrow}</td>
          <td>{if ($order.pico)}<img src="{$order.pico}" width="50px" height="50px"></img>{/if}</td>
          {* <td>{$order.id_seller_history}</td> *}
          <td>{$order.id_product}</td>
          {* <td>{$order.type}</td> *}
          <td>{$order.amount}</td>
          <td>{$order.id_order}</td>
          {* <td>{$order.detail}</td> *}
          <td>{$order.product_name}</td>
          {* <td>{$order.order_source}</td> *}
          {* <td>{$order.order_date}</td> *}
          <td>{$order.iso_code}</td>
          {* <td>{$order.role}</td> *}
          {* <td>{$order.role_detail}</td> *}
          {* <td>{$order.cname}</td> *}
          <td>{$order.product_type}</td>
          {* <td>{$order.zen_name}</td> *}
          {* <td>{$order.id_zen_product}</td> *}
          {* <td>{$order.id_pack}</td> *}
          {* <td>{$order.contributor_promo}</td> *}
          {* <td>{$order.zen_alone}</td> *}
          {* <td>{$order.id_customer}</td> *}
          <td>{$order.product_quantity}</td>
          <td>{$order.product_quantity_refunded}</td>
          {* <td>{$order.presto}</td> *}
          {* <td>{$order.customer_hash}</td> *}
          {* <td>{$order.transferred_customer_hash}</td> *}
          {* <td>{$order.discount_name}</td> *}
          <td>{$order.order_date_display}</td>
        </tr>
        {$orderrow = $orderrow + 1}
        {/foreach}
      </tbody>
    </table>
  </div>
</div>
<script type="text/javascript">
$(document).ready(function(){
  $('#myTab a').click(function (e) {
    e.preventDefault()
    $(this).tab('show')
  });
});
</script>
</div>
</div>

