<?php
require_once __DIR__ . '/check_session.php';
require_role('admin');

$todaySales = (int) $pdo->query("SELECT COALESCE(SUM(total_belanja), 0) FROM tbl_transaksi WHERE DATE(tanggal) = CURDATE()")->fetchColumn();
$allSales = (int) $pdo->query("SELECT COALESCE(SUM(total_belanja), 0) FROM tbl_transaksi")->fetchColumn();
$todayOrders = (int) $pdo->query("SELECT COUNT(*) FROM tbl_transaksi WHERE DATE(tanggal) = CURDATE()")->fetchColumn();
$productCount = (int) $pdo->query("SELECT COUNT(*) FROM tbl_produk")->fetchColumn();
$lowStock = $pdo->query("SELECT id_produk, nama_produk, stok FROM tbl_produk WHERE stok < 5 ORDER BY stok ASC, nama_produk ASC")->fetchAll();
$recentTransactions = $pdo->query(
    "SELECT t.id_transaksi, t.tanggal, t.total_belanja, p.nama_pelanggan
     FROM tbl_transaksi t LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
     ORDER BY t.tanggal DESC, t.id_transaksi DESC LIMIT 8"
)->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>

<!-- Welcome Banner -->
<div class="card-panel mb-4 p-4" style="background: linear-gradient(135deg, #ffffff 0%, #f4f6fc 100%);">
    <div class="row align-items-center">
        <div class="col-lg-8">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge-soft badge-indigo"><i class="bi bi-stars"></i> Point of Sale AkbarMart</span>
                <span class="text-muted small">· Status Toko: <strong class="text-success">Buka (Normal)</strong></span>
            </div>
            <h2 class="h4 fw-bold mb-1 text-slate-900">Halo, <?= e($_SESSION['nama']) ?>! 👋</h2>
            <p class="text-muted mb-0 small">Pantau performa penjualan harian, pergerakan stok barang, dan pencetakan struk transaksi secara real-time.</p>
        </div>
        <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
            <div class="d-flex gap-2 justify-content-lg-end">
                <a class="btn btn-primary" href="pos.php">
                    <i class="bi bi-cart-plus me-1"></i> Buka Kasir (POS)
                </a>
                <a class="btn btn-light" href="laporan.php">
                    <i class="bi bi-bar-chart me-1"></i> Laporan
                </a>
            </div>
        </div>
    </div>
</div>

<?php if ($lowStock): ?>
    <div class="alert-stock mb-4">
        <div class="alert-stock-icon">
            <i class="bi bi-exclamation-triangle-fill"></i>
        </div>
        <div class="flex-grow-1">
            <strong>Peringatan Stok Menipis!</strong>
            <span>
                <?php foreach (array_slice($lowStock, 0, 4) as $index => $product): ?>
                    <?= $index > 0 ? ' · ' : '' ?><strong><?= e($product['nama_produk']) ?></strong> (tersisa <span class="badge-soft badge-amber py-0 px-2"><?= (int) $product['stok'] ?> pcs</span>)
                <?php endforeach; ?>
                <?php if (count($lowStock) > 4): ?>
                    · dan <?= count($lowStock) - 4 ?> produk lainnya
                <?php endif; ?>.
            </span>
        </div>
        <a class="btn btn-sm btn-outline-secondary text-nowrap" href="inventory.php">
            <i class="bi bi-box-arrow-up-right me-1"></i> Periksa Stok
        </a>
    </div>
<?php endif; ?>

<!-- 4 Modern SaaS Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="stat-card card-indigo">
            <div class="stat-content">
                <span class="stat-label">Penjualan Hari Ini</span>
                <div class="stat-value text-primary"><?= rupiah($todaySales) ?></div>
                <div class="stat-note"><i class="bi bi-calendar-check text-primary"></i> Total omzet <?= date('d M Y') ?></div>
            </div>
            <div class="stat-icon icon-indigo">
                <i class="bi bi-cash-stack"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="stat-card card-emerald">
            <div class="stat-content">
                <span class="stat-label">Total Penjualan</span>
                <div class="stat-value text-success"><?= rupiah($allSales) ?></div>
                <div class="stat-note"><i class="bi bi-graph-up-arrow text-success"></i> Akumulasi seluruh kasir</div>
            </div>
            <div class="stat-icon icon-emerald">
                <i class="bi bi-wallet2"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="stat-card card-sky">
            <div class="stat-content">
                <span class="stat-label">Pesanan Hari Ini</span>
                <div class="stat-value"><?= number_format($todayOrders, 0, ',', '.') ?> <span class="fs-6 fw-normal text-muted">Trx</span></div>
                <div class="stat-note"><i class="bi bi-check-circle text-info"></i> Struk transaksi berhasil</div>
            </div>
            <div class="stat-icon icon-sky">
                <i class="bi bi-receipt"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="stat-card card-purple">
            <div class="stat-content">
                <span class="stat-label">Katalog Produk</span>
                <div class="stat-value"><?= number_format($productCount, 0, ',', '.') ?> <span class="fs-6 fw-normal text-muted">SKU</span></div>
                <div class="stat-note"><i class="bi bi-tags text-purple"></i> Produk aktif terdaftar</div>
            </div>
            <div class="stat-icon icon-purple">
                <i class="bi bi-boxes"></i>
            </div>
        </div>
    </div>
</div>

<!-- Recent Transactions Panel -->
<div class="card-panel">
    <div class="panel-heading">
        <div>
            <h2 class="section-title">
                <i class="bi bi-clock-history text-primary"></i>
                Transaksi Penjualan Terkini
            </h2>
            <div class="section-subtitle">Daftar transaksi kasir terbaru yang diproses oleh sistem</div>
        </div>
        <a class="btn btn-sm btn-outline-secondary" href="laporan.php">
            Lihat Semua Laporan <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>No. Transaksi</th>
                    <th>Waktu Pembelian</th>
                    <th>Pelanggan</th>
                    <th>Total Belanja</th>
                    <th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$recentTransactions): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <i class="bi bi-receipt"></i>
                            <div class="empty-state-text">
                                Belum ada transaksi penjualan yang tercatat. Mulai layani pelanggan melalui menu POS Kasir.
                            </div>
                            <a href="pos.php" class="btn btn-primary btn-sm mt-3">
                                <i class="bi bi-plus-circle me-1"></i> Mulai Transaksi Pertama
                            </a>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($recentTransactions as $transaction): ?>
                <tr>
                    <td>
                        <span class="trx-code">TRX-<?= str_pad((string) $transaction['id_transaksi'], 6, '0', STR_PAD_LEFT) ?></span>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-calendar-event text-muted"></i>
                            <span><?= e(date('d/m/Y', strtotime($transaction['tanggal']))) ?></span>
                            <span class="badge-soft badge-indigo py-0 px-2"><?= e(date('H:i', strtotime($transaction['tanggal']))) ?></span>
                        </div>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="avatar" style="width: 28px; height: 28px; font-size: 11px; background: #eef2ff; color: #4f46e5; border-radius: 6px;">
                                <i class="bi bi-person"></i>
                            </div>
                            <span class="fw-semibold text-slate-800"><?= e($transaction['nama_pelanggan'] ?? 'Pelanggan Umum') ?></span>
                        </div>
                    </td>
                    <td>
                        <strong class="text-success fs-6"><?= rupiah($transaction['total_belanja']) ?></strong>
                    </td>
                    <td>
                        <span class="badge-soft badge-ok">
                            <i class="bi bi-check-circle-fill me-1"></i> Lunas
                        </span>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-light" href="cetak_struk.php?id=<?= (int) $transaction['id_transaksi'] ?>" target="_blank" rel="noopener" title="Cetak Ulang Struk">
                            <i class="bi bi-printer text-primary me-1"></i> Struk
                        </a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
