# Changelog

## 2.2.0

### Changed

- Conversations on the Messages tab are now sorted by their last message, the same order as the marketplace's own inbox. A buyer writing today in a conversation opened two years ago now appears at the top instead of being buried under newer conversations.
- The "Opened" column is now "Last message". The date the conversation was opened is shown underneath in smaller text.
- Pinned conversations still come first. Unread conversations no longer jump ahead of the others; the Unread filter still lists them.
- In a conversation, each message now says whether it was written by the customer or by you.

### Added

- An "Awaiting reply" filter on the Messages tab, listing every conversation where the buyer wrote last.
- A counter of conversations awaiting your reply on the Messages tab and in the Status list on the Dashboard tab.

### Fixed

- Passwords that buyers paste into a conversation (for example "password: …", "parola: …", "mot de passe: …") are now hidden behind dots when the conversation is shown. Only the display changes: the message itself is not altered.

### Notes

- Last-message dates come from page 1 of the seller/messages endpoint (newest first, up to 5000 rows); conversations not on that page fall back to their opening date. Message bodies are not kept in that bulk cache. The per-conversation map is cached under the last_by_thread key and refreshed by the back office and by the cron URL.
- upgrade-2.2.0.php only empties the threads and allmessages caches and the compiled templates. No table changes.
- New test: tests/ThreadSortTest.php.

## 2.1.2

### Added

- The configuration page links to the free MEG Venture Updates module, which installs updates for this module in one click. The line is hidden once that module is installed.

## 2.1.1

### Fixed

- **The back-office icons turned into empty boxes on PrestaShop 9.** The 42
  icons the module draws for itself came from FontAwesome 4, which the back
  office shipped up to PrestaShop 8. PrestaShop 9 replaced it with Material
  Symbols Outlined, and FontAwesome now reaches the page only through a
  stylesheet left over from the old theme rather than anything the new back
  office asks for.

  An icon-font class does not name a picture; it selects a private-use code
  point that means nothing without that exact font file. So the moment the
  font is not there the browser has nothing to fall back to and draws a
  placeholder box. That makes the failure abrupt rather than gradual, and it
  shows up first on a page load with a freshly cleared asset cache.

  The icons now come from the set the core loads for its own interface, where
  the icon name is the element’s text rather than a class. They are sized
  down from its 24px default and set back to inheriting the surrounding text
  colour, so they sit exactly where the FontAwesome ones did. Nothing in the
  interface moves or changes name.

## 2.1.0

### Added

- A single review-request line on the module's own configuration page. It
  appears at the earliest 21 days after installing, asks once for a short
  review on megventure.com, and disappears forever after a click, a
  "No thanks", or three unanswered views. It makes no outbound request of any
  kind and stores nothing beyond three prefixed configuration values, which
  uninstalling removes.

### Fixed

- **Uninstalling the module could delete another module's settings.** The
  uninstall step still removed three unprefixed configuration names left over
  from v1 as cleanup. Those names carry no module prefix, so if any other module on the
  shop stored a setting under one of them, uninstalling this module silently
  wiped it. The old rows are now left alone — a stale leftover row is
  harmless, deleting another module's setting is not.

## 2.0.0

### Fixed — the module did not run on PHP 8 at all

- A currency-formatting function that PHP 8.0 removed was called on every configuration page load.
- A missing or invalid API key left the module with an empty response that it then tried to
  loop over — so a fresh install fataled the moment it was opened.
- Opening the module crashed with an uncaught error when the server's cURL extension was absent.

### Fixed — silently wrong figures

- Local turnover multiplied the tax-included line total (already a line total) by quantity again;
  a 3-unit line reported 3× its value.
- The local sales query had no join condition, so every order line was paired with every product.
- Refunded quantities were subtracted but never read from the order, so local refunds were never
  actually deducted despite the UI claiming otherwise.
- Invalid and abandoned orders were counted as local sales.
- Product matching used a loose comparison; on PHP 5/7 any non-numeric reference matched marketplace ID 0.
- Marketplace and shop revenue were summed with no currency conversion.
- The settings switch read its saved value with the wrong arguments, so it always rendered "No"
  regardless of the saved value.

### Fixed — security

- **SQL injection** in the storefront: the product id taken from the page address was put into a
  query without being cast to a number.
- cURL performed no SSL verification configuration and had **no timeout**, so an unresponsive
  endpoint could hang a page indefinitely.

### Fixed — performance

- The storefront made **two live API calls fetching up to 10,000 rows each, uncached, per product page
  view, per visitor**. Storefront rendering is now fully decoupled from the API: it reads a
  precomputed map from the shop configuration, costing zero queries and zero HTTP calls.

### Fixed — conversations never loaded at all

- The marketplace's conversation list was read wrongly. The rows sit one level below the paging
  data, which is itself nested inside a named wrapper. The module read the wrapper directly
  and, finding a map, wrapped the paging data itself as a single phantom "conversation" that then
  failed every downstream check. The Messages tab counted 1 and rendered nothing.
- Conversations were looked up by the wrong id field, so every row was silently skipped.
- Pagination was ignored, silently truncating any endpoint past 5000 rows.
- The message cache could never hit: saving it stripped anything that looked like an HTML tag, so a
  buyer message containing a less-than sign truncated the stored data and it never decoded again.
- A failed request rendered identically to an empty account ("no conversations returned").
  Failures, empty results and unrecognised shapes are now three distinct, visible states.

### Fixed — broken features

- Replies could not be sent: every request was signed with an API key that was never set, and
  the message body was SQL-escaped before it was put in an HTTP request.
- `getProducts()` passed an undefined `$post` variable.
- The back-office assets were registered for two names of the same position, so they all
  loaded twice.
- Dates were sent to the marketplace as year/month/day with slashes, while the documented format
  is year-month-day with dashes.

### Added

- Dashboard with KPI cards, a 12-month revenue chart (server-rendered, no JS chart library)
  and a buyer-country breakdown.
- **Messages** tab: buyer conversations with replies sent from the back office, carrying an
  optional attachment (8 MB, extension allow-list, server-side upload, size and type checks,
  real file type read from the file's bytes rather than the browser's declaration).
- **Merchant-owned unread / pinned state** in its own table, with
  All / Unread / Pinned filters, pinned-then-unread-then-newest ordering, and a back-office
  Dashboard notice. Everything starts unread; the merchant sets the baseline once. A buyer reply
  moves the conversation's fingerprint, which returns it to unread automatically while keeping
  its pin. The marketplace exposes no read/status/answered field and no message author, so this
  tracks the merchant's decisions plus genuine change rather than pretending to mirror the
  Addons "unread" badge. It is not kept in the shop configuration: 1843 conversations need ~83KB
  and a configuration value is capped at 64KB.
- The Dashboard widget is cache-only and can never make an admin page wait on the marketplace.
  It now shows only on the Dashboard — its position also exists on other admin pages, and it
  rendered the block twice on the module's own configuration page.
- Message bodies are decoded and reduced to plain text. They arrive as HTML, sometimes
  entity-encoded on top, so escaping them printed the markup and rendering them raw would run
  buyer-supplied script with the merchant's back-office session.
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
- English and Turkish translations (188 strings; 1.x shipped none).

### Changed

- Rebuilt from scratch and renamed to “Seller Dashboard — Marketplace Sales, Messages &
  Payouts” (was “Prestashop Addons Seller Store API Module”).
- **Storefront notice**: the jQuery Growl toast that popped at every visitor is now a static
  inline badge in the product page's additional-information area. It ships **disabled**, with a 10-sale
  threshold, and counts marketplace + shop by default. It is **never shown on free products** —
  a "Downloaded N times" line reads oddly on a giveaway. "Free" uses the price the customer
  actually pays, so a product discounted to zero counts too.
- Combined totals are **hidden** rather than guessed when the shop has no exchange rate for the
  marketplace currency.
- The module's three settings now carry the module's own prefix (migrated automatically).
- Minimum PrestaShop version raised from 1.6 to 1.7.
- Every storefront position is guarded, so an error in the module can no longer white-screen a
  product page.

### Removed

- An unused database table that was never read or written; its install script was never
  called, and its mixed-case name breaks on MySQL servers set to lowercase table names.
- Bundled jQuery Growl (`jquery.growl.js` / `.css`).
- `back.js`, `front.js`, `back.css` — licence headers with no code.
- `upgrade-1.1.0.php` — a `return true` for a version that never shipped.
- Unused SQL and image folders and the old GIF logo.
- `classes/SellerApi.php` — replaced by `SellerApiClient`; it extended `ObjectModel` without a
  `$definition`, for no reason.

Net: 14 code files → 10 (plus 2 translation files and 2 documents that did not exist before),
with substantially more functionality.
