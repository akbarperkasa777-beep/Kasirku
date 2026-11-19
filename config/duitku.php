<?php
declare(strict_types=1);

/**
 * Konfigurasi Integrasi Payment Gateway Duitku untuk AkbarMart POS & Ritel.
 * Alamat Gerai: Jl. Gajah Mada No. 12, Ponorogo.
 *
 * INFORMASI KREDENSIAL PROYEK DUITKU:
 * - Kode Merchant       : DS38180 (Duitku Sandbox Environment)
 * - API Key Rahasia     : 93cec3f6f9faa8156d22c83192240f45
 * - Website Proyek      : https://sterida.my.id/akbar
 * - URL Callback Webhook: https://sterida.my.id/akbar/callback.php
 * - URL Return          : https://sterida.my.id/akbar/belanja_pelanggan.php
 * - URL Simulasi Demo   : https://sandbox.duitku.com/payment/demo/demosuccesstransaction.aspx
 */

// Deteksi host saat ini (live hosting sterida.my.id atau localhost XAMPP)
$isProductionHost = isset($_SERVER['HTTP_HOST']) && str_contains($_SERVER['HTTP_HOST'], 'sterida.my.id');

// Tentukan Base URL proyek
if ($isProductionHost) {
    $baseUrl = 'https://sterida.my.id/akbar';
} else {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ? 'https'
        : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $protocol . '://' . $host . '/kasirku';
}

return [
    // Kode Merchant resmi Duitku dari akun proyek Anda
    'merchant_code' => 'DS38180',

    // API Key Duitku Rahasia
    'api_key' => '93cec3f6f9faa8156d22c83192240f45',

    // Mode Operasi: 'sandbox' (menggunakan endpoint Sandbox Duitku)
    'mode' => 'sandbox',

    // Daftar Endpoint Duitku v2 (Pop-up / Redirect Inquiry)
    'endpoints' => [
        'sandbox' => 'https://sandbox.duitku.com/webapi/api/merchant/v2/inquiry',
        'production' => 'https://passport.duitku.com/webapi/api/merchant/v2/inquiry',
    ],

    // Endpoint Pengecekan Status Transaksi Duitku (Check Transaction Status)
    'status_endpoints' => [
        'sandbox' => 'https://sandbox.duitku.com/webapi/api/merchant/transactionStatus',
        'production' => 'https://passport.duitku.com/webapi/api/merchant/transactionStatus',
    ],

    // URL Return: Halaman kembali setelah pelanggan menyelesaikan pembayaran di Duitku
    'return_url' => $isProductionHost
        ? 'https://sterida.my.id/akbar/belanja_pelanggan.php'
        : $baseUrl . '/belanja_pelanggan.php',

    // URL Callback / Webhook resmi proyek Duitku
    // Server Duitku akan mengirim notifikasi status pembayaran (HTTP POST) ke URL ini
    'callback_url' => 'https://sterida.my.id/akbar/callback.php',

    // Halaman demo simulasi pembayaran Duitku Sandbox
    'demo_simulator_url' => 'https://sandbox.duitku.com/payment/demo/demosuccesstransaction.aspx',

    // Masa aktif tagihan pembayaran dalam menit (default 60 menit)
    'expiry_period' => 60,

    // Izinkan fallback simulator lokal HANYA jika cURL offline total (default: false agar selalu memanggil API Duitku nyata)
    'allow_local_simulation' => false,

    // Daftar Kanal Pembayaran Duitku yang didukung sistem AkbarMart
    'channels' => [
        // Kanal Virtual Account (Simulasi di demosuccesstransaction.aspx menggunakan nomor VA resmi dari Duitku)
        'va' => [
            'BR' => ['name' => 'BRI Virtual Account', 'demo_label' => 'Bank Rakyat Indonesia'],
            'BC' => ['name' => 'BCA Virtual Account', 'demo_label' => 'BCA'],
            'M2' => ['name' => 'Mandiri Virtual Account', 'demo_label' => 'Mandiri'],
            'I1' => ['name' => 'BNI Virtual Account', 'demo_label' => 'BNI'],
            'VA' => ['name' => 'Maybank Virtual Account', 'demo_label' => 'Maybank'],
            'BT' => ['name' => 'Permata Bank Virtual Account', 'demo_label' => 'Permata Bank'],
            'B1' => ['name' => 'CIMB Niaga Virtual Account', 'demo_label' => 'CIMB Niaga'],
            'BV' => ['name' => 'BSI Virtual Account', 'demo_label' => 'Bank Syariah Indonesia'],
            'DM' => ['name' => 'Danamon Virtual Account', 'demo_label' => 'Danamon'],
            'AG' => ['name' => 'Bank Artha Graha VA', 'demo_label' => 'Artha Graha'],
            'NC' => ['name' => 'Bank Neo Commerce / BNC VA', 'demo_label' => 'Bank Neo Commerce'],
            'S1' => ['name' => 'Bank Sahabat Sampoerna VA', 'demo_label' => 'Sahabat Sampoerna'],
            'A1' => ['name' => 'ATM Bersama / Alto VA', 'demo_label' => 'ATM Bersama'],
        ],
        // Kanal QRIS (Simulasi di demosuccesstransaction.aspx menggunakan Duitku Reference)
        'qris' => [
            'SP' => ['name' => 'QRIS Realtime (ShopeePay / All E-Wallet)', 'demo_label' => 'ShopeePay QRIS'],
            'NQ' => ['name' => 'QRIS Nobu (All E-Wallet)', 'demo_label' => 'Nobu QRIS'],
        ],
        // Kanal E-Wallet
        'ewallet' => [
            'OV' => ['name' => 'OVO', 'demo_label' => 'OVO'],
            'DA' => ['name' => 'DANA', 'demo_label' => 'DANA'],
            'SA' => ['name' => 'ShopeePay App Direct', 'demo_label' => 'ShopeePay'],
            'LA' => ['name' => 'LinkAja', 'demo_label' => 'LinkAja'],
            'AP' => ['name' => 'AstraPay', 'demo_label' => 'AstraPay'],
        ],
        // Kanal Gerai Retail
        'retail' => [
            'FT' => ['name' => 'Indomaret', 'demo_label' => 'Indomaret'],
            'IR' => ['name' => 'Pos Indonesia / Pegadaian', 'demo_label' => 'Pos Indonesia'],
        ],
    ],

    // Informasi Toko AkbarMart Ponorogo
    'store_name' => 'AkbarMart',
    'store_address' => 'Jl. Gajah Mada No. 12, Ponorogo',
    'website_url' => 'https://sterida.my.id/akbar',
];
