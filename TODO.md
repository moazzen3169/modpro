# TODO: Fix Returns Stock Logic

## Tasks
- [x] Change stock update in handle_create_return from + to -
- [x] Change stock update in handle_delete_return from - to +

## Details
- In returns.php, handle_create_return currently adds to stock, but should subtract.
- In returns.php, handle_delete_return currently subtracts, but should add to restore stock.
