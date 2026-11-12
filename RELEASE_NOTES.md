# Seller Dashboard - Marketplace Sales, Messages and Payouts — Release notes

_Only customer-relevant changes are listed. Internal refactoring, tests and housekeeping are omitted._

## 2.3.0

### New
- Multistore support. With several shops, the marketplace figures behind the product badge and the last-updated date are stored once for the whole installation, because they belong to the seller account: calling the cron URL from any shop now refreshes them for every shop
- A notice on the configuration page when you are in the All shops or shop group context, explaining that settings saved there are the default for every shop, that one seller account normally serves all shops, and how the badge and the dashboard count orders

### Fixed
- With several shops, the "Marketplace + this shop" badge counted the orders of every shop. It now counts only the orders of the shop being visited
- With several shops, a product pinned by hand on the Products tab could show the name of another shop's language row. The name now comes from the current shop

## 2.2.0

### New
- An "Awaiting reply" filter on the Messages tab, listing every conversation where the buyer wrote last
- A counter of conversations awaiting your reply on the Messages tab and in the Status list on the Dashboard tab

### Improved
- Conversations on the Messages tab are now sorted by their last message, the same order as the marketplace's own inbox. A buyer writing today in a conversation opened two years ago now appears at the top instead of being buried under newer conversations
- The "Opened" column is now "Last message". The date the conversation was opened is shown underneath in smaller text
- Pinned conversations still come first. Unread conversations no longer jump ahead of the others; the Unread filter still lists them
- In a conversation, each message now says whether it was written by the customer or by you

### Fixed
- Passwords that buyers paste into a conversation (for example "password: …", "parola: …", "mot de passe: …") are now hidden behind dots when the conversation is shown. Only the display changes: the message itself is not altered

## 2.1.2

### New
- The configuration page links to the free MEG Venture Updates module, which installs updates for this module in one click. The line is hidden once that module is installed
