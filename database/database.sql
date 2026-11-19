-- Initial schema and sample data for db_tokokita.
CREATE DATABASE IF NOT EXISTS db_tokokita CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_tokokita;

CREATE TABLE IF NOT EXISTS tbl_kategori (
    id_kategori INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama_kategori VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tbl_produk (
    id_produk INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama_produk VARCHAR(150) NOT NULL,
    id_kategori INT UNSIGNED NOT NULL,
    kode_barcode VARCHAR(50) NULL,
    gambar VARCHAR(255) NULL,
    harga DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
    stok INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_produk_nama (nama_produk),
    UNIQUE KEY uq_produk_kode_barcode (kode_barcode),
    CONSTRAINT fk_produk_kategori FOREIGN KEY (id_kategori) REFERENCES tbl_kategori(id_kategori)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tbl_pelanggan (
    id_pelanggan INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama_pelanggan VARCHAR(150) NOT NULL,
    no_telepon VARCHAR(20) NULL,
    kota VARCHAR(100) NULL,
    id_card_rfid VARCHAR(50) NULL,
    status_member ENUM('aktif', 'nonaktif') NOT NULL DEFAULT 'aktif',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pelanggan_id_card_rfid (id_card_rfid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tbl_transaksi (
    id_transaksi BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tanggal DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_pelanggan INT UNSIGNED NULL,
    total_belanja DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
    diskon DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
    uang_bayar DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
    kembalian DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
    metode_pembayaran ENUM('tunai', 'qris', 'transfer', 'duitku') NOT NULL DEFAULT 'tunai',
    status_pembayaran ENUM('lunas', 'menunggu_pembayaran', 'menunggu_verifikasi', 'ditolak', 'dibatalkan') NOT NULL DEFAULT 'lunas',
    simulasi_pembayaran TINYINT(1) NOT NULL DEFAULT 0,
    bukti_pembayaran VARCHAR(255) NULL,
    tanggal_pembayaran DATETIME NULL,
    ditinjau_oleh INT UNSIGNED NULL,
    tanggal_ditinjau DATETIME NULL,
    catatan_pembayaran VARCHAR(255) NULL,
    merchant_order_id VARCHAR(100) NULL,
    duitku_reference VARCHAR(100) NULL,
    duitku_va_number VARCHAR(50) NULL,
    duitku_payment_url VARCHAR(500) NULL,
    nama_kasir VARCHAR(100) NOT NULL DEFAULT 'Kasir',
    INDEX idx_transaksi_tanggal (tanggal),
    INDEX idx_transaksi_status_tanggal (status_pembayaran, tanggal),
    INDEX idx_transaksi_merchant_order (merchant_order_id),
    INDEX idx_transaksi_duitku_ref (duitku_reference),
    CONSTRAINT fk_transaksi_pelanggan FOREIGN KEY (id_pelanggan) REFERENCES tbl_pelanggan(id_pelanggan)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tbl_detail_transaksi (
    id_detail BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_transaksi BIGINT UNSIGNED NOT NULL,
    id_produk INT UNSIGNED NOT NULL,
    jumlah INT UNSIGNED NOT NULL,
    harga_satuan DECIMAL(12,2) UNSIGNED NOT NULL,
    INDEX idx_detail_transaksi (id_transaksi),
    CONSTRAINT fk_detail_transaksi FOREIGN KEY (id_transaksi) REFERENCES tbl_transaksi(id_transaksi)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_detail_produk FOREIGN KEY (id_produk) REFERENCES tbl_produk(id_produk)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT IGNORE INTO tbl_kategori (nama_kategori) VALUES
('Makanan'), ('Minuman'), ('Perlengkapan');

INSERT INTO tbl_pelanggan (nama_pelanggan, kota, id_card_rfid, status_member)
SELECT 'Pelanggan Umum', '—', NULL, 'nonaktif'
WHERE NOT EXISTS (SELECT 1 FROM tbl_pelanggan WHERE nama_pelanggan = 'Pelanggan Umum');

INSERT INTO tbl_pelanggan (nama_pelanggan, kota, id_card_rfid, status_member)
SELECT 'Budi Santoso (Member)', 'Ponorogo', 'RFID-001', 'aktif'
WHERE NOT EXISTS (SELECT 1 FROM tbl_pelanggan WHERE id_card_rfid = 'RFID-001');

INSERT INTO tbl_pelanggan (nama_pelanggan, kota, id_card_rfid, status_member)
SELECT 'Siti Rahmawati (Member)', 'Madiun', 'RFID-002', 'aktif'
WHERE NOT EXISTS (SELECT 1 FROM tbl_pelanggan WHERE id_card_rfid = 'RFID-002');

INSERT INTO tbl_pelanggan (nama_pelanggan, kota, id_card_rfid, status_member)
SELECT 'Ahmad Fauzi (Member Nonaktif)', 'Magetan', 'RFID-003', 'nonaktif'
WHERE NOT EXISTS (SELECT 1 FROM tbl_pelanggan WHERE id_card_rfid = 'RFID-003');

INSERT INTO tbl_produk (nama_produk, id_kategori, harga, stok)
SELECT 'Kopi Susu 250ml', id_kategori, 12000, 18 FROM tbl_kategori WHERE nama_kategori = 'Minuman'
AND NOT EXISTS (SELECT 1 FROM tbl_produk WHERE nama_produk = 'Kopi Susu 250ml');
INSERT INTO tbl_produk (nama_produk, id_kategori, harga, stok)
SELECT 'Roti Cokelat', id_kategori, 8500, 4 FROM tbl_kategori WHERE nama_kategori = 'Makanan'
AND NOT EXISTS (SELECT 1 FROM tbl_produk WHERE nama_produk = 'Roti Cokelat');
INSERT INTO tbl_produk (nama_produk, id_kategori, harga, stok)
SELECT 'Air Mineral 600ml', id_kategori, 5000, 32 FROM tbl_kategori WHERE nama_kategori = 'Minuman'
AND NOT EXISTS (SELECT 1 FROM tbl_produk WHERE nama_produk = 'Air Mineral 600ml');
