# Seller Dashboard - Marketplace Sales, Messages and Payouts — Release notes

_Only customer-relevant changes are listed. Internal refactoring, tests and housekeeping are omitted._

## 2.1.2

### New
- The configuration page links to the free MEG Venture Updates module, which installs updates for this module in one click. The line is hidden once that module is installed

## 2.1.1

### Fixed
- The back-office icons turned into empty boxes on PrestaShop 9. The 42 icons the module draws for itself came from FontAwesome 4, which the back office shipped up to PrestaShop 8. PrestaShop 9 replaced it with Material Symbols Outlined, and FontAwesome now reaches the page only through a stylesheet left over from the old theme rather than anything the new back office asks for

## 2.1.0

### New
- A single review-request line on the module's own configuration page. It appears at the earliest 21 days after installing, asks once for a short review on megventure.com, and disappears forever after a click, a "No thanks", or three unanswered views. It makes no outbound request of any kind and stores nothing beyond three prefixed configuration values, which uninstalling removes

### Fixed
- Uninstalling the module could delete another module's settings. The uninstall step still removed three unprefixed configuration names left over from v1 as cleanup. Those names carry no module prefix, so if any other module on the shop stored a setting under one of them, uninstalling this module silently wiped it. The old rows are now left alone — a stale leftover row is harmless, deleting another module's setting is not
