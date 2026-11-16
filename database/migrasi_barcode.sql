-- Add unique, optional product barcodes without changing existing product data.
USE db_tokokita;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_produk' AND COLUMN_NAME = 'kode_barcode') = 0,
    'ALTER TABLE tbl_produk ADD COLUMN kode_barcode VARCHAR(50) NULL AFTER id_kategori',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_produk' AND INDEX_NAME = 'uq_produk_kode_barcode') = 0,
    'ALTER TABLE tbl_produk ADD UNIQUE KEY uq_produk_kode_barcode (kode_barcode)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
