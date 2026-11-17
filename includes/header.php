<?php
$pageTitle = $pageTitle ?? 'Dashboard';
$flashMessage = $_SESSION['flash'] ?? null;
$roleLabel = $_SESSION['role'] === 'admin' ? 'Admin' : 'Kasir';
$roleClass = $_SESSION['role'] === 'admin' ? 'role-badge-admin' : 'role-badge-kasir';
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | AkbarMart POS &amp; Inventory</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/akbarmart-icon.svg?v=2">
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5.3.3 & Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Custom Modern POS Styling -->
    <link href="assets/css/style.css?v=3" rel="stylesheet">
</head>
<body>
<div class="app-shell">
    <!-- Sidebar Navigation -->
    <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="appSidebar" aria-labelledby="sidebarTitle">
        <div class="sidebar-brand">
            <div class="brand-mark">
                <i class="bi bi-shop"></i>
            </div>
            <div class="brand-info">
                <strong id="sidebarTitle">AkbarMart</strong>
                <small>Retail POS <span class="badge-version">v2.0</span></small>
            </div>
            <button type="button" class="btn-close d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Tutup menu"></button>
        </div>

        <div class="sidebar-label">MENU UTAMA</div>
        <nav class="nav flex-column sidebar-nav mb-4">
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a class="nav-link <?= active_page('dashboard.php') ?>" href="dashboard.php">
                    <i class="bi bi-grid-1x2-fill"></i>
                    <span>Dashboard</span>
                </a>
            <?php endif; ?>
            <a class="nav-link <?= active_page('pos.php') ?>" href="pos.php">
                <i class="bi bi-bag-check-fill"></i>
                <span>POS / Kasir</span>
            </a>
            <?php if ($_SESSION['role'] === 'admin'): ?>
                <a class="nav-link <?= active_page('produk.php') ?>" href="produk.php">
                    <i class="bi bi-box-seam-fill"></i>
                    <span>Produk</span>
                </a>
                <a class="nav-link <?= active_page('inventory.php') ?>" href="inventory.php">
                    <i class="bi bi-clipboard2-data-fill"></i>
                    <span>Inventory / Stok</span>
                </a>
                <a class="nav-link <?= active_page('kategori.php') ?>" href="kategori.php">
                    <i class="bi bi-tags-fill"></i>
                    <span>Kategori</span>
                </a>
                <a class="nav-link <?= active_page('laporan.php') ?>" href="laporan.php">
                    <i class="bi bi-bar-chart-line-fill"></i>
                    <span>Laporan Penjualan</span>
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-bottom">
            <div class="help-card">
                <span class="help-icon"><i class="bi bi-shield-check"></i></span>
                <div>
                    <strong>Sistem AkbarMart</strong>
                    <small>Database terhubung normal &amp; siap transaksi.</small>
                </div>
            </div>
            <div class="sidebar-user">
                <div class="avatar">
                    <?= e(mb_strtoupper(mb_substr((string) $_SESSION['nama'], 0, 1))) ?>
                    <span class="avatar-status-dot"></span>
                </div>
                <div>
                    <strong><?= e($_SESSION['nama']) ?></strong>
                    <small><span class="role-badge <?= e($roleClass) ?>"><?= e($roleLabel) ?></span></small>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content Panel -->
    <main class="main-panel">
        <!-- Sticky Modern Topbar -->
        <header class="topbar">
            <div class="d-flex align-items-center">
                <button class="btn btn-light btn-icon d-lg-none me-3" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Buka menu navigasi">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <div class="topbar-title">
                    <span class="eyebrow">AKBARMART POS SYSTEM</span>
                    <h1><?= e($pageTitle) ?></h1>
                </div>
            </div>
            <div class="topbar-actions">
                <span class="today-badge d-none d-md-flex">
                    <i class="bi bi-clock"></i>
                    <span id="topbar-live-clock"><?= date('H:i') ?> WIB</span>
                </span>
                <span class="today-badge">
                    <i class="bi bi-calendar3"></i>
                    <span><?= date('d M Y') ?></span>
                </span>
                <div class="account-identity">
                    <span class="account-name"><?= e($_SESSION['nama']) ?></span>
                    <span class="role-badge <?= e($roleClass) ?>"><?= e($roleLabel) ?></span>
                </div>
                <form class="logout-form" method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <button class="btn btn-outline-secondary btn-sm" type="submit">
                        <i class="bi bi-box-arrow-right"></i>
                        <span>Logout</span>
                    </button>
                </form>
                <?php if (active_page('pos.php') !== 'active'): ?>
                    <a class="btn btn-primary btn-sm" href="pos.php">
                        <i class="bi bi-plus-lg"></i>
                        <span>Transaksi Baru</span>
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- Page Main View Container -->
        <div class="page-content">
            <?php if ($flashMessage): ?>
                <?php
                $alertIcon = match ($flashMessage['type']) {
                    'success' => 'bi-check-circle-fill',
                    'danger' => 'bi-exclamation-triangle-fill',
                    'warning' => 'bi-exclamation-circle-fill',
                    default => 'bi-info-circle-fill'
                };
                ?>
                <div class="alert alert-<?= e($flashMessage['type']) ?> alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
                    <i class="bi <?= $alertIcon ?> fs-5"></i>
                    <div><?= e($flashMessage['message']) ?></div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Tutup pesan"></button>
                </div>
            <?php endif; ?>
