-- Non-destructive migration for an existing db_tokokita installation.
USE db_tokokita;

-- This migration only adds/extends columns; it does not drop existing tables or rows.
ALTER TABLE tbl_produk MODIFY nama_produk VARCHAR(150) NOT NULL;
ALTER TABLE tbl_kategori MODIFY nama_kategori VARCHAR(100) NOT NULL;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'diskon') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN diskon DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'uang_bayar') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN uang_bayar DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'kembalian') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN kembalian DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'nama_kasir') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN nama_kasir VARCHAR(100) NOT NULL DEFAULT ''Kasir''',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND DATA_TYPE = 'date') > 0,
    'ALTER TABLE tbl_transaksi MODIFY tanggal DATETIME NULL DEFAULT CURRENT_TIMESTAMP',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_detail_transaksi' AND COLUMN_NAME = 'id_produk') = 0,
    'ALTER TABLE tbl_detail_transaksi ADD COLUMN id_produk INT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_detail_transaksi' AND COLUMN_NAME = 'harga_satuan') = 0,
    'ALTER TABLE tbl_detail_transaksi ADD COLUMN harga_satuan DECIMAL(12,2) UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Historical detail rows remain intact; link them to products when name and price match.
UPDATE tbl_detail_transaksi d
JOIN tbl_produk p ON p.nama_produk = d.nama_produk AND p.harga = d.harga
SET d.id_produk = p.id_produk, d.harga_satuan = d.harga
WHERE d.id_produk IS NULL;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_detail_transaksi' AND INDEX_NAME = 'idx_detail_produk_pos') = 0,
    'ALTER TABLE tbl_detail_transaksi ADD INDEX idx_detail_produk_pos (id_produk)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_detail_transaksi' AND CONSTRAINT_NAME = 'fk_detail_produk_pos') = 0,
    'ALTER TABLE tbl_detail_transaksi ADD CONSTRAINT fk_detail_produk_pos FOREIGN KEY (id_produk) REFERENCES tbl_produk(id_produk) ON UPDATE CASCADE ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
