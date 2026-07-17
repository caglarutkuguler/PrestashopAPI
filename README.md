# Seller Dashboard — Marketplace Sales, Messages & Payouts

See how your PrestaShop Addons marketplace products are selling without leaving your own back
office — combined with the sales of the same products in your own shop.

Built for PrestaShop Addons contributors, by a contributor. Free and open source.

---

## What it does

| | |
|---|---|
| **Dashboard** | Revenue, units, refunds, average per order, last 30 days, and a 12-month revenue chart. |
| **Products** | Every marketplace product with its marketplace sales *and* your own shop's sales for the same product, side by side. |
| **Sales** | Every sale line, filterable, exportable to CSV for your accountant. |
| **Messages** | Your buyer conversations, with replies — and file attachments — sent straight from your back office. New conversations are flagged, and the back-office Dashboard tells you when buyers are waiting. |
| **Payouts** | Your marketplace invoices. |
| **Storefront badge** | Optional social-proof badge on your own product pages: "Downloaded 1 234 times". |

Everything is read-only against your seller account. The module never publishes, changes or
removes anything on the marketplace. The only write it performs is a reply you type yourself
on the Messages tab.

## Requirements

- PrestaShop **1.7, 8.x or 9.x**
- PHP 7.1+ (works on 8.x)
- The **cURL** PHP extension
- Outgoing HTTPS access to `api.addons.prestashop.com`
- A PrestaShop Addons **seller** account

The module checks all of these itself and tells you on the Dashboard tab which one is missing.
You do not have to go looking.

## Quick start

1. Install the module and open its configuration page.
2. Follow the three steps shown on screen to fetch your API key
   (seller account → **Settings** → **API** → *Get my API key*).
3. Paste the key into the **Settings** tab and save.

That is it. Your sales appear immediately.

## Settings

| Setting | Default | Notes |
|---|---|---|
| API key | *(empty)* | Validated on save; obvious paste mistakes are rejected with an explanation. |
| Reporting period | Last 12 months | 30 days / 3 / 6 / 12 months / this year / all time / custom dates. |
| Marketplace currency | `EUR` | Used to convert marketplace revenue into your shop currency. |
| Refresh data every | 60 minutes | How long the local copy of your data is kept before re-downloading. |
| Storefront badge | Off | Shows download counts on your own product pages. |
| Badge counts | Marketplace + this shop | Or marketplace only. |
| Hide the badge below | 10 sales | Low numbers are social proof *against* you. |

## Matching your products to the marketplace

To combine marketplace sales with your own shop's sales, the module has to know which of your
products is which on the marketplace.

By default it matches **your product's reference** to the **marketplace product ID**. If your
references are different, you do not have to rename anything: open the **Products** tab and pick
the right product from the dropdown next to each row. The Dashboard tells you how many matched
(`4 / 6 marketplace products are matched…`), so you always know whether the totals are complete.

## Buyer messages

The **Messages** tab lists your marketplace conversations and lets you reply without leaving
your shop. Replies can carry one attachment (up to 8 MB — jpg, png, gif, webp, bmp, pdf, txt,
log, csv, zip, doc, docx, xls, xlsx). Size and type are checked on the server, not just in the
browser.

**"New" flags and the Dashboard notice.** When a conversation has activity you have not looked
at, it is flagged *New*, the Messages tab shows a red count, and the back-office Dashboard shows
a notice. Opening a conversation clears its flag; **Mark all as read** clears the lot.

One honest limitation: the module reports conversations that are **new to you**, not ones that
are *unanswered*. The Seller API does not document a field for "awaiting seller reply", so
rather than reading a field that might not exist, the module fingerprints each conversation and
re-flags it whenever anything in it changes — which a new buyer message necessarily does. The
practical difference: on first install everything is flagged, because you have not reviewed any
of it here yet. Hit **Mark all as read** once and it settles.

The Dashboard notice reads only the local copy of your data — it never calls the marketplace, so
it cannot slow your back office down. It refreshes with everything else.

## Keeping data fresh automatically

Your storefront never calls the marketplace — visitors read a local copy, so a slow or offline
marketplace can never slow down your shop. That copy refreshes whenever you open the module's
configuration page.

If you want it to refresh on its own, the **Settings** tab shows a private cron URL. Call it
once an hour:

```
0 * * * * wget -q -O /dev/null "https://your-shop.com/module/PrestashopAPI/cron?token=..."
```

Keep that URL private, and note that it changes if you reinstall the module.

## Troubleshooting

**"The marketplace could not be reached."**
Your server could not open an outgoing HTTPS connection. Shared hosts often block this. Check
the Status list on the Dashboard tab: it tells you whether cURL itself is available. If it is,
ask your host whether outgoing connections to `api.addons.prestashop.com` are allowed.

**"Your API key looks wrong or has been revoked."**
Generate a fresh key in your seller account under **Settings → API** and paste it again. Copy
only the key, not the surrounding text or the page URL.

**Shop sales show zero for every product.**
None of your marketplace products matched a product in your catalogue. See
*Matching your products* above — the Dashboard's Status list confirms the count.

**Combined totals show "n/a".**
Your shop currency differs from your marketplace currency and your shop has no exchange rate
for the marketplace currency. Add that currency under **International → Currencies**, or set
*Marketplace currency* to match your shop. The module hides the combined figure rather than
adding euros to dollars.

**The figures look stale.**
Data is cached for the interval set in Settings. Use **Refresh now** at the top of the page, or
set up the cron URL.

**Nothing renders / a blank configuration page.**
That was a 1.x symptom on PHP 8 and is fixed — see below. If you still see it, you are running
1.x; upgrade.

## Upgrading from 1.x

Install over the top. The upgrade script migrates your settings, deletes the obsolete 1.x files
and removes the unused database table. Two changes are deliberate and worth knowing:

- **Your API key is preserved**, but the *Date from* / *Date to* fields become a
  **Reporting period** dropdown. If you had dates set, the period is set to *Custom dates* and
  your dates are kept. Values that were not valid dates are cleared (1.x never validated them,
  so an unparseable date silently shrank your reporting window without saying so).
- **The storefront notice changed.** 1.x popped a jQuery Growl toast at every visitor on every
  product page. 2.0 renders a static inline badge instead, and it starts **disabled** with a
  10-sale threshold. Turn it on in Settings if you want it.

## What was wrong with 1.x

Documented because some of it silently corrupted the numbers rather than failing loudly.

**Fatal on PHP 8**
- `money_format()` was removed in PHP 8.0. 1.x called it on every configuration page load, so
  the page was a hard fatal on any modern server.
- With no API key saved, the API returned an error, `json_decode` returned `null`, and
  `foreach ($products['products'])` fataled — meaning a fresh install crashed on first open.

**Wrong numbers**
- Local turnover multiplied `total_price_tax_incl` (already a line total) by the quantity
  again, so a 3-unit line reported 3× its real value.
- The local sales query had no join condition (`FROM order_detail a, product b WHERE
  a.product_id = <id>`), producing a cartesian product of every order line against every
  product.
- It subtracted a `product_quantity_refunded` column it never selected, so despite the on-screen
  claim that refunds were accounted for, local refunds were never subtracted.
- Sales counted invalid and abandoned orders.
- Product matching used `==`, so on PHP 5/7 every non-numeric reference matched marketplace
  ID `0`.
- Marketplace revenue was added to shop revenue with no currency conversion, under a banner
  telling you to assume the currencies matched.

**Security**
- The storefront hook interpolated `Tools::getValue('id_product')` into SQL **uncast**
  (`WHERE a.product_id = ' . $id_product`) — an injection point reachable by any visitor.

**Performance**
- The storefront hook made **two live API calls with `limit => 10000`, uncached, with no
  timeout, on every product page view for every visitor**. A slow marketplace meant a slow shop;
  cURL had no timeout at all, so an unresponsive endpoint could hang a page indefinitely.

**Broken by construction**
- `sendMessage()` signed requests with a `private static $api_key = ''` that was never assigned,
  so every reply went out with an empty key. It also ran the message through a hand-rolled SQL
  escaper before putting it in an HTTP body.
- `getProducts()` passed an undefined `$post` variable.
- The settings switch read `Configuration::get($key, true)` — the second argument is
  `$id_lang`, not a default — so the toggle always displayed "No" no matter what was saved.
- `backOfficeHeader` and `displayBackOfficeHeader` were both registered. They are aliases, so
  every asset loaded twice.
- Config keys `PRODUCT_PAGE_FRONT_ENABLE`, `API_DATE_FROM` and `API_DATE_TO` were unprefixed
  and sat in the global namespace where any module could collide with them.

**Dead weight**
- An empty `ps_PrestashopAPI` table with nothing but an auto-increment id, never read, never
  written, and whose mixed-case name breaks on MySQL with `lower_case_table_names` enabled.
  (Its install script was never even called.)
- `back.js`, `front.js`, `back.css`, `front.css` — four files containing only a licence header.
- An `upgrade-1.1.0.php` for a version that never shipped, whose body was `return true`.
- Bundled jQuery Growl, for one toast.

The README also told you to **edit `views/admin/tabs.tpl` and comment out lines** to hide
columns you did not want. That path did not exist (the file was at
`views/templates/admin/tabs.tpl`), and editing module source is not a feature. Use the filter
box and the CSV export instead.

## Uninstalling

Removes your API key, product matches, cached data and the cache table. Your marketplace sales
history is untouched — this module only ever read it.

## Privacy

Your API key is stored in your own shop's configuration and sent only to
`api.addons.prestashop.com`. Marketplace data is cached in your own database. Nothing is sent
to MEG Venture or anywhere else.

## Not affiliated

This module is not affiliated with or endorsed by PrestaShop SA. It uses the public Seller API
with the key you provide.

---

[MEG Venture](https://www.megventure.com) · AFL-3.0 · Contributions welcome at
[github.com/caglarutkuguler/PrestashopAPI](https://github.com/caglarutkuguler/PrestashopAPI)
