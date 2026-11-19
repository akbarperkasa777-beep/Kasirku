<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_role('pelanggan');

$customerId = (int) $_SESSION['id_pelanggan'];
$userId = (int) $_SESSION['id_user'];
$userName = (string) ($_SESSION['nama'] ?? 'Pelanggan');

// Konfigurasi Duitku Sandbox
$duitkuConfigFile = __DIR__ . '/config/duitku.php';
$hasDuitkuConfig = is_file($duitkuConfigFile);
$duitkuConfig = $hasDuitkuConfig ? require $duitkuConfigFile : [];
$demoSimulatorUrl = (string) ($duitkuConfig['demo_simulator_url'] ?? 'https://sandbox.duitku.com/payment/demo/demosuccesstransaction.aspx');

// Filter Status Transaksi
$filter = trim((string) ($_GET['status'] ?? 'all'));

$sql = "SELECT t.id_transaksi, t.tanggal, t.total_belanja, t.diskon, t.uang_bayar, t.kembalian,
               t.metode_pembayaran, t.status_pembayaran, t.simulasi_pembayaran, t.catatan_pembayaran,
               t.merchant_order_id, t.duitku_reference, t.duitku_va_number, t.duitku_payment_url,
               t.tanggal_pembayaran
        FROM tbl_transaksi t
        WHERE t.id_user = ? AND t.id_pelanggan = ?";
$params = [$userId, $customerId];

if ($filter === 'lunas') {
    $sql .= " AND t.status_pembayaran = 'lunas'";
} elseif ($filter === 'pending') {
    $sql .= " AND t.status_pembayaran = 'menunggu_pembayaran'";
} elseif ($filter === 'cancel') {
    $sql .= " AND t.status_pembayaran IN ('dibatalkan', 'ditolak')";
}

$sql .= " ORDER BY t.tanggal DESC, t.id_transaksi DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Siapkan query untuk mengambil rincian barang per transaksi
$detailStmt = $pdo->prepare(
    "SELECT dt.id_produk, dt.jumlah, COALESCE(dt.harga_satuan, dt.harga, p.harga) AS harga_satuan,
            COALESCE(dt.nama_produk, p.nama_produk) AS nama_produk
     FROM tbl_detail_transaksi dt
     LEFT JOIN tbl_produk p ON p.id_produk = dt.id_produk
     WHERE dt.id_transaksi = ?"
);

$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Riwayat Transaksi';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | AkbarMart Ponorogo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; color: #1e293b; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .trx-card {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #ffffff;
            transition: all 0.2s ease;
        }
        .trx-card:hover {
            box-shadow: 0 10px 20px -5px rgba(0,0,0,0.05);
            border-color: #cbd5e1;
        }
        .info-copy-box {
            background: #f8fafc;
            border: 1.5px dashed #cbd5e1;
            border-radius: 8px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/navbar_pelanggan.php'; ?>

<main class="container py-4" style="max-width: 1040px;">
    <?php if ($flashMessage): ?>
        <div class="alert alert-<?= e($flashMessage['type']) ?> alert-dismissible fade show d-flex align-items-center gap-2 mb-4 shadow-sm" role="alert">
            <i class="bi <?= $flashMessage['type'] === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> fs-5"></i>
            <div><?= e($flashMessage['message']) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Header Section -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 mb-4 border-bottom">
        <div>
            <h4 class="fw-bold text-slate-900 mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-clock-history text-primary"></i> Riwayat Transaksi Saya
            </h4>
            <span class="small text-muted">Daftar riwayat belanja mandiri non-tunai di gerai AkbarMart Ponorogo</span>
        </div>
        <a href="belanja_pelanggan.php" class="btn btn-primary btn-sm rounded-pill px-3 py-2 fw-semibold shadow-sm align-self-start align-self-md-auto">
            <i class="bi bi-cart-plus me-1"></i> Belanja Sekarang
        </a>
    </div>

    <!-- Filter Status Tabs -->
    <div class="d-flex gap-2 mb-4 overflow-x-auto pb-1">
        <a href="riwayat_transaksi.php" class="btn btn-sm rounded-pill px-3 fw-semibold <?= $filter === 'all' ? 'btn-primary' : 'btn-light border' ?>">
            Semua (<?= count($transactions) ?>)
        </a>
        <a href="riwayat_transaksi.php?status=lunas" class="btn btn-sm rounded-pill px-3 fw-semibold <?= $filter === 'lunas' ? 'btn-success text-white' : 'btn-light border' ?>">
            <i class="bi bi-check-circle me-1"></i> Lunas
        </a>
        <a href="riwayat_transaksi.php?status=pending" class="btn btn-sm rounded-pill px-3 fw-semibold <?= $filter === 'pending' ? 'btn-warning text-dark' : 'btn-light border' ?>">
            <i class="bi bi-hourglass-split me-1"></i> Menunggu Bayar
        </a>
        <a href="riwayat_transaksi.php?status=cancel" class="btn btn-sm rounded-pill px-3 fw-semibold <?= $filter === 'cancel' ? 'btn-danger text-white' : 'btn-light border' ?>">
            <i class="bi bi-x-circle me-1"></i> Dibatalkan / Expired
        </a>
    </div>

    <!-- List Transaksi -->
    <?php if (!$transactions): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
            <div class="mb-3">
                <i class="bi bi-receipt-cutoff text-muted" style="font-size: 3.5rem;"></i>
            </div>
            <h5 class="fw-bold text-slate-800 mb-1">Belum Ada Transaksi</h5>
            <p class="text-muted small mb-4">Anda belum memiliki transaksi pada kategori yang dipilih.</p>
            <div>
                <a href="belanja_pelanggan.php" class="btn btn-primary px-4 rounded-pill fw-bold">
                    <i class="bi bi-bag-plus me-1"></i> Mulai Belanja Mandiri
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($transactions as $trx): ?>
                <?php
                $detailStmt->execute([(int) $trx['id_transaksi']]);
                $items = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
                $status = (string) $trx['status_pembayaran'];
                $isPaid = ($status === 'lunas');
                $isPending = ($status === 'menunggu_pembayaran');
                $isExpired = ($status === 'dibatalkan' || $status === 'ditolak');
                $trxCode = 'TRX-' . str_pad((string) $trx['id_transaksi'], 6, '0', STR_PAD_LEFT);
                ?>
                <div class="trx-card p-4">
                    <!-- Top Info Bar -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pb-3 mb-3 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <span class="p-2 rounded-3 bg-light text-primary border">
                                <i class="bi bi-receipt fs-5"></i>
                            </span>
                            <div>
                                <strong class="fs-6 font-monospace text-slate-900"><?= e($trxCode) ?></strong>
                                <span class="small text-muted d-block" style="font-size: 11.5px;">
                                    <i class="bi bi-calendar3 me-1"></i><?= date('d/m/Y H:i', strtotime($trx['tanggal'])) ?> WIB
                                </span>
                            </div>
                        </div>

                        <!-- Status Badge -->
                        <div>
                            <?php if ($isPaid): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill fw-bold">
                                    <i class="bi bi-check-circle-fill me-1"></i> LUNAS / BERHASIL
                                </span>
                            <?php elseif ($isPending): ?>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-2 rounded-pill fw-bold">
                                    <i class="bi bi-hourglass-split me-1"></i> MENUNGGU PEMBAYARAN
                                </span>
                            <?php elseif ($isExpired): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 rounded-pill fw-bold">
                                    <i class="bi bi-x-circle-fill me-1"></i> DIBATALKAN / EXPIRED
                                </span>
                            <?php else: ?>
                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-3 py-2 rounded-pill fw-bold">
                                    <?= strtoupper(e($status)) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Middle Content: Nominal & Detail -->
                    <div class="row g-3 align-items-center">
                        <div class="col-md-7">
                            <div class="small text-muted mb-1">
                                Metode: <strong class="text-slate-800 text-uppercase"><?= e($trx['metode_pembayaran']) ?></strong>
                                <?php if (!empty($trx['merchant_order_id'])): ?>
                                    &bull; <code class="font-monospace text-secondary" style="font-size: 11px;"><?= e($trx['merchant_order_id']) ?></code>
                                <?php endif; ?>
                            </div>

                            <!-- List Item Ringkas -->
                            <div class="small text-slate-700">
                                <?php foreach ($items as $idx => $it): ?>
                                    <span class="d-inline-block me-2">
                                        <?= e($it['nama_produk']) ?> <span class="text-muted">(&times;<?= (int) $it['jumlah'] ?>)</span><?= $idx < count($items) - 1 ? ',' : '' ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>

                            <!-- Info VA atau Reference jika ada -->
                            <?php if (!empty($trx['duitku_va_number'])): ?>
                                <div class="mt-2 small d-flex align-items-center gap-1">
                                    <span class="badge bg-primary text-white" style="font-size: 10px;">VA NUMBER</span>
                                    <code class="fw-bold text-dark font-monospace"><?= e($trx['duitku_va_number']) ?></code>
                                </div>
                            <?php elseif (!empty($trx['duitku_reference'])): ?>
                                <div class="mt-2 small d-flex align-items-center gap-1">
                                    <span class="badge bg-warning text-dark" style="font-size: 10px;">DUITKU REF</span>
                                    <code class="fw-bold text-dark font-monospace"><?= e($trx['duitku_reference']) ?></code>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Total & Aksi -->
                        <div class="col-md-5 text-md-end">
                            <span class="small text-muted d-block" style="font-size: 11px;">TOTAL BELANJA:</span>
                            <div class="fs-4 fw-bold text-primary mb-2">
                                <?= rupiah($trx['total_belanja']) ?>
                            </div>

                            <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                                <?php if ($isPaid): ?>
                                    <a href="cetak_struk.php?id=<?= (int) $trx['id_transaksi'] ?>&print=1" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm fw-bold px-3 rounded-pill">
                                        <i class="bi bi-printer me-1"></i> Cetak Struk
                                    </a>
                                <?php elseif ($isPending): ?>
                                    <!-- Tombol Cek Status Realtime -->
                                    <button type="button" 
                                            class="btn btn-outline-secondary btn-sm rounded-pill px-3 btn-check-status" 
                                            data-order-id="<?= (int) $trx['id_transaksi'] ?>"
                                            data-merchant-id="<?= e($trx['merchant_order_id'] ?? '') ?>">
                                        <i class="bi bi-arrow-repeat me-1"></i> Cek Status
                                    </button>

                                    <!-- Tombol Buka Tagihan & Simulasi -->
                                    <button type="button" 
                                            class="btn btn-primary btn-sm rounded-pill px-3 fw-bold btn-view-invoice"
                                            data-order-id="<?= e($trx['merchant_order_id'] ?: $trx['id_transaksi']) ?>"
                                            data-raw-id="<?= (int) $trx['id_transaksi'] ?>"
                                            data-ref="<?= e($trx['duitku_reference'] ?? '') ?>"
                                            data-va="<?= e($trx['duitku_va_number'] ?? '') ?>"
                                            data-amount="<?= rupiah($trx['total_belanja']) ?>"
                                            data-method="<?= strtoupper(e($trx['metode_pembayaran'])) ?>"
                                            data-portal="<?= e($trx['duitku_payment_url'] ?? '') ?>">
                                        <i class="bi bi-wallet2 me-1"></i> Bayar / Buka Tagihan
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<!-- MODAL INFORMASI TRANSAKSI & SIMULASI DEMO DUITKU SANDBOX -->
<div class="modal fade" id="duitku-info-modal" tabindex="-1" aria-labelledby="duitkuModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-shield-check fs-5"></i>
                    <h5 class="modal-title fs-6 fw-bold mb-0" id="duitkuModalLabel">Tagihan Pembayaran Duitku Sandbox</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body p-4">
                <!-- TAMPILAN 1: MENUNGGU PEMBAYARAN & COUNTDOWN TIMER -->
                <div id="duitku-pending-view">
                    <!-- BANNER COUNTDOWN TIMER -->
                    <div class="d-flex align-items-center justify-content-between p-3 rounded-3 bg-light border border-secondary-subtle mb-3" id="timer-box">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-clock-history fs-3 text-warning"></i>
                            <div>
                                <span class="small text-muted d-block" style="font-size: 11px;">Batas Waktu Pembayaran:</span>
                                <strong class="text-slate-800 small">Selesaikan sebelum waktu habis</strong>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-danger text-white fs-5 fw-bold font-monospace px-3 py-1 shadow-sm" id="duitku-countdown-timer">10:00</span>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <span class="text-muted small d-block mb-1">Total Nominal Belanja:</span>
                            <strong class="text-primary fs-4" id="modal-display-amount">Rp 0</strong>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small d-block mb-1">Metode / Kanal Pembayaran:</span>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 fs-6" id="modal-display-method">DUITKU</span>
                        </div>
                    </div>

                    <!-- INDIKATOR DETEKSI OTOMATIS REAL-TIME -->
                    <div class="d-flex align-items-center justify-content-center gap-2 small text-primary bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-3 py-2 px-3 mb-3" id="polling-indicator">
                        <div class="spinner-grow spinner-grow-sm text-primary" role="status"></div>
                        <span class="fw-semibold" style="font-size: 12px;">Mendeteksi status pembayaran secara otomatis (real-time)...</span>
                    </div>

                    <!-- KOTAK 1: VIRTUAL ACCOUNT NUMBER -->
                    <div class="p-3 bg-primary bg-opacity-10 border border-primary rounded-3 mb-3" id="box-va-number">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong class="text-primary small d-flex align-items-center gap-1">
                                <i class="bi bi-bank2"></i> Nomor Virtual Account (VA Number Resmi Duitku):
                            </strong>
                            <button type="button" class="btn btn-sm btn-primary py-0 px-2 fw-semibold" id="btn-copy-va">
                                <i class="bi bi-clipboard me-1"></i>Salin Nomor VA
                            </button>
                        </div>
                        <div class="info-copy-box">
                            <code class="fs-4 fw-bold text-dark font-monospace user-select-all" id="modal-display-va-number">-</code>
                        </div>
                        <small class="text-dark d-block mt-2" style="font-size: 11.5px;">
                            👉 <strong>Langkah Simulasi VA di Demo Duitku:</strong> Buka halaman demo Duitku, pilih <strong>Payment Method</strong> (sesuai bank VA), tempelkan <strong>Nomor Virtual Account</strong> ini di kolom <em>Va Number</em>, lalu klik <strong>Check</strong>.
                        </small>
                    </div>

                    <!-- KOTAK 2: DUITKU REFERENCE (QRIS) -->
                    <div class="p-3 bg-warning bg-opacity-10 border border-warning rounded-3 mb-3 d-none" id="box-qris-ref">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong class="text-dark small d-flex align-items-center gap-1">
                                <i class="bi bi-qr-code text-warning"></i> Duitku Reference (Khusus Kanal QRIS):
                            </strong>
                            <button type="button" class="btn btn-sm btn-outline-warning text-dark py-0 px-2 fw-semibold" id="btn-copy-ref">
                                <i class="bi bi-clipboard me-1"></i>Salin Referensi
                            </button>
                        </div>
                        <div class="info-copy-box">
                            <code class="fs-5 fw-bold text-dark font-monospace user-select-all" id="modal-display-ref">-</code>
                        </div>
                        <small class="text-dark d-block mt-2" style="font-size: 11.5px;">
                            👉 <strong>Langkah Simulasi QRIS:</strong> Buka halaman demo Duitku, tempelkan <strong>Duitku Reference</strong> ini di kolom <em>Reference</em>, lalu klik <strong>Check</strong>.
                        </small>
                    </div>

                    <!-- KOTAK 3: ORDER ID -->
                    <div class="p-3 bg-light border border-secondary-subtle rounded-3 mb-4" id="box-order-id">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong class="text-dark small">Merchant Order ID (Internal AkbarMart):</strong>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fw-semibold" id="btn-copy-order">
                                <i class="bi bi-clipboard me-1"></i>Salin Order ID
                            </button>
                        </div>
                        <div class="info-copy-box">
                            <code class="fs-6 fw-bold text-secondary font-monospace user-select-all" id="modal-display-order-id">-</code>
                        </div>
                    </div>

                    <!-- TOMBOL AKSI SIMULASI -->
                    <div class="d-grid gap-2">
                        <a href="<?= e($demoSimulatorUrl) ?>" target="_blank" rel="noopener" class="btn btn-success btn-lg fw-bold py-3 shadow-sm" id="btn-open-demo">
                            <i class="bi bi-box-arrow-up-right me-2"></i> Buka Halaman Simulasi Duitku (demosuccesstransaction.aspx)
                        </a>
                        <a href="#" target="_blank" rel="noopener" class="btn btn-outline-primary fw-semibold py-2 d-none" id="btn-open-portal">
                            <i class="bi bi-credit-card me-2"></i> Buka Portal Pembayaran Duitku (paymentUrl)
                        </a>
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Tutup Jendela Tagihan</button>
                    </div>
                </div>

                <!-- TAMPILAN 2: LAYAR PEMBAYARAN BERHASIL -->
                <div id="duitku-success-view" class="d-none text-center py-4">
                    <div class="mb-3">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4.5rem;"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-1">Pembayaran Berhasil &amp; Lunas!</h4>
                    <p class="text-muted small mb-4">
                        Terima kasih! Pembayaran Anda telah terverifikasi resmi oleh Payment Gateway Duitku. Stok barang telah terpotong secara aman &amp; atomik.
                    </p>

                    <div class="card bg-light border border-success-subtle rounded-3 p-3 mb-4 text-start mx-auto shadow-sm" style="max-width: 460px;">
                        <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle">
                            <span class="text-muted small">No. Transaksi:</span>
                            <strong class="text-dark small font-monospace" id="success-display-trx">-</strong>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle">
                            <span class="text-muted small">Merchant Order ID:</span>
                            <strong class="text-dark small font-monospace" id="success-display-order-id">-</strong>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle">
                            <span class="text-muted small">Total Pembayaran:</span>
                            <strong class="text-success fs-5" id="success-display-amount">Rp 0</strong>
                        </div>
                        <div class="d-flex justify-content-between py-1">
                            <span class="text-muted small">Waktu Pelunasan:</span>
                            <span class="text-dark small" id="success-display-time">-</span>
                        </div>
                    </div>

                    <div class="d-flex gap-2 justify-content-center">
                        <a href="#" target="_blank" rel="noopener" class="btn btn-outline-primary px-4 fw-bold" id="success-btn-receipt">
                            <i class="bi bi-receipt me-1"></i> Cetak Struk Pembelian
                        </a>
                        <button type="button" class="btn btn-primary px-4 fw-bold" onclick="location.reload();">
                            <i class="bi bi-arrow-repeat me-1"></i> Selesai &amp; Muat Ulang
                        </button>
                    </div>
                </div>

                <!-- TAMPILAN 3: LAYAR KEDALUWARSA -->
                <div id="duitku-expired-view" class="d-none text-center py-4">
                    <div class="mb-3">
                        <i class="bi bi-hourglass-bottom text-danger" style="font-size: 4rem;"></i>
                    </div>
                    <h4 class="fw-bold text-danger mb-1">Batas Waktu Pembayaran Habis</h4>
                    <p class="text-muted small mb-4">
                        Waktu 10:00 menit untuk menyelesaikan pembayaran telah berakhir. Pesanan ini telah otomatis dibatalkan.
                    </p>
                    <button type="button" class="btn btn-primary px-4 fw-semibold" onclick="location.reload();">
                        <i class="bi bi-arrow-repeat me-1"></i> Muat Ulang Halaman
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var countdownInterval = null;
    var statusPollingInterval = null;
    var activeOrderId = null;
    var activeMerchantOrderId = null;
    var remainingSeconds = 600;

    function formatMMSS(seconds) {
        var mins = Math.floor(seconds / 60);
        var secs = seconds % 60;
        return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }

    function startCountdown(durationSeconds) {
        stopCountdown();
        remainingSeconds = durationSeconds || 600;
        var timerEl = document.getElementById('duitku-countdown-timer');
        if (timerEl) timerEl.textContent = formatMMSS(remainingSeconds);

        countdownInterval = setInterval(function () {
            remainingSeconds--;
            if (timerEl) timerEl.textContent = formatMMSS(Math.max(0, remainingSeconds));
            if (remainingSeconds <= 0) {
                stopCountdown();
                handlePaymentExpired();
            }
        }, 1000);
    }

    function stopCountdown() {
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
    }

    function startStatusPolling(orderId, merchantOrderId) {
        stopStatusPolling();
        activeOrderId = orderId;
        activeMerchantOrderId = merchantOrderId;

        checkPaymentStatus();
        statusPollingInterval = setInterval(checkPaymentStatus, 3000);
    }

    function stopStatusPolling() {
        if (statusPollingInterval) {
            clearInterval(statusPollingInterval);
            statusPollingInterval = null;
        }
    }

    function checkPaymentStatus() {
        if (!activeOrderId && !activeMerchantOrderId) return;

        var url = 'cek_status_transaksi.php?order_id=' + encodeURIComponent(activeOrderId || '')
            + '&merchant_order_id=' + encodeURIComponent(activeMerchantOrderId || '')
            + '&_t=' + Date.now();

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data || !data.success) return;

            if (data.is_paid || data.status === 'lunas') {
                handlePaymentSuccess(data);
            } else if (data.status === 'dibatalkan' || data.is_expired) {
                handlePaymentExpired();
            }
        })
        .catch(function (err) {
            console.warn('Polling status:', err);
        });
    }

    function handlePaymentSuccess(data) {
        stopStatusPolling();
        stopCountdown();

        var pendingView = document.getElementById('duitku-pending-view');
        var successView = document.getElementById('duitku-success-view');
        var expiredView = document.getElementById('duitku-expired-view');

        if (pendingView) pendingView.classList.add('d-none');
        if (expiredView) expiredView.classList.add('d-none');
        if (successView) {
            successView.classList.remove('d-none');
            document.getElementById('success-display-trx').textContent = data.trx_code || ('TRX-' + String(data.order_id).padStart(6, '0'));
            document.getElementById('success-display-order-id').textContent = data.merchant_order_id || '-';
            document.getElementById('success-display-amount').textContent = data.total_belanja_formatted || 'Rp 0';
            document.getElementById('success-display-time').textContent = data.tanggal_pembayaran || '-';

            var receiptBtn = document.getElementById('success-btn-receipt');
            if (receiptBtn) {
                receiptBtn.href = data.redirect_url || data.receipt_url || ('cetak_struk.php?id=' + data.order_id + '&print=1');
            }
        }
    }

    function handlePaymentExpired() {
        stopStatusPolling();
        stopCountdown();

        var pendingView = document.getElementById('duitku-pending-view');
        var successView = document.getElementById('duitku-success-view');
        var expiredView = document.getElementById('duitku-expired-view');

        if (pendingView) pendingView.classList.add('d-none');
        if (successView) successView.classList.add('d-none');
        if (expiredView) expiredView.classList.remove('d-none');

        if (activeOrderId) {
            fetch('cek_status_transaksi.php?action=expire&order_id=' + encodeURIComponent(activeOrderId), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).catch(function () {});
        }
    }

    function copyText(elementId, buttonEl, label) {
        var text = document.getElementById(elementId).textContent.trim();
        if (!text || text === '-') return;
        navigator.clipboard.writeText(text).then(function () {
            var origHtml = buttonEl.innerHTML;
            buttonEl.innerHTML = '<i class="bi bi-check-lg me-1 text-success"></i>Tersalin!';
            setTimeout(function () { buttonEl.innerHTML = origHtml; }, 2000);
        }).catch(function () {
            window.prompt('Salin ' + label + ':', text);
        });
    }

    var btnCopyVa = document.getElementById('btn-copy-va');
    if (btnCopyVa) btnCopyVa.addEventListener('click', function () { copyText('modal-display-va-number', btnCopyVa, 'Nomor VA'); });

    var btnCopyRef = document.getElementById('btn-copy-ref');
    if (btnCopyRef) btnCopyRef.addEventListener('click', function () { copyText('modal-display-ref', btnCopyRef, 'Referensi'); });

    var btnCopyOrder = document.getElementById('btn-copy-order');
    if (btnCopyOrder) btnCopyOrder.addEventListener('click', function () { copyText('modal-display-order-id', btnCopyOrder, 'Order ID'); });

    // Handler Tombol Buka Tagihan
    document.querySelectorAll('.btn-view-invoice').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var orderId = btn.dataset.orderId;
            var rawId = btn.dataset.rawId;
            var ref = btn.dataset.ref;
            var va = btn.dataset.va;
            var amount = btn.dataset.amount;
            var method = btn.dataset.method;
            var portal = btn.dataset.portal;

            document.getElementById('modal-display-order-id').textContent = orderId || '-';
            document.getElementById('modal-display-ref').textContent = ref || '-';
            document.getElementById('modal-display-amount').textContent = amount || '-';
            document.getElementById('modal-display-method').textContent = method || 'DUITKU';

            var vaEl = document.getElementById('modal-display-va-number');
            if (vaEl) vaEl.textContent = va || '-';

            var vaBox = document.getElementById('box-va-number');
            var qrisBox = document.getElementById('box-qris-ref');

            if (va && va.trim() !== '' && va !== '-') {
                if (vaBox) vaBox.classList.remove('d-none');
                if (qrisBox) qrisBox.classList.add('d-none');
            } else if (ref && ref.trim() !== '') {
                if (qrisBox) qrisBox.classList.remove('d-none');
                if (vaBox) vaBox.classList.add('d-none');
            } else {
                if (vaBox) vaBox.classList.add('d-none');
                if (qrisBox) qrisBox.classList.remove('d-none');
            }

            var portalBtn = document.getElementById('btn-open-portal');
            if (portalBtn) {
                if (portal && portal.trim() !== '') {
                    portalBtn.href = portal;
                    portalBtn.classList.remove('d-none');
                } else {
                    portalBtn.classList.add('d-none');
                }
            }

            var pendingView = document.getElementById('duitku-pending-view');
            var successView = document.getElementById('duitku-success-view');
            var expiredView = document.getElementById('duitku-expired-view');

            if (pendingView) pendingView.classList.remove('d-none');
            if (successView) successView.classList.add('d-none');
            if (expiredView) expiredView.classList.add('d-none');

            var modalEl = document.getElementById('duitku-info-modal');
            if (modalEl) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }

            startCountdown(600);
            startStatusPolling(rawId, orderId);
        });
    });

    // Handler Tombol Cek Status Realtime
    document.querySelectorAll('.btn-check-status').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var orderId = btn.dataset.orderId;
            var merchantOrderId = btn.dataset.merchantId;
            var originalHtml = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Mengecek...';

            var checkUrl = 'cek_status_transaksi.php?order_id=' + encodeURIComponent(orderId) 
                + '&merchant_order_id=' + encodeURIComponent(merchantOrderId) 
                + '&_t=' + Date.now();

            fetch(checkUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    alert(data.message || 'Gagal mengecek status transaksi Duitku.');
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                    return;
                }

                if (data.is_paid || data.status === 'lunas') {
                    btn.className = 'btn btn-sm btn-success fw-bold rounded-pill px-3';
                    btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> LUNAS!';
                    setTimeout(function () { location.reload(); }, 1200);
                } else if (data.status === 'dibatalkan' || data.is_expired) {
                    btn.className = 'btn btn-sm btn-danger fw-bold rounded-pill px-3';
                    btn.innerHTML = '<i class="bi bi-x-circle-fill me-1"></i> KEDALUWARSA';
                    setTimeout(function () { location.reload(); }, 1200);
                } else {
                    alert('Status transaksi saat ini: ' + data.status.toUpperCase() + ' (Menunggu pembayaran).');
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                }
            })
            .catch(function (err) {
                alert('Koneksi pengecekan Duitku gagal: ' + err.message);
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
        });
    });

    var modalEl = document.getElementById('duitku-info-modal');
    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function () {
            stopStatusPolling();
            stopCountdown();
        });
    }
});
</script>
</body>
</html>
