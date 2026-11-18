# Seller Dashboard - Marketplace Sales, Messages and Payouts — Release notes

_Only customer-relevant changes are listed. Internal refactoring, tests and housekeeping are omitted._

## 2.5.0

### Improved
- Marketplace products are now matched by the MPN field of your product (Catalog > Products > Details > References). Your reference is free again for your own SKU scheme
- A product with an empty MPN is still matched by its reference, so shops set up with earlier versions keep working without changes
- The product lists on the Products tab show the MPN next to the product name, or the reference when there is no MPN

## 2.4.0

### New
- The Dashboard notice lists the newest five waiting conversations by subject, product and date, each linking straight to the conversation

### Improved
- Answering a buyer, or opening their message, on the marketplace now settles the conversation in the module too, at the next refresh. "Mark as read" and "Mark as unread" still work and hold until a new message arrives in that conversation
- The waiting count now follows every refresh, including the cron URL and the automatic refresh when the cached data expires. It used to move only when you clicked Refresh
- On the first refresh after this update the old backlog is cleared once: only conversations whose last message is an unseen buyer message from the last 30 days stay unread. Pinned conversations stay pinned

### Fixed
- The back-office Dashboard could say that hundreds of marketplace conversations were waiting for you when none were. A conversation used to go back to unread whenever anything about it changed, including your own replies on the marketplace and a renamed product. It is now unread only when the buyer wrote the last message and you have not seen it yet
- After replying from the module, the conversation could come back as unread until the next refresh
- Subjects, product names and shop addresses in the conversation list are now shown as plain text; an apostrophe could appear as "&#039;"

## 2.3.0

### New
- Multistore support. With several shops, the marketplace figures behind the product badge and the last-updated date are stored once for the whole installation, because they belong to the seller account: calling the cron URL from any shop now refreshes them for every shop
- A notice on the configuration page when you are in the All shops or shop group context, explaining that settings saved there are the default for every shop, that one seller account normally serves all shops, and how the badge and the dashboard count orders

### Fixed
- With several shops, the "Marketplace + this shop" badge counted the orders of every shop. It now counts only the orders of the shop being visited
- With several shops, a product pinned by hand on the Products tab could show the name of another shop's language row. The name now comes from the current shop
