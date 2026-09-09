{*
* 2019-2026 MEG Venture
*
* NOTICE OF LICENSE
*
* This source file is subject to the MIT License
* that is bundled with this package in the file LICENSE.
* It is also available through the world-wide-web at this URL:
* https://opensource.org/licenses/MIT
*
*  @author    MEG Venture
*  @copyright 2019-2026 MEG Venture & Consulting Ltd.
*  @license   https://opensource.org/licenses/MIT MIT License
*}
<style>
{literal}
/* Back-office icons. These used to be FontAwesome 4, which the back office shipped up to
   PrestaShop 8; PrestaShop 9 replaced it with Material Symbols Outlined. An icon-font class
   selects a private-use code point, so the moment the font is not there the browser has
   nothing to fall back to and draws an empty box. These now come from the set the core loads
   for its own interface, which is ligature-based: the icon name is the element's text.
   Sized down from the 24px default and set back to inheriting the text colour, so they sit
   where the FontAwesome ones did. */
.material-icons.mv-ico{font-size:18px;line-height:1;vertical-align:middle;margin-right:4px;}
.material-icons.mv-ico,.material-icons.mv-ico:hover{color:inherit;}
h3 .material-icons.mv-ico,
.panel-heading .material-icons.mv-ico{font-size:20px;}
.btn .material-icons.mv-ico{font-size:16px;margin-right:3px;}
{/literal}
</style>

{* Only back-office Bootstrap classes here, no psapi-* ones: this renders on the Dashboard
   controller, where the module's admin.css is never loaded. Custom classes would match
   nothing and the block would appear unstyled next to its siblings. *}
{if $psapi_unread > 0}
	<div class="alert alert-warning">
		<i class="material-icons mv-ico">forum</i>
		{if $psapi_unread == 1}
			<strong>{l s='1 marketplace conversation is waiting for you' mod='PrestashopAPI'}</strong>
		{else}
			<strong>{$psapi_unread} {l s='marketplace conversations are waiting for you' mod='PrestashopAPI'}</strong>
		{/if}
		&mdash; {l s='out of' mod='PrestashopAPI'} {$psapi_total} {l s='in total.' mod='PrestashopAPI'}
		{if $psapi_pinned > 0}
			<span class="text-muted">({$psapi_pinned} {l s='pinned' mod='PrestashopAPI'})</span>
		{/if}
		<a class="btn btn-primary btn-sm" href="{$psapi_messages_url|escape:'html':'UTF-8'}">
			{l s='Open them' mod='PrestashopAPI'}
		</a>
	</div>
{else}
	<div class="alert alert-info">
		<i class="material-icons mv-ico">check</i>
		{l s='No marketplace conversation is waiting for a reply.' mod='PrestashopAPI'}
		<span class="text-muted">({$psapi_total} {l s='conversations tracked' mod='PrestashopAPI'})</span>
		<a class="btn btn-default btn-sm" href="{$psapi_messages_url|escape:'html':'UTF-8'}">
			{l s='Open Seller Dashboard' mod='PrestashopAPI'}
		</a>
	</div>
{/if}
