# TODO List for Modifying SuitStore Manager Pro

## Products Page (products.php)
- [ ] Remove "محصول جدید" button from header
- [ ] Add link to purchases.php in header
- [ ] Modify product list query to show only products with stock > 0
- [ ] Add count of zero stock products in stats
- [ ] Remove add product modal
- [ ] Remove add variant modal
- [ ] Add recharge button for variants (update stock)
- [ ] Create recharge modal for updating stock
- [ ] Update variant table to show recharge instead of edit/delete

## Purchases Page (purchases.php)
- [ ] Remove supplier-related code and functions
- [ ] Simplify form: date, product name (select or new), colors (multiple), sizes per color, purchase price
- [ ] Modify handle_create_purchase to create product/variants if new
- [ ] Update display to group by product with total qty
- [ ] Remove supplier balance calculations

## Sales Page (sales.php)
- [ ] Remove customer select from new sale modal
- [ ] Remove status select from new sale modal
- [ ] Update handle_create_sale to set customer_id=0, status='paid'
- [ ] Remove customer and status from edit sale modal
