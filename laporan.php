<?php
require_once __DIR__ . '/check_session.php';
require_role('admin');

$today = date('Y-m-d');
$from = (string) ($_GET['dari'] ?? date('Y-m-01'));
$to = (string) ($_GET['sampai'] ?? $today);

$validDate = static function (string $date): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
};

if (!$validDate($from) || !$validDate($to) || $from > $to) {
    flash('warning', 'Rentang tanggal tidak valid. Menampilkan laporan bulan ini.');
    redirect('laporan.php');
}

$stmt = $pdo->prepare(
    'SELECT t.id_transaksi, t.tanggal, t.total_belanja, t.diskon, t.uang_bayar, t.kembalian,
            t.nama_kasir, t.status_pembayaran, t.metode_pembayaran,
            t.simulasi_pembayaran, p.nama_pelanggan
     FROM tbl_transaksi t 
     LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
     WHERE t.tanggal >= ? AND t.tanggal < DATE_ADD(?, INTERVAL 1 DAY)
     ORDER BY t.tanggal DESC, t.id_transaksi DESC'
);
$stmt->execute([$from, $to]);
$transactions = $stmt->fetchAll();

$paidTransactions = array_filter(
    $transactions,
    static fn(array $row): bool => $row['status_pembayaran'] === 'lunas' && (int) $row['simulasi_pembayaran'] === 0
);
$totalRevenue = array_sum(array_map(static fn(array $row): float => (float) $row['total_belanja'], $paidTransactions));
$avgTransaction = count($paidTransactions) > 0 ? $totalRevenue / count($paidTransactions) : 0;

$pageTitle = 'Laporan Penjualan';
require __DIR__ . '/includes/header.php';
?>

<!-- 3 SaaS Fintech Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="stat-card card-emerald">
            <div class="stat-content">
                <span class="stat-label">Total Omzet Penjualan</span>
                <div class="stat-value text-success"><?= rupiah($totalRevenue) ?></div>
                <div class="stat-note"><i class="bi bi-calendar-range text-success"></i> Periode: <?= e(date('d M', strtotime($from))) ?> – <?= e(date('d M Y', strtotime($to))) ?></div>
            </div>
            <div class="stat-icon icon-emerald">
                <i class="bi bi-cash-coin"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card card-indigo">
            <div class="stat-content">
                <span class="stat-label">Jumlah Transaksi Sukses</span>
                <div class="stat-value text-primary"><?= count($paidTransactions) ?> <span class="fs-6 fw-normal text-muted">Struk</span></div>
                <div class="stat-note"><i class="bi bi-check-all text-primary"></i> Transaksi berhasil dibukukan</div>
            </div>
            <div class="stat-icon icon-indigo">
                <i class="bi bi-receipt-cutoff"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card card-sky">
            <div class="stat-content">
                <span class="stat-label">Rata-Rata per Transaksi</span>
                <div class="stat-value text-info"><?= rupiah($avgTransaction) ?></div>
                <div class="stat-note"><i class="bi bi-graph-up-arrow text-info"></i> Rata-rata belanja (AOV)</div>
            </div>
            <div class="stat-icon icon-sky">
                <i class="bi bi-bar-chart-fill"></i>
            </div>
        </div>
    </div>
</div>

<!-- Report Panel & Filter -->
<div class="card-panel">
    <div class="panel-heading">
        <div>
            <h2 class="section-title">
                <i class="bi bi-journal-text text-primary"></i>
                Riwayat &amp; Rekapitulasi Transaksi
            </h2>
            <div class="section-subtitle">Daftar transaksi penjualan lengkap dengan status dan cetak struk</div>
        </div>

        <!-- Quick Period Presets -->
        <div class="d-flex gap-2">
            <a href="laporan.php?dari=<?= date('Y-m-d') ?>&sampai=<?= date('Y-m-d') ?>" 
               class="btn btn-sm <?= ($from === $today && $to === $today) ? 'btn-primary' : 'btn-light' ?>">
                Hari Ini
            </a>
            <a href="laporan.php?dari=<?= date('Y-m-01') ?>&sampai=<?= $today ?>" 
               class="btn btn-sm <?= ($from === date('Y-m-01') && $to === $today) ? 'btn-primary' : 'btn-light' ?>">
                Bulan Ini
            </a>
        </div>
    </div>

    <!-- Filter Form Bar -->
    <div class="panel-body border-bottom py-3" style="background: #fafbfc;">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-sm-4 col-md-3">
                <label class="form-label mb-1"><i class="bi bi-calendar3 me-1"></i> Dari Tanggal</label>
                <input class="form-control" type="date" name="dari" value="<?= e($from) ?>" required>
            </div>
            <div class="col-12 col-sm-4 col-md-3">
                <label class="form-label mb-1"><i class="bi bi-calendar3-fill me-1"></i> Sampai Tanggal</label>
                <input class="form-control" type="date" name="sampai" value="<?= e($to) ?>" required>
            </div>
            <div class="col-12 col-sm-4 col-md-2">
                <button class="btn btn-primary w-100" type="submit">
                    <i class="bi bi-funnel-fill me-1"></i> Filter Laporan
                </button>
            </div>
        </form>
    </div>

    <!-- Transactions Table -->
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>No. Transaksi</th>
                    <th>Waktu Pembelian</th>
                    <th>Pelanggan</th>
                    <th>Nama Kasir</th>
                    <th>Total Belanja</th>
                    <th>Status</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$transactions): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <i class="bi bi-calendar-x"></i>
                            <div class="empty-state-text">
                                Tidak ada catatan transaksi pada rentang tanggal yang dipilih.
                            </div>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($transactions as $transaction): ?>
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
                        <span class="badge-soft badge-indigo">
                            <i class="bi bi-person-badge me-1"></i> <?= e($transaction['nama_kasir']) ?>
                        </span>
                    </td>
                    <td>
                        <strong class="text-success fs-6"><?= rupiah($transaction['total_belanja']) ?></strong>
                    </td>
                    <td>
                        <?php if ((int) $transaction['simulasi_pembayaran'] === 1): ?>
                            <?php if ($transaction['status_pembayaran'] === 'lunas'): ?>
                                <span class="badge text-bg-warning"><i class="bi bi-play-circle me-1"></i>Berhasil (Simulasi)</span>
                            <?php elseif ($transaction['status_pembayaran'] === 'menunggu_verifikasi'): ?>
                                <span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1"></i>Menunggu verifikasi (Simulasi)</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary"><i class="bi bi-x-circle me-1"></i>Dibatalkan (Simulasi)</span>
                            <?php endif; ?>
                        <?php elseif ($transaction['status_pembayaran'] === 'lunas'): ?>
                            <span class="badge-soft badge-ok"><i class="bi bi-check-circle-fill me-1"></i>Lunas</span>
                        <?php elseif ($transaction['status_pembayaran'] === 'menunggu_verifikasi'): ?>
                            <span class="badge-soft badge-amber"><i class="bi bi-hourglass-split me-1"></i>Menunggu verifikasi</span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger"><i class="bi bi-x-circle-fill me-1"></i>Dibatalkan</span>
                        <?php endif; ?>
                        <small class="d-block text-muted"><?= e(strtoupper($transaction['metode_pembayaran'])) ?></small>
                    </td>
                    <td class="text-end">
                        <?php if ($transaction['status_pembayaran'] === 'lunas'): ?>
                            <a class="btn btn-sm btn-light"
                               href="cetak_struk.php?id=<?= (int) $transaction['id_transaksi'] ?>"
                               target="_blank" rel="noopener" title="Cetak Salinan Struk">
                                <i class="bi bi-printer text-primary me-1"></i> Struk
                            </a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-outline-primary" href="admin_pembayaran.php">
                                Tinjau pembayaran
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
