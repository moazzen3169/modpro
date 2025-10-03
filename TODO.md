# TODO: Prevent Negative Quantities in Sales Form

- [x] Add server-side validation in create_sale POST handler to reject negative or zero quantities
- [x] Add server-side validation in add_sale_item POST handler to reject negative or zero quantities
- [x] Add server-side validation in edit_sale_item POST handler to reject negative or zero quantities
- [x] In display code, ensure no negative quantities are shown (replace with zero or hide)
- [x] In JavaScript addItemToSale() function, add check to prevent adding items with negative or zero quantity

# TODO: Add Purchase Creation Form

- [x] Update sidebar link from "مشاهده خریدها" to "خریدها"
- [x] Add "New Purchase" button in header
- [x] Add create_purchase POST handler with validation and stock increase
- [x] Add purchase creation modal with supplier selection, date, payment, items
- [x] Add JavaScript for modal functionality and item selection
- [x] Test purchase creation and verify stock updates
