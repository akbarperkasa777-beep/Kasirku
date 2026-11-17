-- ========================================================================
-- Migrasi Database AkbarMart: Penambahan Fitur Auto Member Discount via RFID
-- Kolom: id_card_rfid (VARCHAR(50), UNIQUE, NULLABLE)
-- Kolom: status_member (ENUM('aktif', 'nonaktif'), DEFAULT 'aktif')
-- ========================================================================

USE db_tokokita;

-- 1. Tambahkan kolom id_card_rfid jika belum ada
SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_pelanggan' AND COLUMN_NAME = 'id_card_rfid') = 0,
    'ALTER TABLE tbl_pelanggan ADD COLUMN id_card_rfid VARCHAR(50) NULL AFTER kota',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Tambahkan unique constraint untuk id_card_rfid jika belum ada
SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_pelanggan' AND INDEX_NAME = 'uq_pelanggan_id_card_rfid') = 0,
    'ALTER TABLE tbl_pelanggan ADD UNIQUE KEY uq_pelanggan_id_card_rfid (id_card_rfid)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Tambahkan kolom status_member jika belum ada
SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_pelanggan' AND COLUMN_NAME = 'status_member') = 0,
    'ALTER TABLE tbl_pelanggan ADD COLUMN status_member ENUM(\'aktif\', \'nonaktif\') NOT NULL DEFAULT \'aktif\' AFTER id_card_rfid',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Tambahkan data sample member untuk pengujian RFID
INSERT INTO tbl_pelanggan (nama_pelanggan, kota, id_card_rfid, status_member)
SELECT 'Budi Santoso (Member)', 'Ponorogo', 'RFID-001', 'aktif'
WHERE NOT EXISTS (SELECT 1 FROM tbl_pelanggan WHERE id_card_rfid = 'RFID-001');

INSERT INTO tbl_pelanggan (nama_pelanggan, kota, id_card_rfid, status_member)
SELECT 'Siti Rahmawati (Member)', 'Madiun', 'RFID-002', 'aktif'
WHERE NOT EXISTS (SELECT 1 FROM tbl_pelanggan WHERE id_card_rfid = 'RFID-002');

INSERT INTO tbl_pelanggan (nama_pelanggan, kota, id_card_rfid, status_member)
SELECT 'Ahmad Fauzi (Member Nonaktif)', 'Magetan', 'RFID-003', 'nonaktif'
WHERE NOT EXISTS (SELECT 1 FROM tbl_pelanggan WHERE id_card_rfid = 'RFID-003');
