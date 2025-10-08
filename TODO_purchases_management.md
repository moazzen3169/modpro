# TODO: Add Edit/Delete for Purchase Variants in purchases.php

## Steps to Complete

- [x] Modify get_all_purchase_items.php to include purchase_id and variant_id in JSON response
- [x] Update detailed modal in purchases.php to add "Operations" column with Edit/Delete buttons
- [x] Add Edit Purchase Item modal in purchases.php
- [x] Add JavaScript functions for handling edit and delete actions
- [x] Add PHP POST handler for 'edit_purchase_item' action (update quantity, buy_price, adjust stock)
- [x] Add PHP POST handler for 'delete_purchase_item' action (delete quantity, buy_price, adjust stock)
- [ ] Test the functionality to ensure stock updates correctly
