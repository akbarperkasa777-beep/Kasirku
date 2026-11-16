USE db_tokokita;

CREATE TABLE IF NOT EXISTS tbl_user (
    id_user INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nama_lengkap VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'kasir') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_user),
    UNIQUE KEY uq_tbl_user_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tbl_user (nama_lengkap, username, password, role)
SELECT 'Administrator AkbarMart', 'admin', '$2y$10$0mr3spPJxq8t.JMGcCQYleQEE9gOyBPecCdgFBKVTlpf0cgT95ifO', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM tbl_user WHERE username = 'admin');

INSERT INTO tbl_user (nama_lengkap, username, password, role)
SELECT 'Kasir AkbarMart', 'kasir', '$2y$10$AgiYfnGPls3tyBGWikMy/.c7Vq4mBuAGo0XHv2pPkyk24rvrKyGE2', 'kasir'
WHERE NOT EXISTS (SELECT 1 FROM tbl_user WHERE username = 'kasir');

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND COLUMN_NAME = 'id_user') = 0,
    'ALTER TABLE tbl_transaksi ADD COLUMN id_user INT UNSIGNED NULL AFTER id_transaksi',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND INDEX_NAME = 'idx_transaksi_user') = 0,
    'ALTER TABLE tbl_transaksi ADD INDEX idx_transaksi_user (id_user)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_transaksi' AND CONSTRAINT_NAME = 'fk_transaksi_user') = 0,
    'ALTER TABLE tbl_transaksi ADD CONSTRAINT fk_transaksi_user FOREIGN KEY (id_user) REFERENCES tbl_user(id_user) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
