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

{* v1 popped a jQuery Growl toast at every visitor on page load. This is a static element:
   no library, no JavaScript, no layout shift. *}
<div class="psapi-proof">
	<span class="psapi-proof-icon" aria-hidden="true">
		<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
			<path d="M8 10.5L3.5 6h9L8 10.5z" fill="currentColor"/>
			<path d="M2 13h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
			<path d="M8 2v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
		</svg>
	</span>
	<span class="psapi-proof-text">
		{l s='Downloaded' mod='PrestashopAPI'}
		<strong>{$psapi_units_formatted}</strong>
		{l s='times by customers like you' mod='PrestashopAPI'}
	</span>
</div>
