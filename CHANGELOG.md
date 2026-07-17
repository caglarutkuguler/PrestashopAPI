# Changelog

## 2.0.0

Complete rewrite. Renamed **Seller Dashboard — Marketplace Sales, Messages & Payouts**
(was *Prestashop Addons Seller Store API Module*).

### Fixed — the module did not run on PHP 8 at all

- `money_format()`, removed in PHP 8.0, was called on every configuration page load.
- A missing/invalid API key made `json_decode()` return `null`, which was then passed straight
  to `foreach` — so a fresh install fataled the moment it was opened.
- Uncaught `Exception` thrown from `SellerApi::__construct()` when cURL was absent.

### Fixed — silently wrong figures

- Local turnover multiplied `total_price_tax_incl` (already a line total) by quantity again;
  a 3-unit line reported 3× its value.
- Local sales query had no join condition — a cartesian product of `order_detail` × `product`.
- `product_quantity_refunded` was subtracted but never selected, so local refunds were never
  actually deducted despite the UI claiming otherwise.
- Invalid and abandoned orders were counted as local sales.
- Product matching used `==`; on PHP 5/7 any non-numeric reference matched marketplace ID `0`.
- Marketplace and shop revenue were summed with no currency conversion.
- The settings switch used `Configuration::get($key, true)` — arg 2 is `$id_lang`, not a
  default — so it always rendered "No" regardless of the saved value.

### Fixed — security

- **SQL injection** in the storefront hook: `Tools::getValue('id_product')` was interpolated
  into a query uncast.
- cURL performed no SSL verification configuration and had **no timeout**, so an unresponsive
  endpoint could hang a page indefinitely.

### Fixed — performance

- The storefront made **two live API calls with `limit => 10000`, uncached, per product page
  view, per visitor**. Storefront rendering is now fully decoupled from the API: it reads a
  precomputed map from `Configuration`, costing zero queries and zero HTTP calls.

### Fixed — conversations never loaded at all

- The response wrapper was read wrongly. `seller/threads` answers
  `{"success":true,"threads":{…paginator…,"data":[…rows…]}}` — the paginator is nested *inside*
  the named wrapper, and the rows are one level below that. The module read `threads` directly
  and, finding a map, wrapped the paginator itself as a single phantom "conversation" that then
  failed every downstream check. The Messages tab counted 1 and rendered nothing.
- The thread id is `id_community_thread`, not `id_thread`; every row was silently skipped.
- Pagination was ignored, silently truncating any endpoint past 5000 rows.
- `pSQL()` was used on the cached JSON payload. It runs `strip_tags(nl2br())` internally, which
  rewrites the *content*: a buyer writing `Works on PS <8 only` truncated the stored JSON so it
  never decoded again, and the cache could never hit. Fixed with `pSQL($json, true)`.
- A failed request rendered identically to an empty account ("no conversations returned").
  Failures, empty results and unrecognised shapes are now three distinct, visible states.

### Fixed — broken features

- `sendMessage()` signed every request with a `private static $api_key = ''` that was never
  assigned, and SQL-escaped the message body before putting it in an HTTP request.
- `getProducts()` passed an undefined `$post` variable.
- `backOfficeHeader` and `displayBackOfficeHeader` were both registered — they are aliases, so
  all assets loaded twice.
- Date parameters were sent as `Y/m/d` while the documented format is `YYYY-MM-DD`.

### Added

- Dashboard with KPI cards, a 12-month revenue chart (server-rendered, no JS chart library)
  and a buyer-country breakdown.
- **Messages** tab: buyer conversations with replies sent from the back office, carrying an
  optional attachment (8 MB, extension allow-list, `is_uploaded_file` + server-side size and
  type checks, real MIME read from the file's bytes rather than the browser's declaration).
- **New-conversation flags and a back-office Dashboard notice** telling you when buyers are
  waiting, with per-conversation and "mark all as read" clearing. Conversations are fingerprinted
  whole rather than read through a "status"/"unanswered" field, because the threads endpoint
  documents none — so the feature reports *new to you*, not *unanswered*. The Dashboard widget
  is cache-only and can never make an admin page wait on the marketplace.
- **Payouts** tab: marketplace invoices.
- **Products** tab: manual product matching, replacing the 1.x instruction to rename your
  references to match marketplace IDs.
- Reporting-period selector (30 days → all time, or custom dates) replacing two unvalidated
  free-text date fields.
- Database-backed response cache with a configurable TTL, and stale-while-unreachable fallback
  so a marketplace outage shows yesterday's figures instead of an error.
- Token-protected cron endpoint for scheduled refreshes.
- CSV export of sales, with formula-injection neutralisation.
- Connection test, and real health checks for cURL, API key, product matching and currency.
- Three-step onboarding shown until a key is saved.
- Help tab: how it works, plus troubleshooting.
- `en` and `tr` translations (188 strings; 1.x shipped none).

### Changed

- **Storefront notice**: the jQuery Growl toast that popped at every visitor is now a static
  inline badge on `displayProductAdditionalInfo`. It ships **disabled**, with a 10-sale
  threshold, and counts marketplace + shop by default.
- Combined totals are **hidden** rather than guessed when the shop has no exchange rate for the
  marketplace currency.
- Config keys `PRODUCT_PAGE_FRONT_ENABLE`, `API_DATE_FROM`, `API_DATE_TO` are now prefixed
  `PRESTASHOPAPI_*` (migrated automatically).
- Minimum PrestaShop version raised from 1.6 to 1.7.
- Every storefront hook body is wrapped in `catch (Throwable)` — an undefined method is an
  `Error`, not an `Exception`, and would otherwise white-screen a product page.

### Removed

- `ps_PrestashopAPI` table — an auto-increment id and nothing else, never read or written; its
  install script was never called, and its mixed-case name breaks on MySQL with
  `lower_case_table_names` enabled.
- Bundled jQuery Growl (`jquery.growl.js` / `.css`).
- `back.js`, `front.js`, `back.css` — licence headers with no code.
- `upgrade-1.1.0.php` — a `return true` for a version that never shipped.
- `sql/`, `views/img/`, `logo.gif`.
- `classes/SellerApi.php` — replaced by `SellerApiClient`; it extended `ObjectModel` without a
  `$definition`, for no reason.

Net: 14 code files → 10 (plus 2 translation files and 2 documents that did not exist before),
with substantially more functionality.
