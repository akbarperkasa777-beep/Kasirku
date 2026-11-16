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
    kota VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tbl_transaksi (
    id_transaksi BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tanggal DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_pelanggan INT UNSIGNED NULL,
    total_belanja DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
    diskon DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
    uang_bayar DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
    kembalian DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0,
    nama_kasir VARCHAR(100) NOT NULL DEFAULT 'Kasir',
    INDEX idx_transaksi_tanggal (tanggal),
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

INSERT INTO tbl_pelanggan (nama_pelanggan, kota)
SELECT 'Pelanggan Umum', '—'
WHERE NOT EXISTS (SELECT 1 FROM tbl_pelanggan WHERE nama_pelanggan = 'Pelanggan Umum');

INSERT INTO tbl_produk (nama_produk, id_kategori, harga, stok)
SELECT 'Kopi Susu 250ml', id_kategori, 12000, 18 FROM tbl_kategori WHERE nama_kategori = 'Minuman'
AND NOT EXISTS (SELECT 1 FROM tbl_produk WHERE nama_produk = 'Kopi Susu 250ml');
INSERT INTO tbl_produk (nama_produk, id_kategori, harga, stok)
SELECT 'Roti Cokelat', id_kategori, 8500, 4 FROM tbl_kategori WHERE nama_kategori = 'Makanan'
AND NOT EXISTS (SELECT 1 FROM tbl_produk WHERE nama_produk = 'Roti Cokelat');
INSERT INTO tbl_produk (nama_produk, id_kategori, harga, stok)
SELECT 'Air Mineral 600ml', id_kategori, 5000, 32 FROM tbl_kategori WHERE nama_kategori = 'Minuman'
AND NOT EXISTS (SELECT 1 FROM tbl_produk WHERE nama_produk = 'Air Mineral 600ml');
