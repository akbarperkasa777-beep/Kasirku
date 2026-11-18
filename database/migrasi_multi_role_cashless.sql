-- Extend existing staff accounts and transactions for customer self-checkout.
-- Run after migrasi_user.sql, migrasi_member_rfid.sql, and migrasi_telepon_pelanggan.sql.
USE db_tokokita;

ALTER TABLE tbl_user
    MODIFY role ENUM('admin', 'kasir', 'pelanggan') NOT NULL;

SET @customer_id_type = (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbl_pelanggan'
      AND COLUMN_NAME = 'id_pelanggan'
    LIMIT 1
);
SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'tbl_user'
       AND COLUMN_NAME = 'id_pelanggan') = 0,
    CONCAT('ALTER TABLE tbl_user ADD COLUMN id_pelanggan ', @customer_id_type, ' NULL AFTER role'),
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'tbl_user'
       AND INDEX_NAME = 'uq_tbl_user_pelanggan') = 0,
    'ALTER TABLE tbl_user ADD UNIQUE KEY uq_tbl_user_pelanggan (id_pelanggan)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'tbl_user'
       AND CONSTRAINT_NAME = 'fk_tbl_user_pelanggan') = 0,
    'ALTER TABLE tbl_user ADD CONSTRAINT fk_tbl_user_pelanggan FOREIGN KEY (id_pelanggan) REFERENCES tbl_pelanggan(id_pelanggan) ON UPDATE CASCADE ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'metode_pembayaran') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN metode_pembayaran ENUM(\'tunai\', \'qris\', \'transfer\') NOT NULL DEFAULT \'tunai\'',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'status_pembayaran') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN status_pembayaran ENUM(\'lunas\', \'menunggu_verifikasi\', \'ditolak\', \'dibatalkan\') NOT NULL DEFAULT \'lunas\'',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE tbl_transaksi
    MODIFY status_pembayaran ENUM('lunas', 'menunggu_verifikasi', 'ditolak', 'dibatalkan') NOT NULL DEFAULT 'lunas';

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'simulasi_pembayaran') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN simulasi_pembayaran TINYINT(1) NOT NULL DEFAULT 0 AFTER status_pembayaran',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'bukti_pembayaran') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN bukti_pembayaran VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'tanggal_pembayaran') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN tanggal_pembayaran DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'ditinjau_oleh') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN ditinjau_oleh INT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'tanggal_ditinjau') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN tanggal_ditinjau DATETIME NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'catatan_pembayaran') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN catatan_pembayaran VARCHAR(255) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'tbl_transaksi'
       AND INDEX_NAME = 'idx_transaksi_status_tanggal') = 0,
    'ALTER TABLE tbl_transaksi ADD INDEX idx_transaksi_status_tanggal (status_pembayaran, tanggal)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
