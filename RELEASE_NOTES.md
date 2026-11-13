# Seller Dashboard - Marketplace Sales, Messages and Payouts — Release notes

_Only customer-relevant changes are listed. Internal refactoring, tests and housekeeping are omitted._

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
