-- Add optional product image filename without changing existing product data.
USE db_tokokita;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_produk' AND COLUMN_NAME = 'gambar') = 0,
    'ALTER TABLE tbl_produk ADD COLUMN gambar VARCHAR(255) NULL AFTER kode_barcode',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
