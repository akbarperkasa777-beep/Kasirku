<?php
declare(strict_types=1);

/**
 * Konfigurasi Metode Pembayaran Non-Tunai AkbarMart (Jl. Gajah Mada No. 12, Ponorogo)
 *
 * Mode Pembayaran yang Tersedia:
 * 1. 'duitku'  : Payment Gateway otomatis (QRIS, VA, E-Wallet). Konfigurasi API di config/duitku.php.
 * 2. 'manual'  : Pembayaran transfer bank / QRIS toko fisik dengan unggah bukti pembayaran.
 * 3. 'dummy'   : Pengujian lokal offline di XAMPP dengan bukti gambar tiruan.
 */
return [
    'mode' => 'duitku', // Set ke 'duitku' untuk otomatisasi penuh payment gateway
    'bank_name' => 'BCA',
    'bank_account_number' => '1234567890',
    'bank_account_name' => 'AkbarMart Ponorogo',
    'qris_image' => '', // Jalur file gambar QRIS toko di assets/img/ (opsional jika mode manual)
];
