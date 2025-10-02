# TODO: Prevent Negative Quantities in Sales Form

- [x] Add server-side validation in create_sale POST handler to reject negative or zero quantities
- [x] Add server-side validation in add_sale_item POST handler to reject negative or zero quantities
- [x] Add server-side validation in edit_sale_item POST handler to reject negative or zero quantities
- [x] In display code, ensure no negative quantities are shown (replace with zero or hide)
- [x] In JavaScript addItemToSale() function, add check to prevent adding items with negative or zero quantity
