-- Separate the PayPal checkout order ID from the final capture transaction ID.
-- Existing pending PayPal orders currently store their order ID in provider_transaction_id.
-- Move those values before the application starts writing capture IDs there.

SET @column_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pf_payment_orders'
      AND COLUMN_NAME = 'provider_order_id'
);
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE pf_payment_orders ADD COLUMN provider_order_id VARCHAR(190) NULL AFTER checkout_url',
    'SELECT 1'
);
PREPARE pf_stmt FROM @sql;
EXECUTE pf_stmt;
DEALLOCATE PREPARE pf_stmt;

UPDATE pf_payment_orders
SET provider_order_id = provider_transaction_id,
    provider_transaction_id = NULL
WHERE provider = 'PAYPAL'
  AND status IN ('INITIALIZED','PENDING')
  AND provider_transaction_id IS NOT NULL
  AND provider_order_id IS NULL;

SET @index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pf_payment_orders'
      AND INDEX_NAME = 'uq_pf_payment_provider_order_id'
);
SET @sql = IF(
    @index_exists = 0,
    'CREATE UNIQUE INDEX uq_pf_payment_provider_order_id ON pf_payment_orders (provider_order_id)',
    'SELECT 1'
);
PREPARE pf_stmt FROM @sql;
EXECUTE pf_stmt;
DEALLOCATE PREPARE pf_stmt;
