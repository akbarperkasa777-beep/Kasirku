<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/koneksi.php';

// Ambil statistik ringkas untuk ditampilkan di landing page jika database tersedia
try {
    $totalProduk = (int) $pdo->query("SELECT COUNT(*) FROM tbl_produk")->fetchColumn();
    $totalKategori = (int) $pdo->query("SELECT COUNT(*) FROM tbl_kategori")->fetchColumn();
    $totalMember = (int) $pdo->query("SELECT COUNT(*) FROM tbl_pelanggan WHERE id_card_rfid IS NOT NULL")->fetchColumn();
    $totalTransaksi = (int) $pdo->query("SELECT COUNT(*) FROM tbl_transaksi WHERE simulasi_pembayaran = 0")->fetchColumn();
} catch (Throwable $e) {
    $totalProduk = 48;
    $totalKategori = 6;
    $totalMember = 24;
    $totalTransaksi = 120;
}

$userLoggedIn = is_authenticated();
$userRole = $_SESSION['role'] ?? null;
$userName = $_SESSION['nama'] ?? null;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AkbarMart — Solusi Belanja &amp; Kasir Pintar | Ponorogo</title>
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
            --primary-dark: #3730a3;
            --emerald: #10b981;
            --emerald-dark: #059669;
            --teal: #0d9488;
            --dark-navy: #0b1329;
            --slate-800: #1e293b;
            --slate-900: #0f172a;
            --canvas: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--canvas);
            color: #334155;
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* Navbar Styling */
        .landing-nav {
            position: sticky;
            top: 0;
            z-index: 1030;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            transition: all 0.25s ease;
        }

        .brand-logo-box {
            display: grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #4f46e5 0%, #10b981 100%);
            color: #ffffff;
            font-size: 20px;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.25);
        }

        .brand-title {
            font-size: 19px;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--slate-900);
            line-height: 1.2;
        }

        .brand-subtitle {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
        }

        .nav-link-custom {
            font-size: 14px;
            font-weight: 600;
            color: #475569;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.15s ease;
        }

        .nav-link-custom:hover {
            color: var(--primary);
            background: #eef2ff;
        }

        /* Hero Section */
        .hero-section {
            position: relative;
            background: linear-gradient(145deg, #0b132b 0%, #172554 45%, #064e3b 100%);
            color: #ffffff;
            padding: 90px 0 100px;
            overflow: hidden;
        }

        .hero-glow-1 {
            position: absolute;
            top: -15%;
            left: -10%;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(79, 70, 229, 0.35) 0%, rgba(79, 70, 229, 0) 70%);
            filter: blur(50px);
            pointer-events: none;
        }

        .hero-glow-2 {
            position: absolute;
            bottom: -20%;
            right: -5%;
            width: 600px;
            height: 600px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.3) 0%, rgba(16, 185, 129, 0) 70%);
            filter: blur(60px);
            pointer-events: none;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            font-size: 12.5px;
            font-weight: 600;
            color: #a7f3d0;
            margin-bottom: 24px;
        }

        .hero-title {
            font-size: 42px;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -0.03em;
            margin-bottom: 20px;
        }

        .hero-title .gradient-text {
            background: linear-gradient(135deg, #a5b4fc 0%, #34d399 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-subtitle {
            font-size: 16.5px;
            color: #cbd5e1;
            line-height: 1.7;
            max-width: 580px;
            margin-bottom: 36px;
        }

        .btn-hero-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            font-weight: 700;
            padding: 13px 28px;
            border-radius: 12px;
            border: 0;
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.35);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-hero-primary:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45);
        }

        .btn-hero-secondary {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            font-weight: 600;
            padding: 13px 26px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(8px);
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-hero-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.4);
            transform: translateY(-2px);
        }

        /* Glassmorphism Mockup Card on Hero */
        .hero-mockup-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.35);
        }

        .mockup-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            margin-bottom: 20px;
        }

        .mockup-badge-live {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
            border: 1px solid rgba(52, 211, 153, 0.35);
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 11.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .mockup-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.09);
            border-radius: 14px;
            padding: 12px 16px;
            margin-bottom: 12px;
        }

        .mockup-discount-badge {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.25) 0%, rgba(239, 68, 68, 0.25) 100%);
            border: 1px solid rgba(245, 158, 11, 0.4);
            color: #fcd34d;
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .mockup-rfid-status {
            color: #fcd34d; 
            font-weight: 600;
        }

        .mockup-total-box {
            background: rgba(15, 23, 42, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Feature Cards */
        .features-section {
            padding: 90px 0;
            background-color: var(--canvas);
        }

        .section-header {
            text-align: center;
            max-width: 680px;
            margin: 0 auto 60px;
        }

        .section-tag {
            display: inline-block;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--primary);
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            padding: 4px 12px;
            border-radius: 9999px;
            margin-bottom: 12px;
        }

        .section-main-title {
            font-size: 32px;
            font-weight: 800;
            color: var(--slate-900);
            letter-spacing: -0.025em;
            margin-bottom: 12px;
        }

        .section-desc {
            font-size: 15px;
            color: #64748b;
        }

        .feature-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 32px 28px;
            height: 100%;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
            display: flex;
            flex-direction: column;
        }

        .feature-card:hover {
            transform: translateY(-6px);
            border-color: #c7d2fe;
            box-shadow: 0 16px 32px -4px rgba(79, 70, 229, 0.12), 0 4px 12px rgba(15, 23, 42, 0.04);
        }

        .feature-icon-wrapper {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            font-size: 26px;
            margin-bottom: 22px;
            flex-shrink: 0;
        }

        .icon-blue-grad {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            color: #4f46e5;
        }

        .icon-emerald-grad {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #059669;
        }

        .icon-amber-grad {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #d97706;
        }

        .icon-teal-grad {
            background: linear-gradient(135deg, #ccfbf1 0%, #99f6e4 100%);
            color: #0f766e;
        }

        .feature-card h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--slate-900);
            margin-bottom: 12px;
            letter-spacing: -0.02em;
        }

        .feature-card p {
            font-size: 13.5px;
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 18px;
            flex-grow: 1;
        }

        .feature-badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 700;
            color: #4f46e5;
            background: #eef2ff;
            padding: 4px 10px;
            border-radius: 9999px;
            width: fit-content;
        }

        /* Stats Bar Banner */
        .stats-banner {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: #ffffff;
            padding: 44px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .stat-item {
            text-align: center;
            padding: 12px 20px;
        }

        .stat-number {
            font-size: 34px;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: #38bdf8;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 12.5px;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Location & Hours Section */
        .location-section {
            padding: 90px 0;
            background: #ffffff;
        }

        .info-card {
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 32px;
            height: 100%;
        }

        .info-box {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 24px;
        }

        .info-box:last-child {
            margin-bottom: 0;
        }

        .info-box-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid var(--border);
            color: var(--primary);
            font-size: 20px;
            display: grid;
            place-items: center;
            flex-shrink: 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        }

        .info-box strong {
            display: block;
            font-size: 14.5px;
            color: var(--slate-900);
            margin-bottom: 3px;
        }

        .info-box span, .info-box a {
            font-size: 13px;
            color: #64748b;
            text-decoration: none;
        }

        .info-box a:hover {
            color: var(--primary);
        }

        /* Map Embed Container */
        .map-wrapper {
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
            border: 1px solid var(--border);
            height: 100%;
            min-height: 360px;
            position: relative;
        }

        .map-wrapper iframe {
            width: 100%;
            height: 100%;
            min-height: 360px;
            border: 0;
        }

        /* Footer */
        .landing-footer {
            background: var(--dark-navy);
            color: #94a3b8;
            padding: 60px 0 30px;
            font-size: 13.5px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .footer-brand strong {
            font-size: 20px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.02em;
        }

        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-links li {
            margin-bottom: 10px;
        }

        .footer-links a {
            color: #94a3b8;
            text-decoration: none;
            transition: color 0.15s ease;
        }

        .footer-links a:hover {
            color: #ffffff;
        }

        .social-button {
            display: inline-grid;
            place-items: center;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            font-size: 16px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .social-button:hover {
            background: var(--primary);
            color: #ffffff;
            transform: translateY(-2px);
        }

        @media (max-width: 991.98px) {
            .hero-section {
                padding: 60px 0 70px;
            }
            .hero-title {
                font-size: 32px;
            }
            .hero-mockup-card {
                margin-top: 40px;
            }
            .section-main-title {
                font-size: 26px;
            }
        }
    </style>
</head>
<body>

    <!-- Sticky Glassmorphic Navbar -->
    <nav class="navbar navbar-expand-lg landing-nav">
        <div class="container py-1">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <div class="brand-logo-box">
                    <i class="bi bi-shop"></i>
                </div>
                <div>
                    <span class="brand-title d-block">AkbarMart</span>
                    <span class="brand-subtitle">Smart Retail &amp; POS Suite</span>
                </div>
            </a>

            <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu" aria-controls="navMenu" aria-label="Buka menu navigasi">
                <i class="bi bi-list fs-3 text-dark"></i>
            </button>

            <div class="collapse navbar-collapse" id="navMenu">
                <ul class="navbar-nav mx-auto mb-2 mb-lg-0 gap-1">
                    <li class="nav-item">
                        <a class="nav-link-custom" href="#beranda">Beranda</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link-custom" href="#layanan">Keunggulan Toko</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link-custom" href="#lokasi">Lokasi &amp; Jam Buka</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link-custom" href="#kontak">Kontak</a>
                    </li>
                </ul>

                <div class="d-flex align-items-center gap-2">
                    <?php if ($userLoggedIn): ?>
                        <div class="dropdown">
                            <button class="btn btn-light dropdown-toggle d-flex align-items-center gap-2 border" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-person-circle text-primary"></i>
                                <span class="fw-semibold small"><?= e($userName) ?></span>
                                <span class="badge bg-primary-subtle text-primary" style="font-size: 10px;"><?= e(strtoupper($userRole ?? '')) ?></span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <?php if ($userRole === 'admin'): ?>
                                    <li><a class="dropdown-item" href="dashboard.php"><i class="bi bi-grid-1x2-fill text-primary me-2"></i>Dashboard Admin</a></li>
                                <?php endif; ?>
                                <?php if ($userRole === 'pelanggan'): ?>
                                    <li><a class="dropdown-item" href="belanja_pelanggan.php"><i class="bi bi-bag-heart-fill text-success me-2"></i>Belanja Mandiri</a></li>
                                <?php else: ?>
                                    <li><a class="dropdown-item" href="pos.php"><i class="bi bi-cart-check-fill text-success me-2"></i>Layar Kasir (POS)</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="post" action="logout.php" class="px-3 py-1">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">Logout</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                        <a href="<?= $userRole === 'pelanggan' ? 'belanja_pelanggan.php' : 'pos.php' ?>" class="btn btn-primary btn-sm px-3 py-2 fw-semibold">
                            <i class="bi <?= $userRole === 'pelanggan' ? 'bi-bag-heart' : 'bi-cart3' ?> me-1"></i>
                            <?= $userRole === 'pelanggan' ? 'Mulai Belanja' : 'Buka Kasir' ?>
                        </a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-primary px-4 py-2 fw-semibold d-inline-flex align-items-center gap-2" style="border-radius: 10px;">
                            <i class="bi bi-box-arrow-in-right"></i>
                            <span>Login Petugas / Pelanggan</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <header class="hero-section" id="beranda">
        <div class="hero-glow-1"></div>
        <div class="hero-glow-2"></div>

        <div class="container position-relative">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <div class="hero-badge">
                        <i class="bi bi-geo-alt-fill text-emerald"></i>
                        <span>Jl. Gajah Mada No. 12, Ponorogo · Buka Setiap Hari</span>
                    </div>

                    <h1 class="hero-title">
                        Selamat Datang di <span class="gradient-text">AkbarMart</span><br>
                        Solusi Belanja &amp; Kasir Pintar
                    </h1>

                    <p class="hero-subtitle">
                        Toko retail pilihan masyarakat Ponorogo dengan integrasi teknologi kasir modern. Transaksi secepat kilat dengan barcode scanner, diskon otomatis 10% untuk member via tap ID Card RFID, serta pencetakan struk termal instan.
                    </p>

                    <div class="d-flex flex-wrap gap-3">
                        <?php if ($userLoggedIn): ?>
                            <a href="<?= $userRole === 'pelanggan' ? 'belanja_pelanggan.php' : 'pos.php' ?>" class="btn-hero-primary">
                                <i class="bi <?= $userRole === 'pelanggan' ? 'bi-bag-heart-fill' : 'bi-cart-plus-fill' ?> fs-5"></i>
                                <span><?= $userRole === 'pelanggan' ? 'Mulai Belanja Mandiri' : 'Buka Layar Kasir (POS)' ?></span>
                            </a>
                            <?php if ($userRole === 'admin'): ?>
                                <a href="dashboard.php" class="btn-hero-secondary">
                                    <i class="bi bi-speedometer2"></i>
                                    <span>Dashboard Admin</span>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <a href="login.php" class="btn-hero-primary">
                                <i class="bi bi-box-arrow-in-right fs-5"></i>
                                <span>Masuk ke Sistem Kasir</span>
                            </a>
                            <a href="#layanan" class="btn-hero-secondary">
                                <i class="bi bi-stars"></i>
                                <span>Jelajahi Fitur &amp; Layanan</span>
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex align-items-center gap-4 mt-5 pt-3 border-top border-white border-opacity-10">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-check-circle-fill text-success fs-5"></i>
                            <span class="small text-slate-300">Checkout Cepat &amp; Akurat</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-check-circle-fill text-success fs-5"></i>
                            <span class="small text-slate-300">Tap RFID Member Diskon 10%</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 d-none d-md-flex">
                            <i class="bi bi-check-circle-fill text-success fs-5"></i>
                            <span class="small text-slate-300">Struk Termal Otomatis</span>
                        </div>
                    </div>
                </div>

                <!-- Right Visual: Floating Glassmorphism POS Mockup -->
                <div class="col-lg-5">
                    <div class="hero-mockup-card">
                        <div class="mockup-header">
                            <div>
                                <span class="d-block text-white fw-bold fs-6">Sistem Kasir Pintar</span>
                                <small class="text-slate-400">Terminal Kasir AkbarMart #01</small>
                            </div>
                            <span class="mockup-badge-live">
                                <span class="spinner-grow spinner-grow-sm text-success" style="width: 8px; height: 8px;" role="status"></span>
                                Online &amp; Siap
                            </span>
                        </div>

                        <!-- Mockup Items -->
                        <div class="mockup-item">
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-primary bg-opacity-25 p-2 rounded-3 text-indigo-300">
                                    <i class="bi bi-cup-hot-fill fs-5"></i>
                                </div>
                                <div>
                                    <strong class="d-block text-white" style="font-size: 13.5px;">Kopi Susu 250ml</strong>
                                    <small class="text-slate-400">Barcode: AKM8892 · 2 pcs</small>
                                </div>
                            </div>
                            <span class="text-white fw-bold">Rp 24.000</span>
                        </div>

                        <div class="mockup-item">
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-emerald bg-opacity-25 p-2 rounded-3 text-emerald-300">
                                    <i class="bi bi-basket-fill fs-5"></i>
                                </div>
                                <div>
                                    <strong class="d-block text-white" style="font-size: 13.5px;">Roti Cokelat Spesial</strong>
                                    <small class="text-slate-400">Barcode: AKM1044 · 1 pcs</small>
                                </div>
                            </div>
                            <span class="text-white fw-bold">Rp 8.500</span>
                        </div>

                        <!-- Member RFID Tap Notification -->
                        <div class="mockup-discount-badge">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-broadcast-pin fs-5 text-warning"></i>
                                <div>
                                    <strong class="d-block" style="font-size: 12.5px;">Member Terdeteksi: Budi Santoso</strong>
                                    <small class="mockup-rfid-status">RFID Scan Sukses · Diskon 10%</small>
                                </div>
                            </div>
                            <span class="badge bg-warning text-dark fw-bold">-Rp 3.250</span>
                        </div>

                        <!-- Total Box -->
                        <div class="mockup-total-box">
                            <div>
                                <small class="text-slate-400 d-block">TOTAL BELANJA</small>
                                <span class="fs-4 fw-extrabold text-white">Rp 29.250</span>
                            </div>
                            <span class="badge bg-success py-2 px-3 fw-bold">
                                <i class="bi bi-printer me-1"></i> Cetak Struk
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Stats Bar Banner -->
    <section class="stats-banner">
        <div class="container">
            <div class="row g-4 justify-content-center">
                <div class="col-6 col-md-3">
                    <div class="stat-item">
                        <div class="stat-number"><?= number_format($totalProduk, 0, ',', '.') ?>+</div>
                        <div class="stat-label">Produk Siap Jual</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-item">
                        <div class="stat-number">10%</div>
                        <div class="stat-label">Diskon Otomatis Member</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-item">
                        <div class="stat-number">&lt; 3 Detik</div>
                        <div class="stat-label">Kecepatan Scan &amp; Kasir</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-item">
                        <div class="stat-number">80 mm</div>
                        <div class="stat-label">Struk Kasir Thermal</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Services & Features Section -->
    <section class="features-section" id="layanan">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">Keunggulan &amp; Fitur Unggulan</span>
                <h2 class="section-main-title">Belanja Pintar, Layanan Lebih Cepat</h2>
                <p class="section-desc">
                    AkbarMart memadukan kelengkapan barang harian berkualitas dengan sistem POS otomatis agar pengalaman belanja pelanggan menjadi nyaman, transparan, dan efisien.
                </p>
            </div>

            <div class="row g-4">
                <!-- Feature 1: Barcode Scan -->
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon-wrapper icon-blue-grad">
                            <i class="bi bi-upc-scan"></i>
                        </div>
                        <h3>Scan Barcode Produk Cepat</h3>
                        <p>
                            Transaksi kilat menggunakan scanner barcode USB HID. Produk otomatis masuk ke keranjang kasir dalam satu detik tanpa input manual.
                        </p>
                        <span class="feature-badge-pill">
                            <i class="bi bi-lightning-charge-fill"></i> Zero Delay
                        </span>
                    </div>
                </div>

                <!-- Feature 2: Realtime Inventory -->
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon-wrapper icon-emerald-grad">
                            <i class="bi bi-boxes"></i>
                        </div>
                        <h3>Manajemen Inventaris Real-Time</h3>
                        <p>
                            Stok barang tersinkronisasi otomatis pada setiap transaksi. Dilengkapi peringatan dini stok menipis (&lt; 5 pcs) dan restock kilat.
                        </p>
                        <span class="feature-badge-pill" style="color: #059669; background: #ecfdf5;">
                            <i class="bi bi-shield-check"></i> ACID Transaction
                        </span>
                    </div>
                </div>

                <!-- Feature 3: Member RFID Discount 10% -->
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon-wrapper icon-amber-grad">
                            <i class="bi bi-person-vcard-fill"></i>
                        </div>
                        <h3>Diskon 10% Member via Tap RFID</h3>
                        <p>
                            Keuntungan eksklusif bagi member. Cukup tap kartu RFID/NFC pada kasir, potongan 10% langsung dihitung dan mengurangi total belanja otomatis.
                        </p>
                        <span class="feature-badge-pill" style="color: #d97706; background: #fffbeb;">
                            <i class="bi bi-broadcast-pin"></i> RFID / NFC Tap
                        </span>
                    </div>
                </div>

                <!-- Feature 4: Thermal Receipt -->
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon-wrapper icon-teal-grad">
                            <i class="bi bi-printer-fill"></i>
                        </div>
                        <h3>Cetak Struk Kasir Otomatis</h3>
                        <p>
                            Pencetakan struk thermal 80 mm / 72 mm berstandar POS modern dengan Barcode Code 128 untuk transparansi dan rekap belanja pelanggan.
                        </p>
                        <span class="feature-badge-pill" style="color: #0f766e; background: #f0fdfa;">
                            <i class="bi bi-receipt"></i> Auto Print Ready
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Location & Operating Hours Section -->
    <section class="location-section" id="lokasi">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">Lokasi &amp; Jam Layanan</span>
                <h2 class="section-main-title">Kunjungi Toko AkbarMart Ponorogo</h2>
                <p class="section-desc">
                    Terletak strategis di pusat kota Ponorogo, kami siap menyambut Anda dengan koleksi produk lengkap, harga kompetitif, dan pelayanan yang ramah.
                </p>
            </div>

            <div class="row g-4 align-items-stretch">
                <!-- Left: Visual Map Container -->
                <div class="col-lg-7">
                    <div class="map-wrapper">
                        <!-- Google Maps Embed for Jl. Gajah Mada No. 12, Ponorogo -->
                        <iframe 
                            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3953.078426021671!2d111.46467367499778!3d-7.868725892153282!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x2e79a037803a0a45%3A0xe54dc590dcfc43bc!2sJl.%20Gajah%20Mada%20No.12%2C%20Krajan%2C%20Kepatihan%2C%20Kec.%20Ponorogo%2C%20Kabupaten%20Ponorogo%2C%20Jawa%20Timur%2063416!5e0!3m2!1sid!2sid!4v1700000000000!5m2!1sid!2sid" 
                            allowfullscreen="" 
                            loading="lazy" 
                            referrerpolicy="no-referrer-when-downgrade"
                            title="Peta Lokasi Toko AkbarMart Ponorogo">
                        </iframe>
                    </div>
                </div>

                <!-- Right: Store Details Card -->
                <div class="col-lg-5" id="kontak">
                    <div class="info-card d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                                <h3 class="h5 fw-bold text-slate-900 mb-0">Informasi Gerai AkbarMart</h3>
                                <span class="badge bg-success-subtle text-success border border-success-subtle py-1 px-3 rounded-pill fw-semibold">
                                    <i class="bi bi-circle-fill me-1" style="font-size: 7px;"></i> Buka Hari Ini
                                </span>
                            </div>

                            <!-- Alamat -->
                            <div class="info-box">
                                <div class="info-box-icon text-primary">
                                    <i class="bi bi-geo-alt-fill"></i>
                                </div>
                                <div>
                                    <strong>Alamat Lengkap Toko</strong>
                                    <span>Jl. Gajah Mada No. 12, Kel. Kepatihan, Kec. Ponorogo, Kabupaten Ponorogo, Jawa Timur 63416</span>
                                </div>
                            </div>

                            <!-- Jam Operasional -->
                            <div class="info-box">
                                <div class="info-box-icon text-success">
                                    <i class="bi bi-clock-fill"></i>
                                </div>
                                <div>
                                    <strong>Jam Operasional</strong>
                                    <span>Senin – Minggu: <strong>07.00 – 22.00 WIB</strong></span>
                                    <small class="text-muted d-block mt-1">Layanan kasir POS &amp; pendaftaran member siaga setiap hari.</small>
                                </div>
                            </div>

                            <!-- Kontak Kasir & CS -->
                            <div class="info-box">
                                <div class="info-box-icon text-info">
                                    <i class="bi bi-whatsapp"></i>
                                </div>
                                <div>
                                    <strong>WhatsApp &amp; Layanan Pelanggan</strong>
                                    <a href="https://wa.me/6281234567890" target="_blank" rel="noopener" class="fw-semibold text-primary d-block">
                                        +62 812-3456-7890 (Chat Kasir)
                                    </a>
                                    <small class="text-muted">Hubungi untuk cek ketersediaan barang atau bantuan akun member.</small>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Links Action -->
                        <div class="pt-4 mt-3 border-top d-flex gap-2">
                            <a href="https://maps.google.com/?q=Jl.+Gajah+Mada+No.12+Ponorogo" target="_blank" rel="noopener" class="btn btn-primary flex-grow-1 fw-semibold py-2">
                                <i class="bi bi-compass me-1"></i> Petunjuk Arah
                            </a>
                            <a href="login.php" class="btn btn-outline-secondary fw-semibold py-2">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Login Kasir
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="landing-footer">
        <div class="container">
            <div class="row g-4 mb-4">
                <div class="col-lg-5">
                    <div class="footer-brand d-flex align-items-center gap-2 mb-3">
                        <div class="brand-logo-box" style="width: 36px; height: 36px; font-size: 17px;">
                            <i class="bi bi-shop"></i>
                        </div>
                        <strong>AkbarMart POS</strong>
                    </div>
                    <p class="text-slate-400 mb-3" style="max-width: 380px;">
                        Toko Retail &amp; Point of Sale modern yang mengutamakan kenyamanan, kemudahan transaksi, dan penawaran terbaik untuk seluruh keluarga di Ponorogo.
                    </p>
                    <div class="d-flex gap-2">
                        <a href="https://wa.me/6281234567890" target="_blank" rel="noopener" class="social-button" title="WhatsApp Kasir"><i class="bi bi-whatsapp"></i></a>
                        <a href="#" class="social-button" title="Instagram AkbarMart"><i class="bi bi-instagram"></i></a>
                        <a href="mailto:info@akbarmart.com" class="social-button" title="Email AkbarMart"><i class="bi bi-envelope"></i></a>
                    </div>
                </div>

                <div class="col-6 col-lg-3">
                    <h4 class="text-white fw-bold fs-6 mb-3">Layanan Toko</h4>
                    <ul class="footer-links">
                        <li><a href="#layanan">Scan Barcode Kilat</a></li>
                        <li><a href="#layanan">Diskon Member 10% RFID</a></li>
                        <li><a href="#layanan">Inventaris Real-Time</a></li>
                        <li><a href="#layanan">Struk Thermal 80 mm</a></li>
                    </ul>
                </div>

                <div class="col-6 col-lg-4">
                    <h4 class="text-white fw-bold fs-6 mb-3">Lokasi Toko</h4>
                    <p class="text-slate-400 mb-2">
                        <i class="bi bi-geo-alt text-emerald me-1"></i> Jl. Gajah Mada No. 12, Ponorogo, Jawa Timur 63416
                    </p>
                    <p class="text-slate-400 mb-2">
                        <i class="bi bi-clock text-emerald me-1"></i> Buka Setiap Hari: 07.00 – 22.00 WIB
                    </p>
                    <p class="text-slate-400 mb-0">
                        <i class="bi bi-telephone text-emerald me-1"></i> (0352) 123456
                    </p>
                </div>
            </div>

            <div class="pt-4 border-top border-white border-opacity-10 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="small text-slate-500">
                    &copy; <?= date('Y') ?> AkbarMart. Hak Cipta Dilindungi Undang-Undang.
                </span>
                <span class="small text-slate-500">
                    Retail Point of Sale &amp; Inventory Suite v2.0
                </span>
            </div>
        </div>
    </footer>

    <!-- Bootstrap 5.3.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
