# Seller Dashboard - Marketplace Sales, Messages and Payouts — Release notes

_Only customer-relevant changes are listed. Internal refactoring, tests and housekeeping are omitted._

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

## 2.1.1

### Fixed
- The back-office icons turned into empty boxes on PrestaShop 9. The 42 icons the module draws for itself came from FontAwesome 4, which the back office shipped up to PrestaShop 8. PrestaShop 9 replaced it with Material Symbols Outlined, and FontAwesome now reaches the page only through a stylesheet left over from the old theme rather than anything the new back office asks for
