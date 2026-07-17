{*
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
*}

{assign var='psapi_base' value="`$psapi_config_url`&`$psapi_action_param`="}

{* Loaded here rather than through actionAdminControllerSetMedia: the styles only ever apply
   inside getContent(), and a tag cannot silently fail to fire. *}
<link href="{$psapi_module_dir|escape:'html':'UTF-8'}views/css/admin.css?v={$psapi_asset_v|escape:'html':'UTF-8'}" rel="stylesheet" type="text/css" media="all" />

<div class="psapi-app">

	{* ============================================================ *}
	{* Header                                                        *}
	{* ============================================================ *}
	<div class="psapi-hero">
		<div class="psapi-hero-text">
			<h2>{l s='Seller Dashboard' mod='PrestashopAPI'}</h2>
			<p>{l s='Your marketplace sales and your own shop sales, side by side.' mod='PrestashopAPI'}</p>
		</div>
		<div class="psapi-hero-meta">
			{if $psapi_last_sync}
				<span class="psapi-sync">{l s='Last updated' mod='PrestashopAPI'}: {$psapi_last_sync}</span>
			{else}
				<span class="psapi-sync">{l s='Never updated yet' mod='PrestashopAPI'}</span>
			{/if}
			<a class="btn btn-default btn-sm" href="{$psapi_base|escape:'html':'UTF-8'}refresh">
				<i class="icon icon-refresh"></i> {l s='Refresh now' mod='PrestashopAPI'}
			</a>
		</div>
	</div>

	{* ============================================================ *}
	{* Errors and warnings                                           *}
	{* ============================================================ *}
	{if $psapi_api_error}
		<div class="alert alert-danger">
			<strong>{l s='The marketplace could not be reached.' mod='PrestashopAPI'}</strong><br />
			{$psapi_api_error}
		</div>
	{/if}

	{if $psapi_stale && $psapi_has_key}
		<div class="alert alert-warning">
			{l s='Showing the last data we managed to download. The marketplace did not answer the most recent refresh.' mod='PrestashopAPI'}
		</div>
	{/if}

	{* ============================================================ *}
	{* First run                                                     *}
	{* ============================================================ *}
	{if !$psapi_has_key}
		<div class="panel psapi-onboarding">
			<h3><i class="icon icon-rocket"></i> {l s='Let us get you connected' mod='PrestashopAPI'}</h3>
			<p class="psapi-lead">
				{l s='This module reads your sales from the PrestaShop Addons marketplace using your seller API key. It only ever reads: nothing is published, changed or removed on your seller account.' mod='PrestashopAPI'}
			</p>
			<ol class="psapi-steps">
				<li>
					<span class="psapi-step-n">1</span>
					<div>
						<strong>{l s='Open your seller account' mod='PrestashopAPI'}</strong>
						<p>{l s='Sign in at addons.prestashop.com with the account that sells your modules.' mod='PrestashopAPI'}</p>
						<a class="btn btn-default btn-xs" target="_blank" rel="noopener" href="https://addons.prestashop.com/en/login">
							{l s='Go to the marketplace' mod='PrestashopAPI'} <i class="icon icon-external-link"></i>
						</a>
					</div>
				</li>
				<li>
					<span class="psapi-step-n">2</span>
					<div>
						<strong>{l s='Find your API key' mod='PrestashopAPI'}</strong>
						<p>{l s='Go to Settings, open the API tab, then click "Get my API key". Copy the key exactly as shown.' mod='PrestashopAPI'}</p>
					</div>
				</li>
				<li>
					<span class="psapi-step-n">3</span>
					<div>
						<strong>{l s='Paste it into the Settings tab' mod='PrestashopAPI'}</strong>
						<p>{l s='Save, and your sales appear straight away. You can change the reporting period at any time.' mod='PrestashopAPI'}</p>
						<button type="button" class="btn btn-primary btn-xs" data-psapi-goto="settings">
							{l s='Open Settings' mod='PrestashopAPI'}
						</button>
					</div>
				</li>
			</ol>
		</div>
	{/if}

	{* ============================================================ *}
	{* Tabs                                                          *}
	{* ============================================================ *}
	<div class="psapi-tabs" role="tablist">
		<button type="button" class="psapi-tab" data-psapi-tab="dashboard" role="tab">
			<i class="icon icon-dashboard"></i> {l s='Dashboard' mod='PrestashopAPI'}
		</button>
		<button type="button" class="psapi-tab" data-psapi-tab="products" role="tab">
			<i class="icon icon-cubes"></i> {l s='Products' mod='PrestashopAPI'}
			{if $psapi_products}<span class="psapi-pill">{$psapi_products|count}</span>{/if}
		</button>
		<button type="button" class="psapi-tab" data-psapi-tab="sales" role="tab">
			<i class="icon icon-shopping-cart"></i> {l s='Sales' mod='PrestashopAPI'}
			{if $psapi_sales_total}<span class="psapi-pill">{$psapi_sales_total}</span>{/if}
		</button>
		<button type="button" class="psapi-tab" data-psapi-tab="messages" role="tab">
			<i class="icon icon-comments"></i> {l s='Messages' mod='PrestashopAPI'}
			{if $psapi_unread > 0}
				<span class="psapi-pill psapi-pill--alert">{$psapi_unread}</span>
			{elseif $psapi_threads_total}
				<span class="psapi-pill">{$psapi_threads_total}</span>
			{/if}
		</button>
		<button type="button" class="psapi-tab" data-psapi-tab="payouts" role="tab">
			<i class="icon icon-money"></i> {l s='Payouts' mod='PrestashopAPI'}
		</button>
		<button type="button" class="psapi-tab" data-psapi-tab="settings" role="tab">
			<i class="icon icon-cogs"></i> {l s='Settings' mod='PrestashopAPI'}
		</button>
		<button type="button" class="psapi-tab" data-psapi-tab="help" role="tab">
			<i class="icon icon-life-ring"></i> {l s='Help' mod='PrestashopAPI'}
		</button>
	</div>

	{* ============================================================ *}
	{* Dashboard                                                     *}
	{* ============================================================ *}
	<section class="psapi-pane" data-psapi-pane="dashboard">

		<div class="psapi-kpis">
			<div class="psapi-kpi psapi-kpi--primary">
				<span class="psapi-kpi-label">{l s='Marketplace revenue' mod='PrestashopAPI'}</span>
				<span class="psapi-kpi-value">{$psapi_money.addons_revenue}</span>
				<span class="psapi-kpi-hint">{$psapi_period_from} &rarr; {$psapi_period_to}</span>
			</div>
			<div class="psapi-kpi">
				<span class="psapi-kpi-label">{l s='Last 30 days' mod='PrestashopAPI'}</span>
				<span class="psapi-kpi-value">{$psapi_money.addons_last30}</span>
				<span class="psapi-kpi-hint">{l s='Marketplace only' mod='PrestashopAPI'}</span>
			</div>
			<div class="psapi-kpi">
				<span class="psapi-kpi-label">{l s='Units sold' mod='PrestashopAPI'}</span>
				<span class="psapi-kpi-value">{$psapi_summary.addons_units}</span>
				<span class="psapi-kpi-hint">{l s='Across' mod='PrestashopAPI'} {$psapi_summary.addons_orders} {l s='orders' mod='PrestashopAPI'}</span>
			</div>
			<div class="psapi-kpi">
				<span class="psapi-kpi-label">{l s='Average per order' mod='PrestashopAPI'}</span>
				<span class="psapi-kpi-value">{$psapi_money.addons_average}</span>
				<span class="psapi-kpi-hint">{l s='Marketplace only' mod='PrestashopAPI'}</span>
			</div>
			<div class="psapi-kpi {if $psapi_summary.addons_refunded > 0}psapi-kpi--warn{/if}">
				<span class="psapi-kpi-label">{l s='Refunded' mod='PrestashopAPI'}</span>
				<span class="psapi-kpi-value">{$psapi_summary.addons_refunded}</span>
				<span class="psapi-kpi-hint">{$psapi_money.addons_refund_value}</span>
			</div>
			<div class="psapi-kpi psapi-kpi--accent">
				<span class="psapi-kpi-label">{l s='Marketplace + your shop' mod='PrestashopAPI'}</span>
				{if $psapi_currency_mismatch}
					<span class="psapi-kpi-value psapi-kpi-value--muted">{l s='n/a' mod='PrestashopAPI'}</span>
					<span class="psapi-kpi-hint">{l s='Currencies cannot be combined' mod='PrestashopAPI'}</span>
				{else}
					<span class="psapi-kpi-value">{$psapi_money.total_revenue}</span>
					<span class="psapi-kpi-hint">{$psapi_summary.total_units} {l s='units in total' mod='PrestashopAPI'}</span>
				{/if}
			</div>
		</div>

		{* ---------- Health checks ---------- *}
		<div class="panel">
			<h3><i class="icon icon-stethoscope"></i> {l s='Status' mod='PrestashopAPI'}</h3>
			<ul class="psapi-checks">
				<li class="{if $psapi_curl}psapi-ok{else}psapi-bad{/if}">
					<i class="icon {if $psapi_curl}icon-check{else}icon-times{/if}"></i>
					{if $psapi_curl}
						{l s='The cURL extension is available.' mod='PrestashopAPI'}
					{else}
						{l s='The cURL extension is missing. This module cannot contact the marketplace without it. Ask your host to enable it.' mod='PrestashopAPI'}
					{/if}
				</li>
				<li class="{if $psapi_has_key}psapi-ok{else}psapi-bad{/if}">
					<i class="icon {if $psapi_has_key}icon-check{else}icon-times{/if}"></i>
					{if $psapi_has_key}
						{l s='An API key is saved.' mod='PrestashopAPI'}
						<a href="{$psapi_base|escape:'html':'UTF-8'}test">{l s='Test the connection' mod='PrestashopAPI'}</a>
					{else}
						{l s='No API key yet. Add one in the Settings tab.' mod='PrestashopAPI'}
					{/if}
				</li>
				<li class="{if $psapi_link_health.linked == $psapi_link_health.total && $psapi_link_health.total > 0}psapi-ok{elseif $psapi_link_health.linked > 0}psapi-warn{else}psapi-warn{/if}">
					<i class="icon icon-link"></i>
					{$psapi_link_health.linked} / {$psapi_link_health.total}
					{l s='marketplace products are matched to a product in this shop.' mod='PrestashopAPI'}
					{if $psapi_link_health.linked < $psapi_link_health.total}
						<button type="button" class="psapi-inline-link" data-psapi-goto="products">
							{l s='Match the rest' mod='PrestashopAPI'}
						</button>
					{/if}
				</li>
				<li class="psapi-ok">
					<i class="icon icon-bell"></i>
					{if $psapi_unread > 0}
						{$psapi_unread} {l s='conversation(s) have new activity. The back-office Dashboard is showing a notice.' mod='PrestashopAPI'}
						<button type="button" class="psapi-inline-link" data-psapi-goto="messages">
							{l s='Read them' mod='PrestashopAPI'}
						</button>
					{else}
						{l s='No conversations are waiting, so the Dashboard shows no notice. It appears there only when a buyer is waiting.' mod='PrestashopAPI'}
					{/if}
				</li>
				<li class="{if $psapi_currency_mismatch}psapi-warn{else}psapi-ok{/if}">
					<i class="icon icon-money"></i>
					{if $psapi_currency_mismatch}
						{l s='Your marketplace currency and your shop currency are different, and your shop has no exchange rate for the marketplace currency, so combined totals are hidden.' mod='PrestashopAPI'}
						({$psapi_marketplace_iso} &ne; {$psapi_shop_iso})
					{else}
						{l s='Marketplace amounts are converted into your shop currency.' mod='PrestashopAPI'}
						({$psapi_marketplace_iso} &rarr; {$psapi_shop_iso})
					{/if}
				</li>
			</ul>
		</div>

		{* ---------- Revenue chart ---------- *}
		<div class="panel">
			<h3><i class="icon icon-bar-chart"></i> {l s='Revenue by month' mod='PrestashopAPI'}</h3>
			{if $psapi_summary.addons_units > 0}
				<div class="psapi-chart">
					{foreach from=$psapi_months item=month}
						<div class="psapi-bar-wrap" title="{$month.short}: {$month.revenue_display}">
							<div class="psapi-bar-value">{$month.units}</div>
							<div class="psapi-bar" style="height:{$month.pct}%"></div>
							<div class="psapi-bar-label">{$month.short}</div>
						</div>
					{/foreach}
				</div>
				<p class="psapi-chart-note">
					{l s='Bar height is marketplace revenue; the number above each bar is the number of units sold.' mod='PrestashopAPI'}
				</p>
			{else}
				<p class="psapi-empty">{l s='No sales in the selected period.' mod='PrestashopAPI'}</p>
			{/if}
		</div>

		{* ---------- Countries ---------- *}
		{if $psapi_countries}
			<div class="panel">
				<h3><i class="icon icon-globe"></i> {l s='Where your buyers are' mod='PrestashopAPI'}</h3>
				<table class="table psapi-table">
					<thead>
						<tr>
							<th>{l s='Country' mod='PrestashopAPI'}</th>
							<th class="text-right">{l s='Units' mod='PrestashopAPI'}</th>
							<th>{l s='Share' mod='PrestashopAPI'}</th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$psapi_countries item=country}
							<tr>
								<td><strong>{$country.iso}</strong></td>
								<td class="text-right">{$country.units}</td>
								<td>
									<div class="psapi-meter">
										<span style="width:{if $psapi_summary.addons_units > 0}{($country.units / $psapi_summary.addons_units) * 100}{else}0{/if}%"></span>
									</div>
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			</div>
		{/if}
	</section>

	{* ============================================================ *}
	{* Products                                                      *}
	{* ============================================================ *}
	<section class="psapi-pane" data-psapi-pane="products">
		<div class="panel">
			<h3><i class="icon icon-cubes"></i> {l s='Your products' mod='PrestashopAPI'}</h3>
			<p class="psapi-lead">
				{l s='A product is matched to this shop when its reference here equals its marketplace product ID. Anything that did not match automatically can be pinned by hand below.' mod='PrestashopAPI'}
			</p>

			{if $psapi_products}
				<form method="post" action="{$psapi_config_url|escape:'html':'UTF-8'}">
					<input type="text" class="psapi-filter form-control" data-psapi-filter="psapi-products-table"
						placeholder="{l s='Filter by name or ID...' mod='PrestashopAPI'}" />

					<div class="table-responsive">
						<table class="table psapi-table" id="psapi-products-table">
							<thead>
								<tr>
									<th></th>
									<th>{l s='ID' mod='PrestashopAPI'}</th>
									<th>{l s='Product' mod='PrestashopAPI'}</th>
									<th>{l s='Price' mod='PrestashopAPI'}</th>
									<th>{l s='Status' mod='PrestashopAPI'}</th>
									<th class="text-right">{l s='Marketplace' mod='PrestashopAPI'}</th>
									<th class="text-right">{l s='This shop' mod='PrestashopAPI'}</th>
									<th class="text-right">{l s='Total units' mod='PrestashopAPI'}</th>
									<th class="text-right">{l s='Total revenue' mod='PrestashopAPI'}</th>
									<th>{l s='Matched to' mod='PrestashopAPI'}</th>
								</tr>
							</thead>
							<tbody>
								{foreach from=$psapi_products item=product}
									<tr data-psapi-search="{$product.name|escape:'html':'UTF-8'} {$product.id_product|escape:'html':'UTF-8'}">
										{* referrerpolicy: the marketplace CDN can reject hotlinked requests that carry a
										   foreign Referer. The fallback replaces the browser's broken-image icon, and its
										   title carries the URL that failed so it can be diagnosed without the console. *}
										<td class="psapi-thumb">
											{if $product.pico}
												<img src="{$product.pico|escape:'html':'UTF-8'}" alt="" loading="lazy"
													referrerpolicy="no-referrer" data-psapi-thumb />
											{/if}
											<span class="psapi-thumb-empty" title="{if $product.pico}{$product.pico|escape:'html':'UTF-8'}{else}{l s='No image supplied by the marketplace' mod='PrestashopAPI'}{/if}">
												<i class="icon icon-picture-o"></i>
											</span>
										</td>
										<td>{$product.id_product}</td>
										<td class="psapi-name">{$product.name}</td>
										<td>{$product.price_display}</td>
										<td>
											{if $product.status}<span class="psapi-badge">{$product.status}</span>{/if}
										</td>
										<td class="text-right">
											<strong>{$product.addons_units}</strong>
											<small>{$product.addons_revenue_display}</small>
										</td>
										<td class="text-right">
											{if $product.linked}
												<strong>{$product.local_units}</strong>
												<small>{$product.local_revenue_display}</small>
											{else}
												<span class="psapi-muted">&mdash;</span>
											{/if}
										</td>
										<td class="text-right"><strong>{$product.total_units}</strong></td>
										<td class="text-right">
											{if $psapi_currency_mismatch}
												<span class="psapi-muted" title="{l s='Currencies cannot be combined' mod='PrestashopAPI'}">&mdash;</span>
											{else}
												{$product.total_revenue_display}
											{/if}
										</td>
										<td>
											<select name="psapi_link[{$product.id_product|escape:'html':'UTF-8'}]" class="psapi-select">
												<option value="0">{l s='Not matched' mod='PrestashopAPI'}</option>
												{foreach from=$psapi_local_products key=id_local item=label}
													<option value="{$id_local}"{if $product.local_id == $id_local} selected="selected"{/if}>{$label}</option>
												{/foreach}
											</select>
										</td>
									</tr>
								{/foreach}
							</tbody>
						</table>
					</div>

					<div class="panel-footer">
						<button type="submit" name="submitPrestashopAPILinks" class="btn btn-default pull-right">
							<i class="icon icon-save"></i> {l s='Save matches' mod='PrestashopAPI'}
						</button>
					</div>
				</form>
			{else}
				<p class="psapi-empty">
					{if $psapi_has_key}
						{l s='The marketplace returned no products for this account.' mod='PrestashopAPI'}
					{else}
						{l s='Add your API key to see your products.' mod='PrestashopAPI'}
					{/if}
				</p>
			{/if}
		</div>
	</section>

	{* ============================================================ *}
	{* Sales                                                         *}
	{* ============================================================ *}
	<section class="psapi-pane" data-psapi-pane="sales">
		<div class="panel">
			<h3>
				<i class="icon icon-shopping-cart"></i> {l s='Marketplace sales' mod='PrestashopAPI'}
				<span class="panel-heading-action">
					<a class="btn btn-default btn-sm" href="{$psapi_base|escape:'html':'UTF-8'}export">
						<i class="icon icon-download"></i> {l s='Export CSV' mod='PrestashopAPI'}
					</a>
				</span>
			</h3>

			{if $psapi_sales}
				{if $psapi_sales_total > 500}
					<div class="alert alert-info">
						{l s='Showing the 500 most recent of' mod='PrestashopAPI'} {$psapi_sales_total}
						{l s='sales. Export to CSV for the full list.' mod='PrestashopAPI'}
					</div>
				{/if}

				<input type="text" class="psapi-filter form-control" data-psapi-filter="psapi-sales-table"
					placeholder="{l s='Filter by product, order or country...' mod='PrestashopAPI'}" />

				<div class="table-responsive">
					<table class="table psapi-table" id="psapi-sales-table">
						<thead>
							<tr>
								<th>{l s='Date' mod='PrestashopAPI'}</th>
								<th>{l s='Order' mod='PrestashopAPI'}</th>
								<th>{l s='Product' mod='PrestashopAPI'}</th>
								<th>{l s='Type' mod='PrestashopAPI'}</th>
								<th>{l s='Country' mod='PrestashopAPI'}</th>
								<th class="text-right">{l s='Qty' mod='PrestashopAPI'}</th>
								<th class="text-right">{l s='Refunded' mod='PrestashopAPI'}</th>
								<th class="text-right">{l s='Amount' mod='PrestashopAPI'}</th>
							</tr>
						</thead>
						<tbody>
							{foreach from=$psapi_sales item=sale}
								<tr data-psapi-search="{if isset($sale.product_name)}{$sale.product_name|escape:'html':'UTF-8'}{/if} {if isset($sale.id_order)}{$sale.id_order|escape:'html':'UTF-8'}{/if} {if isset($sale.iso_code)}{$sale.iso_code|escape:'html':'UTF-8'}{/if}"
									{if isset($sale.product_quantity_refunded) && $sale.product_quantity_refunded > 0}class="psapi-row-refunded"{/if}>
									<td>{if isset($sale.order_date_display)}{$sale.order_date_display}{elseif isset($sale.order_date)}{$sale.order_date}{/if}</td>
									<td>{if isset($sale.id_order)}{$sale.id_order}{/if}</td>
									<td class="psapi-name">{if isset($sale.product_name)}{$sale.product_name}{/if}</td>
									<td>{if isset($sale.product_type)}<span class="psapi-badge">{$sale.product_type}</span>{/if}</td>
									<td>{if isset($sale.iso_code)}{$sale.iso_code}{/if}</td>
									<td class="text-right">{if isset($sale.product_quantity)}{$sale.product_quantity}{/if}</td>
									<td class="text-right">
										{if isset($sale.product_quantity_refunded) && $sale.product_quantity_refunded > 0}
											<span class="psapi-danger">{$sale.product_quantity_refunded}</span>
										{else}0{/if}
									</td>
									<td class="text-right">{if isset($sale.amount)}{$sale.amount}{/if}</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				</div>
			{else}
				<p class="psapi-empty">{l s='No sales found for the selected period.' mod='PrestashopAPI'}</p>
			{/if}
		</div>
	</section>

	{* ============================================================ *}
	{* Messages                                                      *}
	{* ============================================================ *}
	<section class="psapi-pane" data-psapi-pane="messages">
		<div class="panel">
			<h3>
				<i class="icon icon-comments"></i> {l s='Buyer messages' mod='PrestashopAPI'}
				{if $psapi_threads_counts.unread > 0 && !$psapi_thread}
					<span class="panel-heading-action">
						<a class="btn btn-default btn-sm" href="{$psapi_base|escape:'html':'UTF-8'}readall"
							onclick="return confirm('{l s='Mark all conversations as read?' mod='PrestashopAPI' js=1}');">
							<i class="icon icon-check"></i> {l s='Mark all as read' mod='PrestashopAPI'}
							({$psapi_threads_counts.unread})
						</a>
					</span>
				{/if}
			</h3>

			{if $psapi_thread}
				<p>
					<a class="btn btn-default btn-xs" href="{$psapi_config_url|escape:'html':'UTF-8'}#psapi-messages">
						<i class="icon icon-arrow-left"></i> {l s='Back to all conversations' mod='PrestashopAPI'}
					</a>
				</p>

				<div class="psapi-thread">
					{if $psapi_thread.messages}
						{foreach from=$psapi_thread.messages item=message}
							<div class="psapi-message">
								{if $message.meta}
									<div class="psapi-message-meta">{$message.meta nofilter}</div>
								{/if}
								{* Escaped, never rendered as HTML: this is buyer-supplied text in an
								   authenticated back office. Line breaks come from CSS white-space:pre-wrap,
								   so no nl2br is needed and no markup is reintroduced. *}
								<div class="psapi-message-body">{$message.body|escape:'html':'UTF-8'}</div>
							</div>
						{/foreach}
					{else}
						<p class="psapi-empty">{l s='This conversation has no messages, or the marketplace did not return them.' mod='PrestashopAPI'}</p>
					{/if}
				</div>

				{* enctype is required or the browser posts the filename only and $_FILES stays empty. *}
				<form method="post" action="{$psapi_config_url|escape:'html':'UTF-8'}" class="psapi-reply"
					enctype="multipart/form-data">
					<input type="hidden" name="psapi_id_thread" value="{$psapi_thread.id|escape:'html':'UTF-8'}" />

					<label for="psapi-message">{l s='Your reply' mod='PrestashopAPI'}</label>
					<textarea id="psapi-message" name="psapi_message" rows="5" class="form-control"
						placeholder="{l s='Write your answer to the buyer...' mod='PrestashopAPI'}"></textarea>

					<label for="psapi-attachment">{l s='Attach a file' mod='PrestashopAPI'}
						<span class="psapi-optional">{l s='(optional)' mod='PrestashopAPI'}</span>
					</label>
					<div class="psapi-file">
						<input type="file" id="psapi-attachment" name="psapi_attachment"
							accept=".{$psapi_attachment_types|replace:', ':',.'}" />
						<span class="psapi-file-name" data-psapi-file-name></span>
					</div>
					<p class="help-block">
						{l s='Up to' mod='PrestashopAPI'} {$psapi_attachment_max} {l s='MB.' mod='PrestashopAPI'}
						{l s='Allowed:' mod='PrestashopAPI'} {$psapi_attachment_types}
					</p>

					<button type="submit" name="submitPrestashopAPIReply" class="btn btn-primary">
						<i class="icon icon-send"></i> {l s='Send reply' mod='PrestashopAPI'}
					</button>
				</form>
			{elseif $psapi_threads || $psapi_threads_filter != 'all'}
				{assign var='psapi_fbase' value="`$psapi_config_url`&psapi_filter="}

				<div class="psapi-segments">
					<a class="psapi-segment {if $psapi_threads_filter == 'all'}psapi-segment--on{/if}"
						href="{$psapi_fbase|escape:'html':'UTF-8'}all#psapi-messages">
						{l s='All' mod='PrestashopAPI'} <span>{$psapi_threads_counts.total}</span>
					</a>
					<a class="psapi-segment {if $psapi_threads_filter == 'unread'}psapi-segment--on{/if}"
						href="{$psapi_fbase|escape:'html':'UTF-8'}unread#psapi-messages">
						{l s='Unread' mod='PrestashopAPI'} <span>{$psapi_threads_counts.unread}</span>
					</a>
					<a class="psapi-segment {if $psapi_threads_filter == 'pinned'}psapi-segment--on{/if}"
						href="{$psapi_fbase|escape:'html':'UTF-8'}pinned#psapi-messages">
						<i class="icon icon-thumb-tack"></i> {l s='Pinned' mod='PrestashopAPI'}
						<span>{$psapi_threads_counts.pinned}</span>
					</a>
				</div>

				{if $psapi_threads_shown > 300}
					<div class="alert alert-info">
						{l s='Showing the first 300 of' mod='PrestashopAPI'} {$psapi_threads_shown}
						{l s='conversations. Narrow it down with the filters or the search box.' mod='PrestashopAPI'}
					</div>
				{/if}

				<input type="text" class="psapi-filter form-control" data-psapi-filter="psapi-threads-table"
					placeholder="{l s='Filter by subject, product or shop...' mod='PrestashopAPI'}" />

				<div class="table-responsive">
					<table class="table psapi-table" id="psapi-threads-table">
						<thead>
							<tr>
								<th></th>
								<th>{l s='Subject' mod='PrestashopAPI'}</th>
								<th>{l s='Product' mod='PrestashopAPI'}</th>
								<th>{l s='Customer' mod='PrestashopAPI'}</th>
								<th class="text-right">{l s='Messages' mod='PrestashopAPI'}</th>
								<th>{l s='Opened' mod='PrestashopAPI'}</th>
								<th>{l s='Support' mod='PrestashopAPI'}</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{foreach from=$psapi_threads item=thread}
								<tr data-psapi-search="{$thread.topic|escape:'html':'UTF-8'} {$thread.product|escape:'html':'UTF-8'} {$thread.website|escape:'html':'UTF-8'}"
									class="{if $thread.unread}psapi-row-unread {/if}{if $thread.pinned}psapi-row-pinned{/if}">
									<td class="psapi-flags">
										<a class="psapi-pin {if $thread.pinned}psapi-pin--on{/if}"
											title="{if $thread.pinned}{l s='Unpin' mod='PrestashopAPI'}{else}{l s='Pin' mod='PrestashopAPI'}{/if}"
											href="{$psapi_base|escape:'html':'UTF-8'}{if $thread.pinned}unpin{else}pin{/if}&psapi_id={$thread.id}#psapi-messages">
											<i class="icon icon-thumb-tack"></i>
										</a>
										<a class="psapi-dot {if $thread.unread}psapi-dot--unread{/if}"
											title="{if $thread.unread}{l s='Mark as read' mod='PrestashopAPI'}{else}{l s='Mark as unread' mod='PrestashopAPI'}{/if}"
											href="{$psapi_base|escape:'html':'UTF-8'}{if $thread.unread}read{else}unread{/if}&psapi_id={$thread.id}#psapi-messages">
										</a>
									</td>
									<td class="psapi-name">
										{if $thread.unread}<span class="psapi-new">{l s='Unread' mod='PrestashopAPI'}</span>{/if}
										{$thread.topic}
									</td>
									<td>{$thread.product}</td>
									<td>
										{if $thread.is_buyer}
											<span class="psapi-badge">{l s='Bought' mod='PrestashopAPI'}</span>
										{else}
											<span class="psapi-badge">{l s='Pre-sales' mod='PrestashopAPI'}</span>
										{/if}
										{if $thread.website}
											<small>{$thread.website}</small>
										{/if}
										{if $thread.version}
											<small>{l s='PrestaShop' mod='PrestashopAPI'} {$thread.version}</small>
										{/if}
									</td>
									<td class="text-right">{$thread.messages}</td>
									<td>{$thread.date|truncate:10:''}</td>
									<td>
										{* Business Care entitlement: negative days means it has run out. *}
										{if $thread.support_days === null}
											<span class="psapi-muted">&mdash;</span>
										{elseif $thread.support_days < 0}
											<span class="psapi-badge psapi-badge--expired">{l s='Expired' mod='PrestashopAPI'}</span>
										{else}
											<span class="psapi-badge psapi-badge--active">
												{$thread.support_days} {l s='days left' mod='PrestashopAPI'}
											</span>
										{/if}
									</td>
									<td class="text-right">
										<a class="btn btn-default btn-xs"
											href="{$psapi_config_url|escape:'html':'UTF-8'}&psapi_id_thread={$thread.id}#psapi-messages">
											{l s='Open' mod='PrestashopAPI'}
										</a>
									</td>
								</tr>
							{foreachelse}
								<tr>
									<td colspan="8" class="psapi-empty">
										{if $psapi_threads_filter == 'unread'}
											{l s='Nothing is unread. Every conversation has been dealt with.' mod='PrestashopAPI'}
										{elseif $psapi_threads_filter == 'pinned'}
											{l s='No conversation is pinned yet. Use the pin next to any row to keep it here.' mod='PrestashopAPI'}
										{else}
											{l s='No conversations.' mod='PrestashopAPI'}
										{/if}
									</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				</div>
			{elseif $psapi_threads_unrecognised}
				<div class="alert alert-warning">
					<strong>{l s='The marketplace answered, but in a shape this module does not recognise.' mod='PrestashopAPI'}</strong><br />
					{l s='It returned' mod='PrestashopAPI'} {$psapi_threads_total}
					{l s='item(s), none of which look like a conversation. Nothing is wrong with your account or your API key: your products and sales are loading normally.' mod='PrestashopAPI'}
				</div>
				<p class="psapi-lead">
					{l s='This is exactly what the marketplace sent back. Copying it into a bug report is enough to get this fixed.' mod='PrestashopAPI'}
				</p>
				<div class="psapi-diag">
					<div class="psapi-diag-head">
						<code>seller/threads</code>
						<button type="button" class="btn btn-default btn-xs" data-psapi-copy="psapi-threads-debug">
							<i class="icon icon-copy"></i> {l s='Copy' mod='PrestashopAPI'}
						</button>
					</div>
					<textarea id="psapi-threads-debug" class="psapi-diag-body form-control" rows="14" readonly="readonly">{$psapi_threads_debug}</textarea>
				</div>
			{elseif $psapi_threads_error}
				{* An empty list and a failed request are different things and must not look alike. *}
				<div class="alert alert-danger">
					<strong>{l s='Your conversations could not be downloaded.' mod='PrestashopAPI'}</strong><br />
					{$psapi_threads_error}
				</div>
				<p class="psapi-lead">
					{l s='Your products and sales are loading normally, so your API key works. Use the Help tab to see exactly what the conversations endpoint returned.' mod='PrestashopAPI'}
					<button type="button" class="psapi-inline-link" data-psapi-goto="help">
						{l s='Open the Help tab' mod='PrestashopAPI'}
					</button>
				</p>
			{else}
				<p class="psapi-empty">
					{if $psapi_has_key}
						{l s='The marketplace returned no conversations for this account.' mod='PrestashopAPI'}
					{else}
						{l s='Add your API key to see your buyer messages.' mod='PrestashopAPI'}
					{/if}
				</p>
			{/if}
		</div>
	</section>

	{* ============================================================ *}
	{* Payouts                                                       *}
	{* ============================================================ *}
	<section class="psapi-pane" data-psapi-pane="payouts">
		<div class="panel">
			<h3><i class="icon icon-money"></i> {l s='Invoices and payouts' mod='PrestashopAPI'}</h3>

			{if $psapi_invoices.rows}
				<div class="table-responsive">
					<table class="table psapi-table">
						<thead>
							<tr>
								{foreach from=$psapi_invoices.columns item=label}<th>{$label}</th>{/foreach}
							</tr>
						</thead>
						<tbody>
							{foreach from=$psapi_invoices.rows item=invoice}
								<tr>
									{foreach from=$invoice.cells item=cell}
										<td>
											{if $cell.is_url}
												<a href="{$cell.text|escape:'html':'UTF-8'}" target="_blank" rel="noopener">
													{l s='Download' mod='PrestashopAPI'}
												</a>
											{else}
												{$cell.text}
											{/if}
										</td>
									{/foreach}
								</tr>
							{/foreach}
						</tbody>
					</table>
				</div>
			{else}
				<p class="psapi-empty">
					{if $psapi_has_key}
						{l s='No invoices returned by the marketplace for this period.' mod='PrestashopAPI'}
					{else}
						{l s='Add your API key to see your payouts.' mod='PrestashopAPI'}
					{/if}
				</p>
			{/if}
		</div>
	</section>

	{* ============================================================ *}
	{* Settings                                                      *}
	{* ============================================================ *}
	<section class="psapi-pane" data-psapi-pane="settings">
		{$psapi_settings_form nofilter}

		<div class="panel">
			<h3><i class="icon icon-clock-o"></i> {l s='Keep the data fresh automatically' mod='PrestashopAPI'}</h3>
			<p>
				{l s='The storefront badge and the figures above are read from a local copy of your marketplace data, so that no visitor to your shop ever waits for the marketplace. That copy refreshes when you open this page. To refresh it on a schedule instead, call this URL from a cron task, for example once an hour:' mod='PrestashopAPI'}
			</p>
			<div class="psapi-copy">
				<input type="text" class="form-control" readonly="readonly" value="{$psapi_cron_url|escape:'html':'UTF-8'}" id="psapi-cron-url" />
				<button type="button" class="btn btn-default" data-psapi-copy="psapi-cron-url">
					<i class="icon icon-copy"></i> {l s='Copy' mod='PrestashopAPI'}
				</button>
			</div>
			<p class="help-block">
				{l s='Keep this URL private: anyone who has it can trigger a refresh. It changes if you reinstall the module.' mod='PrestashopAPI'}
			</p>
		</div>

		<div class="panel">
			<h3><i class="icon icon-trash"></i> {l s='Cached data' mod='PrestashopAPI'}</h3>
			<p>{l s='If something looks wrong, clear the local copy and download everything again.' mod='PrestashopAPI'}</p>
			<a class="btn btn-default" href="{$psapi_base|escape:'html':'UTF-8'}clear">
				<i class="icon icon-eraser"></i> {l s='Clear cached data' mod='PrestashopAPI'}
			</a>
			<a class="btn btn-default" href="{$psapi_base|escape:'html':'UTF-8'}test">
				<i class="icon icon-plug"></i> {l s='Test the connection' mod='PrestashopAPI'}
			</a>
		</div>
	</section>

	{* ============================================================ *}
	{* Help                                                          *}
	{* ============================================================ *}
	<section class="psapi-pane" data-psapi-pane="help">
		<div class="panel">
			<h3><i class="icon icon-life-ring"></i> {l s='How this module works' mod='PrestashopAPI'}</h3>

			<div class="psapi-help-grid">
				<div>
					<h4>{l s='Where the numbers come from' mod='PrestashopAPI'}</h4>
					<p>{l s='Marketplace figures are downloaded from the PrestaShop Addons Seller API with your own API key, and stored locally. Your shop figures are read from your own valid orders. Nothing is sent anywhere else.' mod='PrestashopAPI'}</p>
				</div>
				<div>
					<h4>{l s='Why some products show no shop sales' mod='PrestashopAPI'}</h4>
					<p>{l s='The module needs to know which product in your catalogue is the same as which product on the marketplace. It matches them when the reference of your product equals the marketplace product ID. If you use different references, pin them by hand on the Products tab.' mod='PrestashopAPI'}</p>
				</div>
				<div>
					<h4>{l s='Refunds' mod='PrestashopAPI'}</h4>
					<p>{l s='Refunded units are subtracted from both units and revenue, on both sides, so the totals are what you actually kept.' mod='PrestashopAPI'}</p>
				</div>
				<div>
					<h4>{l s='Currencies' mod='PrestashopAPI'}</h4>
					<p>{l s='Marketplace payouts are usually in euros while your shop may sell in another currency. Combined totals use your shop exchange rate. If your shop has no rate for the marketplace currency, combined totals are hidden rather than guessed.' mod='PrestashopAPI'}</p>
				</div>
				<div>
					<h4>{l s='The storefront badge' mod='PrestashopAPI'}</h4>
					<p>{l s='Optionally, product pages in your own shop can show how many times a product has been downloaded, as social proof. It is drawn from the local copy of your data, costs no extra page time, and hides itself below the threshold you set.' mod='PrestashopAPI'}</p>
				</div>
				<div>
					<h4>{l s='Speed' mod='PrestashopAPI'}</h4>
					<p>{l s='A wide reporting period means more rows to download. If refreshing feels slow, narrow the period or lengthen the refresh interval in Settings.' mod='PrestashopAPI'}</p>
				</div>
			</div>
		</div>

		<div class="panel">
			<h3><i class="icon icon-question-circle"></i> {l s='If something does not work' mod='PrestashopAPI'}</h3>
			<dl class="psapi-faq">
				<dt>{l s='"The marketplace could not be reached"' mod='PrestashopAPI'}</dt>
				<dd>{l s='Your server could not open an outgoing HTTPS connection to the marketplace. Shared hosts often block this. The Status list on the Dashboard tab tells you whether cURL itself is available; if it is, ask your host whether outgoing connections to api.addons.prestashop.com are allowed.' mod='PrestashopAPI'}</dd>

				<dt>{l s='"Your API key looks wrong or has been revoked"' mod='PrestashopAPI'}</dt>
				<dd>{l s='Generate a fresh key in your seller account, under Settings then API, and paste it again. Copy only the key, not the surrounding text.' mod='PrestashopAPI'}</dd>

				<dt>{l s='The figures are older than they should be' mod='PrestashopAPI'}</dt>
				<dd>{l s='Data is cached for the interval set in Settings. Use "Refresh now" at the top of this page to force an immediate download, or set up the cron URL.' mod='PrestashopAPI'}</dd>

				<dt>{l s='Shop sales are zero for every product' mod='PrestashopAPI'}</dt>
				<dd>{l s='None of your marketplace products matched a product in your catalogue. Check the Status list on the Dashboard tab, and pin the products by hand on the Products tab.' mod='PrestashopAPI'}</dd>
			</dl>
		</div>

		<div class="panel">
			<h3><i class="icon icon-code"></i> {l s='What the API returns' mod='PrestashopAPI'}</h3>
			<p class="psapi-lead">
				{l s='The marketplace only documents its products and sales data. Everything else, including conversations, invoices and payouts, is read from fields whose names we have had to infer. This shows one real row from each endpoint of your own account, so the module can be matched to what your account actually sends.' mod='PrestashopAPI'}
			</p>

			{if $psapi_diag}
				<div class="alert alert-warning">
					{l s='This is live data from your seller account and may contain buyer names or e-mail addresses. Remove anything personal before sharing it.' mod='PrestashopAPI'}
				</div>

				{foreach from=$psapi_diag key=endpoint item=json}
					<div class="psapi-diag">
						<div class="psapi-diag-head">
							<code>{$endpoint}</code>
							<button type="button" class="btn btn-default btn-xs" data-psapi-copy="psapi-diag-{$endpoint|md5}">
								<i class="icon icon-copy"></i> {l s='Copy' mod='PrestashopAPI'}
							</button>
						</div>
						<textarea id="psapi-diag-{$endpoint|md5}" class="psapi-diag-body form-control" rows="10" readonly="readonly">{$json}</textarea>
					</div>
				{/foreach}
			{else}
				<a class="btn btn-default" href="{$psapi_config_url|escape:'html':'UTF-8'}&psapi_diag=1#psapi-help">
					<i class="icon icon-search"></i> {l s='Show one sample row from each endpoint' mod='PrestashopAPI'}
				</a>
			{/if}
		</div>

		<div class="panel">
			<h3><i class="icon icon-info-circle"></i> {l s='About' mod='PrestashopAPI'}</h3>
			<p>
				{l s='Seller Dashboard by' mod='PrestashopAPI'}
				<a href="https://www.megventure.com" target="_blank" rel="noopener">MEG Venture</a>
				&mdash; {l s='version' mod='PrestashopAPI'} {$psapi_version}
			</p>
			<p>
				{l s='This module is not affiliated with or endorsed by PrestaShop SA. It uses the public Seller API with the key you provide.' mod='PrestashopAPI'}
			</p>
		</div>
	</section>

</div>

{* At the end of the markup so the script finds the tabs and panes already in the DOM. *}
<script src="{$psapi_module_dir|escape:'html':'UTF-8'}views/js/admin.js?v={$psapi_asset_v|escape:'html':'UTF-8'}"></script>
