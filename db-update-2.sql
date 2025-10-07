-- Indexes to optimize supplier-based reporting
ALTER TABLE Purchases
    ADD INDEX idx_purchases_supplier_date (supplier_id, purchase_date);

ALTER TABLE Purchase_Returns
    ADD INDEX idx_purchase_returns_supplier_date (supplier_id, return_date);
