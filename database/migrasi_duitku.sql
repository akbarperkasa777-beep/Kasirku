-- ========================================================================
-- Migrasi Integrasi Duitku Payment Gateway untuk POS AkbarMart
-- Lokasi Gerai: Jl. Gajah Mada No. 12, Ponorogo
-- Jalankan file ini pada database db_tokokita di phpMyAdmin / MySQL CLI.
-- ========================================================================
USE db_tokokita;

-- 1. Modifikasi kolom metode_pembayaran pada tbl_transaksi agar mendukung opsi 'duitku'
ALTER TABLE tbl_transaksi
    MODIFY metode_pembayaran ENUM('tunai', 'qris', 'transfer', 'duitku') NOT NULL DEFAULT 'tunai';

-- 2. Modifikasi kolom status_pembayaran pada tbl_transaksi agar mendukung 'menunggu_pembayaran'
ALTER TABLE tbl_transaksi
    MODIFY status_pembayaran ENUM('lunas', 'menunggu_pembayaran', 'menunggu_verifikasi', 'ditolak', 'dibatalkan') NOT NULL DEFAULT 'lunas';

-- 3. Tambahkan kolom merchant_order_id jika belum ada (menyimpan ID pesanan unik Duitku, misal: AKM-12-1718000000)
SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'merchant_order_id') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN merchant_order_id VARCHAR(100) NULL AFTER catatan_pembayaran',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Tambahkan kolom duitku_reference jika belum ada (menyimpan nomor referensi Duitku)
SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_reference') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN duitku_reference VARCHAR(100) NULL AFTER merchant_order_id',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4b. Tambahkan kolom duitku_va_number jika belum ada (menyimpan Nomor Virtual Account resmi Duitku untuk simulasi/pembayaran VA)
SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_va_number') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN duitku_va_number VARCHAR(50) NULL AFTER duitku_reference',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Tambahkan kolom duitku_payment_url jika belum ada (menyimpan URL tagihan Duitku untuk dibuka kembali jika tertutup)
SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'duitku_payment_url') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN duitku_payment_url VARCHAR(500) NULL AFTER duitku_reference',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Tambahkan indeks pada merchant_order_id dan duitku_reference untuk performa cepat pencarian webhook callback
SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND INDEX_NAME = 'idx_transaksi_merchant_order') = 0,
    'ALTER TABLE tbl_transaksi ADD INDEX idx_transaksi_merchant_order (merchant_order_id)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND INDEX_NAME = 'idx_transaksi_duitku_ref') = 0,
    'ALTER TABLE tbl_transaksi ADD INDEX idx_transaksi_duitku_ref (duitku_reference)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
