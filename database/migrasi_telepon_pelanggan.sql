-- Add customer phone numbers for the member management page.
USE db_tokokita;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'tbl_pelanggan'
       AND COLUMN_NAME = 'no_telepon') = 0,
    'ALTER TABLE tbl_pelanggan ADD COLUMN no_telepon VARCHAR(20) NULL AFTER nama_pelanggan',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
