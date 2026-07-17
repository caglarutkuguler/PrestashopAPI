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
{if $psapi_unread > 0}
	<div class="alert alert-warning">
		<i class="icon icon-comments"></i>
		{if $psapi_unread == 1}
			<strong>{l s='1 marketplace conversation has new activity' mod='PrestashopAPI'}</strong>
		{else}
			<strong>{$psapi_unread} {l s='marketplace conversations have new activity' mod='PrestashopAPI'}</strong>
		{/if}
		&mdash; {l s='out of' mod='PrestashopAPI'} {$psapi_total} {l s='in total.' mod='PrestashopAPI'}
		<a class="btn btn-primary btn-sm" href="{$psapi_messages_url|escape:'html':'UTF-8'}">
			{l s='Read them' mod='PrestashopAPI'}
		</a>
	</div>
{else}
	<div class="alert alert-info">
		<i class="icon icon-check"></i>
		{l s='No marketplace conversation has new activity.' mod='PrestashopAPI'}
		<span class="text-muted">({$psapi_total} {l s='conversations tracked' mod='PrestashopAPI'})</span>
		<a class="btn btn-default btn-sm" href="{$psapi_messages_url|escape:'html':'UTF-8'}">
			{l s='Open Seller Dashboard' mod='PrestashopAPI'}
		</a>
	</div>
{/if}
