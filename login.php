<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

// Jika pengguna sudah terautentikasi, alihkan ke dashboard admin atau kasir
if (is_authenticated()) {
    redirect($_SESSION['role'] === 'admin' ? 'dashboard.php' : 'pos.php');
}

require_once __DIR__ . '/config/koneksi.php';

$loginError = '';
$demoAccounts = [];
$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);

// Pengecekan status koneksi database untuk modal diagnostik sistem
$dbStatus = [
    'connected' => false,
    'host' => $host ?? 'localhost',
    'database' => $database ?? 'db_tokokita',
    'version' => 'Unknown',
    'tables_count' => 0,
    'error' => '',
];

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->query('SELECT 1');
        $dbStatus['connected'] = true;
        $dbStatus['version'] = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $tablesRes = $pdo->query('SHOW TABLES');
        $dbStatus['tables_count'] = $tablesRes ? $tablesRes->rowCount() : 6;
    }
} catch (Throwable $dbEx) {
    $dbStatus['connected'] = false;
    $dbStatus['error'] = $dbEx->getMessage();
}

// Akun demo untuk pengujian lokal di lingkungan development / XAMPP
if ($isLocalRequest && $dbStatus['connected']) {
    $defaultDemoAccounts = [
        ['username' => 'admin', 'password' => 'AkbarAdmin#2026', 'label' => 'Administrator', 'badge' => 'Admin'],
        ['username' => 'kasir', 'password' => 'AkbarKasir#2026', 'label' => 'Kasir Toko', 'badge' => 'Kasir'],
    ];

    try {
        $demoUserQuery = $pdo->prepare('SELECT password FROM tbl_user WHERE username = ? LIMIT 1');
        foreach ($defaultDemoAccounts as $account) {
            $demoUserQuery->execute([$account['username']]);
            $demoUser = $demoUserQuery->fetch();
            if ($demoUser && password_verify($account['password'], $demoUser['password'])) {
                $demoAccounts[] = $account;
            }
        }
    } catch (Throwable $e) {
        $demoAccounts = $defaultDemoAccounts;
    }
}

// Proses autentikasi login saat formulir dikirim (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username !== '' && strlen($username) <= 50 && $password !== '') {
        $userQuery = $pdo->prepare(
            'SELECT id_user, nama_lengkap, username, password, role FROM tbl_user WHERE username = ? LIMIT 1'
        );
        $userQuery->execute([$username]);
        $user = $userQuery->fetch();
    } else {
        $user = false;
    }

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['id_user'] = (int) $user['id_user'];
        $_SESSION['nama'] = $user['nama_lengkap'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $rehash = $pdo->prepare('UPDATE tbl_user SET password = ? WHERE id_user = ?');
            $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id_user']]);
        }

        redirect($user['role'] === 'admin' ? 'dashboard.php' : 'pos.php');
    }

    $loginError = 'Username atau password tidak sesuai. Silakan periksa kembali kredensial Anda.';
}

$loggedOut = ($_GET['logout'] ?? '') === '1';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Petugas &amp; Kasir | AkbarMart POS</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/akbarmart-icon.svg?v=2">
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5.3.3 & Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --emerald: #10b981;
            --emerald-hover: #059669;
            --teal: #0d9488;
            --dark-navy: #0b132b;
            --slate-900: #0f172a;
            --slate-800: #1e293b;
            --slate-700: #334155;
            --slate-400: #94a3b8;
            --canvas: #f8fafc;
            --border: #e2e8f0;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--canvas);
            color: #334155;
            margin: 0;
            display: flex;
            overflow-x: hidden;
        }

        /* Split Screen Container */
        .split-screen-container {
            display: flex;
            width: 100vw;
            min-height: 100vh;
        }

        /* -------------------------------------------------------------
           SISI KIRI: BRANDING, INFORMASI TOKO & NAVIGASI CEPAT
        ------------------------------------------------------------- */
        .visual-side {
            flex: 1.15;
            position: relative;
            background: linear-gradient(145deg, #0b132b 0%, #172554 45%, #064e3b 100%);
            padding: 44px 52px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: #ffffff;
            overflow: hidden;
        }

        /* Latar Belakang Glow Blobs */
        .visual-glow-1 {
            position: absolute;
            top: -12%;
            left: -15%;
            width: 480px;
            height: 480px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(79, 70, 229, 0.42) 0%, rgba(79, 70, 229, 0) 70%);
            filter: blur(55px);
            pointer-events: none;
        }

        .visual-glow-2 {
            position: absolute;
            bottom: -15%;
            right: -10%;
            width: 520px;
            height: 520px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.38) 0%, rgba(16, 185, 129, 0) 70%);
            filter: blur(65px);
            pointer-events: none;
        }

        /* Header Sisi Kiri: Brand & Navigasi Cepat */
        .visual-header {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.22);
            padding: 8px 18px;
            border-radius: 16px;
            width: fit-content;
        }

        .brand-logo-icon {
            display: grid;
            place-items: center;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4f46e5 0%, #10b981 100%);
            color: #ffffff;
            font-size: 20px;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
        }

        /* Menu Navigasi Cepat (Pills Transparan Modern) */
        .quick-nav-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            padding: 6px;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 14px;
            width: fit-content;
        }

        .quick-nav-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12.5px;
            font-weight: 600;
            color: #e2e8f0;
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 10px;
            background: transparent;
            border: 0;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .quick-nav-pill:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.16);
            transform: translateY(-1px);
        }

        .quick-nav-pill.active {
            background: rgba(79, 70, 229, 0.4);
            color: #ffffff;
            border: 1px solid rgba(165, 180, 252, 0.3);
        }

        /* Bagian Konten Hero & Keunggulan */
        .visual-body {
            position: relative;
            z-index: 2;
            margin: auto 0;
            padding: 24px 0;
        }

        .visual-hero-text h1 {
            font-size: 36px;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -0.03em;
            margin-bottom: 14px;
        }

        .visual-hero-text .gradient-word {
            background: linear-gradient(135deg, #a5b4fc 0%, #34d399 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .visual-hero-text p {
            font-size: 14.5px;
            color: #cbd5e1;
            line-height: 1.7;
            max-width: 500px;
            margin-bottom: 28px;
        }

        /* Glassmorphism Feature Cards */
        .glass-features {
            display: flex;
            flex-direction: column;
            gap: 13px;
            max-width: 480px;
        }

        .glass-card-item {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 13px 18px;
            border-radius: 16px;
            transition: all 0.25s ease;
        }

        .glass-card-item:hover {
            transform: translateX(6px);
            background: rgba(255, 255, 255, 0.13);
            border-color: rgba(255, 255, 255, 0.25);
        }

        .glass-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            font-size: 19px;
            flex-shrink: 0;
        }

        .glass-icon-indigo {
            background: rgba(79, 70, 229, 0.25);
            color: #a5b4fc;
            border: 1px solid rgba(165, 180, 252, 0.3);
        }

        .glass-icon-emerald {
            background: rgba(16, 185, 129, 0.25);
            color: #6ee7b7;
            border: 1px solid rgba(110, 231, 183, 0.3);
        }

        .glass-icon-cyan {
            background: rgba(6, 182, 212, 0.25);
            color: #67e8f9;
            border: 1px solid rgba(103, 232, 249, 0.3);
        }

        /* Footer Sisi Kiri: Alamat & Jam Buka */
        .visual-footer {
            position: relative;
            z-index: 2;
            border-top: 1px solid rgba(255, 255, 255, 0.14);
            padding-top: 20px;
            font-size: 13px;
            color: #cbd5e1;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .visual-footer-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .visual-footer-address {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* -------------------------------------------------------------
           SISI KANAN: FORM LOGIN & TAUTAN BANTUAN
        ------------------------------------------------------------- */
        .form-side {
            flex: 0.85;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 48px 40px;
            position: relative;
        }

        .form-content-box {
            width: 100%;
            max-width: 440px;
        }

        .back-home-top {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            margin-bottom: 24px;
            padding: 6px 14px;
            border-radius: 9999px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            transition: all 0.15s ease;
        }

        .back-home-top:hover {
            color: var(--primary);
            background: #eef2ff;
            border-color: #c7d2fe;
            transform: translateX(-2px);
        }

        .login-header-group {
            margin-bottom: 26px;
        }

        .login-heading {
            font-size: 26px;
            font-weight: 800;
            color: var(--slate-900);
            letter-spacing: -0.025em;
            margin-bottom: 6px;
        }

        .login-subheading {
            font-size: 13.5px;
            color: #64748b;
            margin-bottom: 0;
            line-height: 1.5;
        }

        /* Floating Labels Minimalis & Elegan */
        .form-floating > .form-control {
            border-radius: 12px;
            border: 1.5px solid var(--border);
            font-size: 14px;
            font-weight: 500;
            height: 56px;
            padding: 1rem 1rem 0.5rem;
            color: #0f172a;
            transition: all 0.15s ease;
        }

        .form-floating > .form-control:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.14);
        }

        .form-floating > label {
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            padding: 1rem;
        }

        /* Toggle Password Button */
        .password-field-container {
            position: relative;
        }

        .btn-toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: 0;
            color: #94a3b8;
            font-size: 18px;
            cursor: pointer;
            padding: 6px 10px;
            z-index: 10;
            border-radius: 8px;
            display: grid;
            place-items: center;
            transition: color 0.15s ease;
        }

        .btn-toggle-password:hover {
            color: var(--primary);
        }

        .btn-submit-login {
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
            border: 0;
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            border-radius: 12px;
            padding: 13px 20px;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
            transition: all 0.2s ease;
        }

        .btn-submit-login:hover {
            background: linear-gradient(135deg, #4338ca 0%, #3730a3 100%);
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.42);
        }

        .btn-submit-login:active {
            transform: translateY(0);
        }

        /* Demo Login Box (Localhost) */
        .demo-box {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 14px;
            padding: 16px;
            margin-top: 22px;
        }

        .demo-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .demo-btn {
            background: #ffffff;
            border: 1px solid var(--border);
            color: #334155;
            font-size: 12px;
            font-weight: 600;
            padding: 8px 12px;
            border-radius: 8px;
            text-align: left;
            transition: all 0.15s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }

        .demo-btn:hover {
            border-color: #818cf8;
            color: var(--primary);
            background: #eef2ff;
            transform: translateY(-1px);
        }

        /* Tautan Bantuan & Status Navigasi Bawah */
        .assistance-footer {
            margin-top: 26px;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .assistance-links {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 12.5px;
        }

        .assistance-link-item {
            color: #64748b;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: transparent;
            border: 0;
            padding: 0;
            cursor: pointer;
            transition: color 0.15s ease;
        }

        .assistance-link-item:hover {
            color: var(--primary);
        }

        .security-badge {
            text-align: center;
            font-size: 11.5px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        /* Responsivitas Layar Kecil (Mobile & Tablet) */
        @media (max-width: 991.98px) {
            .split-screen-container {
                flex-direction: column;
            }
            .visual-side {
                padding: 36px 24px;
                flex: none;
            }
            .visual-hero-text h1 {
                font-size: 28px;
            }
            .glass-features {
                display: none;
            }
            .form-side {
                padding: 40px 20px 60px;
                flex: none;
            }
        }
    </style>
</head>
<body>

    <div class="split-screen-container">
        
        <!-- =========================================================
             SISI KIRI: BRANDING, INFORMASI TOKO & NAVIGASI CEPAT
        ========================================================== -->
        <div class="visual-side">
            <div class="visual-glow-1"></div>
            <div class="visual-glow-2"></div>

            <!-- Header: Logo Toko & Navigasi Cepat Tambahan -->
            <div class="visual-header">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div class="brand-badge">
                        <div class="brand-logo-icon">
                            <i class="bi bi-shop"></i>
                        </div>
                        <div>
                            <strong class="d-block text-white" style="font-size: 15px; letter-spacing: -0.02em;">AkbarMart</strong>
                            <small class="text-slate-300" style="font-size: 11px;">Smart Retail &amp; POS Suite</small>
                        </div>
                    </div>

                    <!-- Navigasi Cepat di Bagian Atas Panel Visual -->
                    <nav class="quick-nav-bar" aria-label="Navigasi Cepat Toko">
                        <a href="index.php" class="quick-nav-pill" title="Menuju ke Beranda Publik AkbarMart">
                            <i class="bi bi-house-door-fill text-warning"></i>
                            <span>Beranda</span>
                        </a>
                        <a href="index.php#layanan" class="quick-nav-pill" title="Lihat Fitur &amp; Keunggulan Layanan Toko">
                            <i class="bi bi-stars text-info"></i>
                            <span>Layanan</span>
                        </a>
                        <button type="button" class="quick-nav-pill" data-bs-toggle="modal" data-bs-target="#modalPanduan" title="Buka Panduan Penggunaan POS">
                            <i class="bi bi-journal-text text-emerald"></i>
                            <span>Panduan POS</span>
                        </button>
                        <a href="index.php#kontak" class="quick-nav-pill" title="Informasi Alamat &amp; Kontak AkbarMart">
                            <i class="bi bi-geo-alt-fill text-danger"></i>
                            <span>Kontak Toko</span>
                        </a>
                    </nav>
                </div>
            </div>

            <!-- Konten Utama: Profil Singkat & Keunggulan Toko -->
            <div class="visual-body">
                <div class="visual-hero-text">
                    <h1>
                        Kelola Transaksi Toko Lebih <span class="gradient-word">Cepat &amp; Pintar</span>
                    </h1>
                    <p>
                        Aplikasi Point of Sales modern untuk gerai ritel AkbarMart Ponorogo. Dirancang untuk mempercepat alur kasir, meminimalisir antrean, serta memberikan pelayanan terbaik bagi pelanggan setia.
                    </p>

                    <!-- 3 Keunggulan Toko dalam Bentuk Glass Card -->
                    <div class="glass-features">
                        <!-- Fitur 1: Scan Barcode -->
                        <div class="glass-card-item">
                            <div class="glass-icon-box glass-icon-indigo">
                                <i class="bi bi-upc-scan"></i>
                            </div>
                            <div>
                                <strong class="d-block text-white" style="font-size: 13.5px;">Scan Barcode Produk Kilat</strong>
                                <small class="text-slate-300">Input otomatis produk tanpa perlu pengetikan kode manual.</small>
                            </div>
                        </div>

                        <!-- Fitur 2: Diskon RFID 10% -->
                        <div class="glass-card-item">
                            <div class="glass-icon-box glass-icon-emerald">
                                <i class="bi bi-broadcast-pin"></i>
                            </div>
                            <div>
                                <strong class="d-block text-white" style="font-size: 13.5px;">Auto Diskon Member 10% via RFID</strong>
                                <small class="text-slate-300">Validasi tap ID Card NFC/RFID memotong total belanja seketika.</small>
                            </div>
                        </div>

                        <!-- Fitur 3: Struk Kasir Otomatis -->
                        <div class="glass-card-item">
                            <div class="glass-icon-box glass-icon-cyan">
                                <i class="bi bi-printer-fill"></i>
                            </div>
                            <div>
                                <strong class="d-block text-white" style="font-size: 13.5px;">Cetak Struk Kasir Otomatis</strong>
                                <small class="text-slate-300">Format thermal receipt standar 80 mm rapi dengan Barcode 128.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Sisi Kiri: Alamat Lengkap & Status Toko -->
            <div class="visual-footer">
                <div class="visual-footer-row">
                    <div class="visual-footer-address">
                        <i class="bi bi-geo-alt-fill text-danger"></i>
                        <span><strong>Jl. Gajah Mada No. 12, Ponorogo</strong>, Jawa Timur 63416</span>
                    </div>
                    <div>
                        <span class="badge bg-emerald text-white rounded-pill px-3 py-1" style="font-size: 11px;">
                            <i class="bi bi-clock-fill me-1"></i> Buka 07.00 – 22.00 WIB
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================
             SISI KANAN: FORM LOGIN & TAUTAN BANTUAN
        ========================================================== -->
        <div class="form-side">
            <div class="form-content-box">
                
                <!-- Tautan Kembali ke Beranda Utama -->
                <a href="index.php" class="back-home-top">
                    <i class="bi bi-arrow-left"></i>
                    <span>Kembali ke Beranda Utama</span>
                </a>

                <div class="login-header-group">
                    <h2 class="login-heading">Masuk ke Sistem Kasir</h2>
                    <p class="login-subheading">Masukkan username dan password Anda untuk memulai shift kerja.</p>
                </div>

                <!-- Notifikasi Logout -->
                <?php if ($loggedOut): ?>
                    <div class="alert alert-success d-flex align-items-center gap-2 py-2 px-3 small rounded-3 mb-4" role="status">
                        <i class="bi bi-check-circle-fill text-success fs-6"></i>
                        <div>Anda telah berhasil keluar (logout) dari sistem kasir.</div>
                    </div>
                <?php endif; ?>

                <!-- Notifikasi Error Login -->
                <?php if ($loginError !== ''): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2 py-2 px-3 small rounded-3 mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill text-danger fs-6"></i>
                        <div><?= e($loginError) ?></div>
                    </div>
                <?php endif; ?>

                <!-- Formulir Login Petugas / Kasir -->
                <form id="login-form" method="post" action="login.php" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <!-- Floating Username Input -->
                    <div class="form-floating mb-3">
                        <input 
                            type="text" 
                            class="form-control" 
                            id="username" 
                            name="username" 
                            placeholder="Username" 
                            maxlength="50" 
                            autocomplete="username" 
                            required 
                            autofocus 
                            value="<?= e($_POST['username'] ?? '') ?>"
                        >
                        <label for="username">
                            <i class="bi bi-person me-1 text-primary"></i> Username Akun
                        </label>
                    </div>

                    <!-- Floating Password Input with Show/Hide Toggle -->
                    <div class="password-field-container mb-4">
                        <div class="form-floating">
                            <input 
                                type="password" 
                                class="form-control pe-5" 
                                id="password" 
                                name="password" 
                                placeholder="Password" 
                                autocomplete="current-password" 
                                required
                            >
                            <label for="password">
                                <i class="bi bi-lock me-1 text-primary"></i> Kata Sandi (Password)
                            </label>
                        </div>
                        <button type="button" class="btn-toggle-password" id="togglePasswordBtn" title="Tampilkan/Sembunyikan Kata Sandi" aria-label="Toggle password visibility">
                            <i class="bi bi-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>

                    <!-- Tombol Login Utama -->
                    <button class="btn btn-submit-login w-100 d-flex align-items-center justify-content-center gap-2" type="submit">
                        <i class="bi bi-box-arrow-in-right fs-5"></i>
                        <span>Masuk ke Sistem</span>
                    </button>
                </form>

                <!-- Tombol Demo Login Cepat (Khusus Pengujian Localhost) -->
                <?php if ($demoAccounts !== []): ?>
                    <div class="demo-box">
                        <div class="demo-title">
                            <i class="bi bi-lightning-charge-fill text-warning"></i>
                            <span>Login Cepat Akun Demo (Localhost)</span>
                        </div>
                        <div class="d-grid gap-2">
                            <?php foreach ($demoAccounts as $account): ?>
                                <button
                                    class="demo-btn demo-login-button"
                                    type="button"
                                    data-demo-username="<?= e($account['username']) ?>"
                                    data-demo-password="<?= e($account['password']) ?>"
                                >
                                    <span>
                                        <i class="bi bi-person-check text-primary me-2"></i>
                                        Masuk sebagai <strong><?= e($account['label']) ?></strong>
                                    </span>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 10px;">
                                        <?= e($account['badge']) ?>
                                    </span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Tautan Bantuan, Diagnostik Koneksi & Navigasi Tambahan -->
                <div class="assistance-footer">
                    <div class="assistance-links">
                        <button type="button" class="assistance-link-item" data-bs-toggle="modal" data-bs-target="#modalBantuan">
                            <i class="bi bi-question-circle text-primary"></i>
                            <span>Bantuan Sistem</span>
                        </button>
                        <span class="text-slate-300">·</span>
                        <button type="button" class="assistance-link-item" data-bs-toggle="modal" data-bs-target="#modalStatusDb">
                            <i class="bi bi-database-check <?= $dbStatus['connected'] ? 'text-success' : 'text-danger' ?>"></i>
                            <span>Status Koneksi Database</span>
                        </button>
                        <span class="text-slate-300">·</span>
                        <a href="index.php" class="assistance-link-item">
                            <i class="bi bi-house-door text-info"></i>
                            <span>Kembali ke Beranda</span>
                        </a>
                    </div>

                    <div class="security-badge">
                        <i class="bi bi-shield-check text-success"></i>
                        <span>Dilindungi Enkripsi Sesi &amp; Token CSRF AkbarMart POS v2.0</span>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- =========================================================
         MODAL 1: PANDUAN PENGGUNAAN POS AKBARMART
    ========================================================== -->
    <div class="modal fade" id="modalPanduan" tabindex="-1" aria-labelledby="modalPanduanLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom px-4 py-3 bg-light rounded-top-4">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-primary text-white p-2 rounded-3" style="width: 34px; height: 34px; display: grid; place-items: center;">
                            <i class="bi bi-journal-text fs-6"></i>
                        </div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="modalPanduanLabel">Panduan Operasional Kasir AkbarMart</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 h-100 bg-white">
                                <h6 class="fw-bold text-primary d-flex align-items-center gap-2 mb-2">
                                    <i class="bi bi-upc-scan fs-5"></i> 1. Scan Barcode Barang
                                </h6>
                                <p class="small text-muted mb-0">
                                    Arahkan scanner USB HID ke barcode produk. Barang langsung otomatis masuk ke daftar belanja dengan harga dan kalkulasi subtotal terkini.
                                </p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 h-100 bg-white">
                                <h6 class="fw-bold text-success d-flex align-items-center gap-2 mb-2">
                                    <i class="bi bi-broadcast-pin fs-5"></i> 2. Auto Diskon Member 10%
                                </h6>
                                <p class="small text-muted mb-0">
                                    Cukup tap kartu ID Card RFID/NFC pelanggan pada reader. Sistem memverifikasi member aktif dan memotong total belanja sebesar 10% secara instan.
                                </p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 h-100 bg-white">
                                <h6 class="fw-bold text-info d-flex align-items-center gap-2 mb-2">
                                    <i class="bi bi-keyboard fs-5"></i> 3. Tombol Shortcut Kasir
                                </h6>
                                <ul class="small text-muted mb-0 ps-3">
                                    <li><strong>F1:</strong> Fokus cepat ke scanner ID Card Member.</li>
                                    <li><strong>F2:</strong> Fokus pencarian barcode produk.</li>
                                    <li><strong>F9:</strong> Fokus nominal bayar &amp; checkout.</li>
                                </ul>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 h-100 bg-white">
                                <h6 class="fw-bold text-warning-emphasis d-flex align-items-center gap-2 mb-2">
                                    <i class="bi bi-printer fs-5"></i> 4. Cetak Struk Kasir
                                </h6>
                                <p class="small text-muted mb-0">
                                    Setelah pembayaran selesai, pilih "Cetak Struk" untuk mengeluarkan nota belanja format printer thermal 80 mm lengkap dengan Barcode Code 128.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-2 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Tutup</button>
                    <a href="index.php" class="btn btn-primary btn-sm px-3">Buka Beranda Toko</a>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================
         MODAL 2: BANTUAN SISTEM & DUKUNGAN TEKNIS
    ========================================================== -->
    <div class="modal fade" id="modalBantuan" tabindex="-1" aria-labelledby="modalBantuanLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom px-4 py-3 bg-light rounded-top-4">
                    <div class="d-flex align-items-center gap-2">
                        <div class="bg-indigo text-white p-2 rounded-3" style="width: 34px; height: 34px; display: grid; place-items: center; background: #4f46e5;">
                            <i class="bi bi-question-circle fs-6"></i>
                        </div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="modalBantuanLabel">Pusat Bantuan Sistem Kasir</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <strong class="d-block text-dark small mb-1">Kendala Login atau Lupa Kata Sandi?</strong>
                        <p class="small text-muted mb-2">
                            Hubungi Administrator Toko AkbarMart untuk melakukan reset password akun kasir Anda melalui menu Manajemen Petugas.
                        </p>
                    </div>

                    <div class="mb-3">
                        <strong class="d-block text-dark small mb-1">Kontak Layanan Pelanggan &amp; IT Toko:</strong>
                        <div class="p-3 bg-light rounded-3 d-flex align-items-center justify-content-between">
                            <div>
                                <span class="d-block fw-bold text-primary small">WhatsApp Customer Care</span>
                                <small class="text-muted">+62 812-3456-7890</small>
                            </div>
                            <a href="https://wa.me/6281234567890" target="_blank" rel="noopener" class="btn btn-sm btn-success">
                                <i class="bi bi-whatsapp me-1"></i> Chat WA
                            </a>
                        </div>
                    </div>

                    <div>
                        <strong class="d-block text-dark small mb-1">Alamat Gerai AkbarMart:</strong>
                        <p class="small text-muted mb-0">
                            Jl. Gajah Mada No. 12, Kel. Kepatihan, Kec. Ponorogo, Kabupaten Ponorogo, Jawa Timur 63416.
                        </p>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-2 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================
         MODAL 3: DIAGNOSTIK STATUS KONEKSI DATABASE
    ========================================================== -->
    <div class="modal fade" id="modalStatusDb" tabindex="-1" aria-labelledby="modalStatusDbLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom px-4 py-3 bg-light rounded-top-4">
                    <div class="d-flex align-items-center gap-2">
                        <div class="p-2 rounded-3 text-white" style="width: 34px; height: 34px; display: grid; place-items: center; background: <?= $dbStatus['connected'] ? '#10b981' : '#ef4444' ?>;">
                            <i class="bi bi-database fs-6"></i>
                        </div>
                        <h5 class="modal-title fw-bold text-dark fs-6" id="modalStatusDbLabel">Status Koneksi Database</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="d-flex align-items-center gap-3 p-3 rounded-3 mb-3 <?= $dbStatus['connected'] ? 'bg-success-subtle border border-success-subtle' : 'bg-danger-subtle border border-danger-subtle' ?>">
                        <i class="bi <?= $dbStatus['connected'] ? 'bi-check-circle-fill text-success fs-3' : 'bi-x-circle-fill text-danger fs-3' ?>"></i>
                        <div>
                            <strong class="d-block <?= $dbStatus['connected'] ? 'text-success-emphasis' : 'text-danger-emphasis' ?>">
                                <?= $dbStatus['connected'] ? 'Koneksi Database MySQL Berhasil (Online)' : 'Koneksi Database Terputus / Gagal' ?>
                            </strong>
                            <small class="text-muted">
                                <?= $dbStatus['connected'] ? 'Server MySQL siap memproses transaksi kasir AkbarMart.' : 'Pastikan modul MySQL di XAMPP Control Panel telah aktif.' ?>
                            </small>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered small mb-0">
                            <tbody>
                                <tr>
                                    <th class="bg-light text-muted" style="width: 40%;">Host Server</th>
                                    <td><?= e($dbStatus['host']) ?></td>
                                </tr>
                                <tr>
                                    <th class="bg-light text-muted">Nama Database</th>
                                    <td><strong><?= e($dbStatus['database']) ?></strong></td>
                                </tr>
                                <tr>
                                    <th class="bg-light text-muted">Versi MySQL</th>
                                    <td><?= e($dbStatus['version']) ?></td>
                                </tr>
                                <tr>
                                    <th class="bg-light text-muted">Driver PDO</th>
                                    <td>PHP PDO MySQL (UTF8MB4)</td>
                                </tr>
                                <tr>
                                    <th class="bg-light text-muted">Status Tabel</th>
                                    <td><span class="badge bg-primary"><?= (int) $dbStatus['tables_count'] ?> Tabel Siap</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-2 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5.3.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Fitur Interaktif Show/Hide Password
        var togglePasswordBtn = document.getElementById('togglePasswordBtn');
        var passwordInput = document.getElementById('password');
        var togglePasswordIcon = document.getElementById('togglePasswordIcon');

        if (togglePasswordBtn && passwordInput && togglePasswordIcon) {
            togglePasswordBtn.addEventListener('click', function () {
                var isPassword = passwordInput.type === 'password';
                passwordInput.type = isPassword ? 'text' : 'password';
                togglePasswordIcon.className = isPassword ? 'bi bi-eye-slash text-primary' : 'bi bi-eye';
            });
        }

        // Script Otomatis Demo Login Cepat (Localhost)
        document.querySelectorAll('.demo-login-button').forEach(function (button) {
            button.addEventListener('click', function () {
                var usernameEl = document.getElementById('username');
                var passwordEl = document.getElementById('password');
                if (usernameEl && passwordEl) {
                    usernameEl.value = button.dataset.demoUsername;
                    passwordEl.value = button.dataset.demoPassword;
                    document.getElementById('login-form').requestSubmit();
                }
            });
        });
    </script>
</body>
</html>
