<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

// Pastikan sesi aktif untuk dibersihkan
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// 1. Kosongkan seluruh variabel sesi
$_SESSION = [];

// 2. Hapus cookie sesi dari browser klien jika ada
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $cookie['path'],
        $cookie['domain'],
        $cookie['secure'],
        $cookie['httponly']
    );
}

// 3. Hancurkan sesi di sisi server
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

// 4. Arahkan pengguna kembali ke halaman login/awal secara aman
header('Location: login.php?logout=1');
exit;
