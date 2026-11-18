# AkbarMart POS & Inventory Suite

Aplikasi Kasir (Point of Sale) dan Manajemen Inventaris toko retail modern berbasis **PHP Native**, **MySQL/MariaDB (PDO)**, dan **Bootstrap 5**. Aplikasi dirancang khusus untuk operasional toko retail **AkbarMart** yang berlokasi di **Jl. Gajah Mada No. 12, Ponorogo**.

Sistem ini mendukung otomatisasi kasir modern, pemindaian barcode barang, pencetakan struk termal, manajemen stok atomik, serta fitur unggulan **Auto Member Discount 10% via Tap ID Card / RFID / NFC Scanner**.
Admin juga dapat mengelola data pelanggan/member, nomor telepon, kartu RFID, serta status aktif/nonaktif melalui halaman Data Pelanggan.
Versi ini juga menyediakan tiga role (**admin, kasir, pelanggan**) dan portal belanja mandiri dengan pembayaran QRIS/transfer yang diverifikasi admin atau kasir sebelum stok dikurangi.

---

## Fitur Utama

1. **Auto Member Discount 10% via ID Card / RFID Scan:**
   - Deteksi otomatis pembaca kartu RFID/NFC (USB HID Keyboard Emulation) yang diakhiri tombol `Enter`.
   - Tombol pintas kasir cepat (`F2`) untuk langsung memfokuskan reader kartu member.
   - Pengecekan data kartu secara asinkron (*real-time* AJAX POST) ke endpoint `ajax_cek_member.php`.
   - Validasi status keanggotaan aktif (`status_member = 'aktif'`). Jika kartu nonaktif atau belum terdaftar, sistem menampilkan peringatan yang informatif.
   - Perhitungan diskon 10% dari subtotal belanja dihitung dan diperbarui secara otomatis setiap kali produk ditambah, dikurangi, atau kuantitasnya diubah di keranjang belanja.
   - Nama pelanggan dan nomor kartu otomatis terpilih dan tercatat pada database transaksi serta struk pembelian.

2. **Scan Barcode Produk:**
   - Pemindaian barcode produk menggunakan scanner laser / CCD (USB HID) dengan tombol pintas cepat (`F1`).
   - Produk otomatis masuk ke keranjang belanja saat barcode terdeteksi tanpa perlu klik mouse.
   - Generator barcode acak otomatis untuk produk baru di halaman manajemen produk.

3. **Manajemen Gambar Produk:**
   - Unggah foto produk (JPG, PNG, WEBP hingga 5 MB) dengan validasi MIME-type dan penyimpanan nama acak anti-konflik.
   - Foto otomatis tampil sebagai thumbnail estetik pada katalog kasir dan tabel daftar produk.
   - Penghapusan gambar otomatis dari server saat produk diubah fotonya atau dihapus.

4. **Cetak Struk Thermal (Thermal Receipt):**
   - Halaman `cetak_struk.php` dengan tipografi *monospace* murni berstandar printer kasir 80 mm / 72 mm.
   - Barcode Code 128 otomatis dari nomor unik transaksi (`TRX-XXXXXX`).
   - Pratinjau layar (*screen preview*) yang elegan dengan tombol cetak ulang dan kembali ke kasir.
   - Cetak otomatis (*auto-print*) setelah transaksi pembayaran kasir berhasil diproses.

5. **Dashboard SaaS & Fintech:**
   - Rekap omzet harian, akumulasi total omzet, jumlah transaksi sukses, dan total SKU aktif.
   - Peringatan stok kritis (*Low Stock Alert*) untuk produk dengan stok di bawah 5 pcs.
   - Tabel 8 transaksi terkini dengan status lunas dan akses cetak struk cepat.

6. **Transaksi Kasir Aman (ACID Transactions):**
   - Proses checkout, detail belanja, dan pengurangan stok dieksekusi dalam satu *database transaction* (`beginTransaction`, `commit`, `rollBack`).
   - Baris produk dikunci dengan `FOR UPDATE` untuk mencegah *race condition* dan penjualan barang yang stoknya sudah habis.

7. **Sistem Tiga Role:**
   - **Admin** mengelola produk, stok, member, laporan transaksi, dan verifikasi pembayaran.
   - **Kasir** mengakses POS, boleh membuat member/akun belanja baru, dan memverifikasi pembayaran; kasir tidak dapat mengubah atau menghapus member yang sudah ada.
   - **Pelanggan** masuk dengan akun yang dibuat petugas dan hanya dapat mengakses portal belanja mandiri serta riwayat transaksinya sendiri.
   - Password di-hash, perubahan data penting dilindungi token CSRF, dan query memakai PDO prepared statements.
8. **Belanja Mandiri Cashless:**
   - Pelanggan hanya dapat memilih QRIS atau transfer; endpoint menolak metode tunai.
   - Mode **dummy** tersedia untuk XAMPP: bukti tiruan menunggu admin, atau tombol auto-success langsung menandai transaksi simulasi berhasil dan memotong stok atomik.
   - Semua transaksi dummy ditandai jelas, dikecualikan dari omzet/laporan penjualan, dan struknya diberi peringatan bukan pembayaran nyata.
   - Dalam mode manual, admin/kasir memeriksa bukti bayar melalui pratinjau gambar. Unggahan dibatasi 5 MB dan disajikan melalui endpoint staf terlindungi.

---

## Struktur Database

Database sistem bernama `db_tokokita` terdiri dari tabel-tabel utama berikut:

| Nama Tabel | Deskripsi | Kolom Kunci / Tambahan |
| :--- | :--- | :--- |
| `tbl_pelanggan` | Data pelanggan dan member toko | `id_pelanggan` (PK), `nama_pelanggan`, `no_telepon`, `kota`, **`id_card_rfid`** (VARCHAR(50), Unique, Nullable), **`status_member`** (ENUM('aktif', 'nonaktif')) |
| `tbl_produk` | Katalog produk barang | `id_produk` (PK), `nama_produk`, `id_kategori` (FK), `kode_barcode` (Unique), `gambar`, `harga`, `stok` |
| `tbl_kategori` | Kategori pengelompokan barang | `id_kategori` (PK), `nama_kategori` (Unique) |
| `tbl_user` | Akun pengguna sistem POS dan portal | `id_user` (PK), `nama_lengkap`, `username` (Unique), `password`, `role` (ENUM('admin', 'kasir', 'pelanggan')), `id_pelanggan` (FK opsional untuk akun pelanggan) |
| `tbl_transaksi` | Data induk transaksi penjualan | `id_transaksi` (PK), `tanggal`, `id_user` (FK), `id_pelanggan` (FK), `total_belanja`, `diskon`, `metode_pembayaran`, `status_pembayaran`, `simulasi_pembayaran`, bukti dan catatan verifikasi |
| `tbl_detail_transaksi` | Rincian item per transaksi | `id_detail` (PK), `id_transaksi` (FK), `id_produk` (FK), `jumlah`, `harga_satuan` |

---

## Prasyarat Sistem

- **Web Server & Database:** XAMPP (Apache dan MySQL/MariaDB).
- **PHP:** Versi 8.0 atau yang lebih baru dengan ekstensi `pdo`, `pdo_mysql`, dan `fileinfo` aktif.
- **Hardware Tambahan (Opsional):**
  - USB RFID / NFC Card Reader (13.56 MHz / 125 KHz format Keyboard Emulation).
  - USB Barcode Scanner (1D/2D Barcode HID Reader).
  - Printer Thermal 80 mm / 58 mm (ESC/POS atau Windows Print Driver).

---

## Panduan Instalasi di XAMPP

1. **Salin Folder Proyek:**
   Tempatkan folder `kasirku` di dalam direktori `htdocs` instalasi XAMPP Anda:
   ```text
   C:\xampp\htdocs\kasirku
   ```

2. **Aktifkan Modul XAMPP:**
   Buka **XAMPP Control Panel**, lalu klik **Start** pada modul **Apache** dan **MySQL**.

3. **Impor Database:**
   - Buka browser dan akses `http://localhost/phpmyadmin`.
   - Buat database baru bernama `db_tokokita`.
   - **Instalasi Baru (Fresh Install):**
     Impor berurutan: [`database/database.sql`](database/database.sql), [`database/migrasi_user.sql`](database/migrasi_user.sql), [`database/migrasi_barcode.sql`](database/migrasi_barcode.sql), [`database/migrasi_gambar_produk.sql`](database/migrasi_gambar_produk.sql), [`database/migrasi_member_rfid.sql`](database/migrasi_member_rfid.sql), [`database/migrasi_telepon_pelanggan.sql`](database/migrasi_telepon_pelanggan.sql), dan [`database/migrasi_multi_role_cashless.sql`](database/migrasi_multi_role_cashless.sql).
   - **Database Lama yang Sudah Ada:**
     Cukup jalankan migrasi non-destruktif berikut secara berurutan:
     1. `database/migrasi_database.sql`
     2. `database/migrasi_user.sql`
     3. `database/migrasi_barcode.sql`
     4. `database/migrasi_gambar_produk.sql`
     5. `database/migrasi_member_rfid.sql`
     6. `database/migrasi_telepon_pelanggan.sql`
     7. `database/migrasi_multi_role_cashless.sql`

4. **Konfigurasi Koneksi Database:**
   Buka file [`config/koneksi.php`](config/koneksi.php) dan pastikan kredensial sesuai dengan MySQL lokal Anda:
   ```php
   $host = 'localhost';
   $database = 'db_tokokita';
   $username = 'root';
   $password = ''; // Kosongkan jika default XAMPP
   ```

5. **Akses Aplikasi:**
   Buka browser dan arahkan ke alamat:
   ```text
   http://localhost/kasirku/
   ```
6. **Mode pembayaran:**
   - Mode default [`config/pembayaran.php`](config/pembayaran.php) adalah `dummy`, siap untuk pengujian lokal. Informasi QRIS/rekening yang tampil hanyalah mockup; jangan transfer uang sungguhan.
   - Untuk pembayaran manual toko, ubah `mode` menjadi `manual`, lalu isi rekening toko yang benar dan/atau path gambar QRIS resmi di `assets/img/`. Checkout non-tunai tetap menunggu verifikasi admin/kasir.
   - Siapkan folder `storage/pembayaran/` agar Apache dapat menulis bukti; aturan `.htaccess` membatasi akses langsung.

---

## Akun Login & Data Pengujian (Testing)

### Akun Sistem Bawaan:
- **Administrator:** Username: `admin` | Password awal: `AkbarAdmin#2026`
- **Kasir:** Username: `kasir` | Password awal: `AkbarKasir#2026`
- Ganti password awal sebelum aplikasi digunakan. Akun pelanggan dibuat petugas saat mendaftarkan member; serahkan kredensial awal secara aman dan minta pelanggan mengganti password saat pertama kali masuk.

### Contoh Kartu Member RFID untuk Pengujian:
| ID Card / RFID | Nama Member | Status Member | Hasil Scan di POS |
| :--- | :--- | :--- | :--- |
| `RFID-001` | Budi Santoso (Member) | **aktif** | Diskon **10%** otomatis terpasang |
| `RFID-002` | Siti Rahmawati (Member) | **aktif** | Diskon **10%** otomatis terpasang |
| `RFID-003` | Ahmad Fauzi (Member Nonaktif) | **nonaktif** | Ditolak: Kartu berstatus **NONAKTIF** |
| `RFID-999` | *(Tidak terdaftar)* | - | Ditolak: Kartu **tidak terdaftar** |

---

## Panduan Penggunaan Role, Kasir & Member

1. **Transaksi Produk:**
   - Kasir membuka menu **POS / Kasir**.
   - Scan barcode barang dengan scanner atau klik kartu produk pada katalog untuk memasukkannya ke keranjang belanja.
2. **Scan / Tap Kartu Member:**
   - POS otomatis memfokuskan input pemindai RFID yang tersembunyi; tekan **`F2`** atau klik **Fokus Pemindai** untuk memfokuskannya kembali.
   - Tempelkan kartu member pada alat pembaca RFID (scanner akan mengetikkan nomor kartu dan mengirim sinyal `Enter`).
   - Sistem secara instan mengirim data ke `ajax_cek_member.php` via AJAX.
   - Hanya kartu terdaftar dengan status **aktif** yang mendapat diskon 10%; kartu kosong, tidak terdaftar, atau nonaktif menampilkan peringatan dan tidak mendapat diskon.
   - Status kartu diverifikasi ulang saat checkout; nilai diskon dari browser tidak dipercaya.
3. **Pembayaran & Struk:**
   - Masukkan nominal uang yang diterima dari pelanggan (atau klik tombol cepat *Uang Pas*, *50.000*, *100.000*).
   - Klik **Proses Pembayaran & Cetak**.
   - Struk thermal akan otomatis terbuka dan mencantumkan nama member serta rincian diskon 10%.
4. **Pendaftaran member oleh Admin/Kasir:**
   - Buka menu **Data Pelanggan (Member)** sebagai admin atau **Tambah Member** sebagai kasir.
   - Isi nama, telepon, tap RFID, username dan password awal. Sistem membuat member aktif beserta akun portal pelanggan.
   - Kasir hanya dapat membuat data baru; edit, aktivasi/nonaktifasi, dan hapus hanya tersedia untuk admin.
5. **Belanja mandiri pelanggan:**
   - Pelanggan masuk menggunakan akun yang diberikan petugas. Akun hanya bisa digunakan selama member aktif.
   - Diskon 10% khusus transaksi POS setelah kartu RFID aktif ditap; portal belanja mandiri tidak menerapkan diskon member.
   - Pilih produk dan QRIS/transfer, lalu klik **Checkout / Bayar Non-Tunai** untuk membuka modal.
   - Mode dummy: **Simulasi Unggah Bukti Bayar** membuat pesanan menunggu admin/kasir; **Simulasi Bayar Instan** langsung menandai berhasil dan memotong stok. Keduanya tidak memindahkan uang.
   - Mode manual: unggah bukti pembayaran asli. Admin/kasir memeriksa lalu menyetujui (stok dipotong) atau menolak (status dibatalkan, stok tetap).

---

## Struktur Berkas Proyek

```text
kasirku/
├── assets/
│   ├── css/style.css              # Design system SaaS & antarmuka kasir
│   ├── img/akbarmart-icon.svg      # Logo identitas toko AkbarMart
│   └── js/app.js                  # Logika keranjang, scan barcode, & RFID AJAX
├── config/
│   ├── koneksi.php                # Koneksi PDO ke database MySQL
│   └── pembayaran.php             # Pengaturan rekening bank dan gambar QRIS resmi toko
├── check_session.php              # Guard otentikasi & proteksi sesi halaman
├── database/
│   ├── database.sql               # Skema awal & data inisial lengkap
│   ├── migrasi_database.sql       # Migrasi non-destruktif untuk skema lama
│   ├── migrasi_user.sql           # Migrasi akun & tabel user
│   ├── migrasi_barcode.sql        # Migrasi kolom barcode produk
│   ├── migrasi_gambar_produk.sql  # Migrasi kolom gambar produk
│   ├── migrasi_member_rfid.sql    # Migrasi kolom RFID & status member
│   ├── migrasi_telepon_pelanggan.sql # Migrasi nomor telepon pelanggan
│   ├── migrasi_multi_role_cashless.sql # Role pelanggan, cashless, dan penanda transaksi simulasi
│   └── .htaccess                  # Proteksi berkas SQL dari akses web
├── uploads/
│   └── produk/                    # Direktori penyimpanan gambar produk
├── includes/
│   ├── functions.php              # Helper fungsi, sanitasi, dan CSRF
│   ├── header.php                 # Header modern, topbar, & sidebar
│   └── footer.php                 # Footer & skrip JavaScript
├── ajax_cek_member.php            # Endpoint JSON verifikasi kartu member RFID
├── admin_pelanggan.php            # CRUD pelanggan, member, nomor telepon, dan status RFID
├── admin_pembayaran.php           # Verifikasi bukti cashless oleh admin
├── belanja_pelanggan.php          # Katalog, checkout cashless, riwayat dan akun pelanggan
├── cetak_struk.php                # Cetak struk belanja thermal 80 mm
├── index.php                      # Dashboard penjualan
├── inventory.php                  # Monitoring & restock inventaris
├── kategori.php                   # CRUD kategori barang
├── laporan.php                    # Laporan & riwayat transaksi penjualan
├── login.php                      # Halaman masuk sistem
├── logout.php                     # Keluar sistem aman dengan CSRF
├── pos.php                        # Antarmuka kasir (Point of Sale)
├── lihat_bukti_pembayaran.php     # Penyajian bukti bayar terlindungi untuk admin/kasir
├── storage/pembayaran/            # Bukti transaksi; akses langsung diblokir
├── produk.php                     # CRUD produk & upload gambar
└── README.md                      # Dokumentasi komprehensif AkbarMart
```

---

© 2026 **AkbarMart Retail POS** — Jl. Gajah Mada No. 12, Ponorogo.
