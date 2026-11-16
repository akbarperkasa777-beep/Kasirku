# AkbarMart POS & Inventory

Aplikasi kasir (Point of Sale) dan manajemen inventory berbasis PHP Native, PDO, MySQL/MariaDB, dan Bootstrap 5. Aplikasi menyediakan dashboard penjualan, pengelolaan produk dan kategori, pemantauan stok, checkout dengan pembaruan stok atomik, laporan transaksi, serta struk yang disiapkan untuk printer thermal 80 mm.

## Fitur utama

- **Dashboard:** penjualan dan jumlah transaksi hari ini, total penjualan, jumlah produk, peringatan otomatis untuk stok di bawah 5 pcs, serta transaksi terbaru.
- **Products:** tambah, lihat, ubah, cari, dan hapus produk; produk yang sudah dipakai pada transaksi tidak dapat dihapus.
- **Inventory:** pemantauan stok langsung dari database, penanda stok menipis, pencarian, serta penambahan stok.
- **Categories:** pengelolaan kategori; kategori yang masih dipakai produk tidak dapat dihapus.
- **POS / Kasir:** katalog yang dapat dicari, keranjang interaktif, pilihan pelanggan, nama kasir, subtotal, diskon rupiah, total, pembayaran, dan kembalian.
- **Checkout aman:** data transaksi, detail transaksi, dan pengurangan stok dijalankan dalam satu database transaction. Stok dikunci dan diperiksa ulang agar transaksi tidak menjual stok yang sudah habis.
- **Multi-role:** akun Admin dapat mengakses seluruh menu; akun Kasir hanya dapat mengakses POS dan struk transaksi miliknya. Login memakai `password_hash`/`password_verify`, sesi aman, CSRF, dan prepared statements.
- **Reports:** filter tanggal, total penjualan, jumlah transaksi, rata-rata transaksi, dan akses untuk mencetak ulang struk.
- **Struk thermal:** halaman `cetak_struk.php` dengan tipografi monospace dan layout 80 mm, barcode Code 128 dari nomor transaksi, tombol cetak, serta cetak otomatis setelah pembayaran berhasil.
- **Identitas AkbarMart:** favicon SVG lokal dipakai di halaman dashboard, POS, dan struk.
- **Antarmuka responsif:** sidebar desktop/mobile, kartu statistik, tabel, dan formulir Bootstrap 5.
- Formulir perubahan data menggunakan prepared statements PDO, validasi input, escaping output HTML, dan token CSRF.

## Prasyarat sistem

- XAMPP (Apache dan MySQL/MariaDB).
- PHP 8.0 atau lebih baru dengan ekstensi `PDO` dan `pdo_mysql` aktif.
- MySQL 5.7+/MariaDB yang mendukung InnoDB dan `utf8mb4`.
- Browser modern dan koneksi internet untuk memuat Bootstrap 5, Bootstrap Icons, serta generator barcode JsBarcode dari CDN.

## Instalasi dan konfigurasi

1. Salin folder proyek `kasirku` ke direktori web XAMPP, umumnya:

   ```text
   C:\xampp\htdocs\kasirku
   ```

2. Jalankan modul **Apache** dan **MySQL** melalui XAMPP Control Panel.

3. Siapkan database:
   - Buka `http://localhost/phpmyadmin`.
   - Jika `db_tokokita` belum ada dan masih kosong, impor `database/database.sql`, `database/migrasi_user.sql`, `database/migrasi_barcode.sql`, lalu `database/migrasi_gambar_produk.sql`. Kolom barcode dan gambar juga sudah ada di skema baru, sehingga kedua migrasi tambahan aman dijalankan.
   - Jika Anda memiliki database lama, **buat backup terlebih dahulu**, lalu impor `database/migrasi_database.sql`, `database/migrasi_user.sql`, `database/migrasi_barcode.sql`, dan `database/migrasi_gambar_produk.sql` secara berurutan. Migrasi bersifat non-destruktif; data lama tidak dihapus. Transaksi historis tanpa pemilik akun tetap dapat dibuka Admin, tetapi tidak dapat dibuka akun Kasir.

   Alternatif melalui command line:

   ```bash
   mysql -u root -p < database/database.sql
   mysql -u root -p < database/migrasi_user.sql
   mysql -u root -p < database/migrasi_barcode.sql
   mysql -u root -p < database/migrasi_gambar_produk.sql
   ```

   Jika MySQL root tidak memiliki password di instalasi XAMPP lokal, hilangkan `-p`.
   Untuk skema lama yang sudah berisi data, jalankan `mysql -u root -p < database/migrasi_database.sql`, lalu `mysql -u root -p < database/migrasi_user.sql`, `mysql -u root -p < database/migrasi_barcode.sql`, dan `mysql -u root -p < database/migrasi_gambar_produk.sql`.

   Akun awal: `admin` / `AkbarAdmin#2026` dan `kasir` / `AkbarKasir#2026`. Ganti password awal sebelum aplikasi digunakan di lingkungan nyata.

   Untuk mengganti password, buat hash baru dengan `php -r "echo password_hash('PasswordBaruYangKuat', PASSWORD_DEFAULT), PHP_EOL;"`, lalu perbarui kolom `password` untuk username terkait melalui phpMyAdmin menggunakan hash tersebut.

4. Sesuaikan koneksi MySQL pada [`config/koneksi.php`](config/koneksi.php):

   ```php
   $host = 'localhost';
   $database = 'db_tokokita';
   $username = 'root';
   $password = '';
   ```

   Ganti username/password sesuai konfigurasi MySQL Anda. Pesan kegagalan koneksi sengaja tidak menampilkan kredensial atau detail internal.

## Menjalankan aplikasi

Buka:

```text
http://localhost/kasirku/
```

Pastikan Apache/MySQL aktif dan skrip database sudah dijalankan. Buka `http://localhost/kasirku/login.php`, lalu masuk menggunakan akun Admin atau Kasir. Jika akun masih memakai password bawaan dan halaman dibuka dari komputer server, tombol login cepat **Admin** dan **Kasir** akan tersedia; tombol ini tidak ditampilkan untuk akses dari perangkat lain.

## Struktur folder dan file

```text
kasirku/
├── assets/
│   ├── css/style.css         # Tema dashboard dan layout responsif
│   ├── img/akbarmart-icon.svg # Favicon AkbarMart
│   └── js/app.js             # Interaksi keranjang dan konfirmasi formulir
├── config/
│   └── koneksi.php           # Koneksi PDO ke MySQL
├── check_session.php         # Validasi sesi pengguna pada halaman terlindungi
├── database/
│   ├── database.sql          # Database, tabel, relasi, indeks, data contoh
│   ├── migrasi_database.sql  # Migrasi tambahan non-destruktif untuk skema lama
│   ├── migrasi_barcode.sql   # Kolom barcode unik untuk produk
│   ├── migrasi_gambar_produk.sql # Kolom gambar produk
│   ├── migrasi_user.sql      # Tabel/akun pengguna dan relasi pemilik transaksi
│   └── .htaccess             # Mencegah unduhan skrip SQL lewat Apache
├── uploads/
│   └── produk/               # Gambar produk yang diunggah (JPG/PNG/WEBP)
├── includes/
│   ├── functions.php         # Escape output, CSRF, flash message, utilitas
│   ├── header.php            # Sidebar, topbar, dan awal layout halaman
│   └── footer.php            # Penutup layout dan pemuatan JavaScript
├── cetak_struk.php           # Detail transaksi dan print thermal receipt
├── index.php                 # Dashboard
├── inventory.php             # Monitoring dan penambahan stok
├── kategori.php              # CRUD kategori
├── laporan.php               # Ringkasan dan riwayat transaksi
├── login.php                 # Form login
├── logout.php                # Logout dengan POST dan validasi CSRF
├── pos.php                   # Katalog, cart, checkout, dan pengurangan stok
├── produk.php                # CRUD produk
└── README.md
```

Bootstrap 5 dan Bootstrap Icons dimuat dari CDN pada `includes/header.php`; aset tampilan dan interaksi khusus aplikasi berada di `assets/css/` dan `assets/js/`.

### Tabel database

- `tbl_kategori`: kategori produk.
- `tbl_produk`: nama, kategori, kode barcode unik opsional, nama file gambar opsional, harga, dan stok produk.
- `tbl_pelanggan`: data pelanggan (checkout juga dapat menggunakan pelanggan umum/tanpa pilihan).
- `tbl_user`: akun Admin/Kasir dengan password ter-hash.
- `tbl_transaksi`: tanggal, pemilik akun, pelanggan, total, diskon, uang bayar, kembalian, dan nama kasir.
- `tbl_detail_transaksi`: produk, jumlah, dan harga satuan saat transaksi.

Kolom `diskon`, `uang_bayar`, `kembalian`, dan `nama_kasir` melengkapi tabel transaksi untuk memenuhi kebutuhan pencatatan pembayaran dan isi struk. Relasi foreign key menjaga konsistensi transaksi serta mencegah penghapusan kategori/produk yang masih digunakan.

Jika skema lama menyimpan nama produk dan harga langsung pada detail transaksi, migrasi menambahkan `id_produk` dan `harga_satuan` tanpa menghapus kolom snapshot lama. Kode POS/struk mendukung kedua bentuk tersebut agar riwayat lama tetap bisa dicetak.

## Panduan penggunaan

### Mengelola produk dan stok

1. Buka **Categories** untuk menambah kategori.
2. Buka **Products**, isi nama produk, barcode (opsional; dapat di-generate), pilih foto JPG/PNG/WEBP (maks. 5 MB), kategori, harga jual, dan stok awal, lalu simpan. Foto akan tampil sebagai thumbnail di daftar produk dan kartu POS; saat mengganti foto, file lama akan dihapus.
   Pastikan Apache memiliki izin tulis ke `uploads/produk/`. Nama file disimpan secara acak; file diperiksa berdasarkan ekstensi dan tipe gambar, bukan hanya nama file.
3. Buka **Inventory** untuk melihat jumlah terkini. Masukkan kuantitas pada kolom **Tambah Stok** untuk mencatat barang masuk. Pengurangan stok terjadi otomatis saat checkout.
4. Stok kurang dari 5 pcs ditandai sebagai menipis dan ditampilkan pada dashboard.

### Menjalankan transaksi POS

1. Buka **POS / Kasir** dan scan barcode menggunakan scanner HID; setelah scanner mengirim Enter, produk otomatis masuk keranjang. Kolom scan otomatis fokus saat halaman dibuka. Produk juga dapat dipilih dari katalog; klik kembali produk untuk menambah kuantitas.
2. Atur jumlah pada keranjang dan pilih pelanggan (opsional). Nama kasir otomatis mengikuti akun yang login.
3. Isi diskon dalam rupiah jika diperlukan, lalu masukkan uang yang diterima. Nilai subtotal, total, dan kembalian diperbarui otomatis.
4. Klik **Proses Pembayaran**. Server memvalidasi ulang harga, jumlah, pembayaran, diskon, dan stok terkini. Jika valid, transaksi serta detailnya disimpan dan stok dikurangi dalam satu transaksi database.
5. Struk terbuka otomatis untuk dicetak. Jika pembayaran tidak valid atau stok berubah/tidak cukup, checkout dibatalkan tanpa menyimpan sebagian transaksi.

### Cetak struk thermal

- Printer 80 mm: pilih ukuran kertas 80 mm di dialog print browser, margin **None/Minimum**, dan skala 100% (sesuaikan kemampuan printer).
- Layout saat ini memakai lebar halaman 80 mm dengan konten cetak 72 mm. Untuk printer 58 mm, ubah `@page` pada `cetak_struk.php` menjadi `size:58mm auto` dan sesuaikan lebar konten menjadi sekitar 48–50 mm.
- Ganti nama toko dan alamat pada variabel `$storeName` dan `$storeAddress` di `cetak_struk.php`.
- Untuk mencetak ulang, buka **Reports** atau transaksi terbaru di Dashboard, lalu klik **Struk**.

## Catatan operasional

- Gunakan harga dalam rupiah bilangan bulat pada formulir. Database memakai kolom `DECIMAL` untuk nilai uang.
- Penghapusan produk yang telah tercatat dalam detail transaksi ditolak agar histori transaksi tetap utuh.
- Untuk deployment, gunakan kredensial database non-root, HTTPS, autentikasi/otorisasi kasir dan administrator, serta konfigurasi keamanan server yang sesuai.
- Seed user menggunakan password awal untuk instalasi lokal; segera ganti keduanya sebelum penggunaan operasional.
- Berkas SQL di folder `database/` dilindungi dari akses HTTP oleh `.htaccess` saat menggunakan Apache.
# kasirToko
# Kasirku
