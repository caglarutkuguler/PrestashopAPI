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

{* Only back-office Bootstrap classes here, no psapi-* ones: this renders on the Dashboard
   controller, where the module's admin.css is never loaded. Custom classes would match
   nothing and the block would appear unstyled next to its siblings. *}
<div class="alert alert-info">
	<i class="icon icon-comments"></i>
	{if $psapi_unread == 1}
		<strong>{l s='One buyer is waiting for a reply' mod='PrestashopAPI'}</strong>
		&mdash; {l s='a marketplace conversation has new activity.' mod='PrestashopAPI'}
	{else}
		<strong>{$psapi_unread} {l s='buyers are waiting for a reply' mod='PrestashopAPI'}</strong>
		&mdash; {l s='marketplace conversations have new activity.' mod='PrestashopAPI'}
	{/if}
	<a class="btn btn-primary btn-sm" href="{$psapi_messages_url|escape:'html':'UTF-8'}">
		{l s='Read them' mod='PrestashopAPI'}
	</a>
</div>
