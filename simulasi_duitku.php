<?php
declare(strict_types=1);

/**
 * Simulator & Panduan Pengujian Duitku Sandbox untuk AkbarMart
 * Alamat: Jl. Gajah Mada No. 12, Ponorogo
 *
 * Mendukung 2 Mode Pengujian:
 * 1. Pengujian Resmi Demo Duitku (https://sandbox.duitku.com/payment/demo/demosuccesstransaction.aspx)
 *    - Untuk Virtual Account: Menggunakan Nomor Virtual Account Resmi (vaNumber) dari Duitku
 *    - Untuk QRIS: Menggunakan Duitku Reference
 * 2. Pengujian Webhook Langsung (Simulasi cURL internal ke callback.php dengan signature MD5 valid)
 */

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/config/koneksi.php';

$duitkuConfig = require __DIR__ . '/config/duitku.php';
$orderId = valid_int($_GET['order'] ?? null, 1);
$merchantOrderId = trim((string) ($_GET['merchant_order_id'] ?? ''));
$amount = (int) ($_GET['amount'] ?? 0);

if ($orderId === null) {
    exit('Parameter pesanan tidak valid.');
}

// Ambil data transaksi dari database
$stmt = $pdo->prepare('SELECT * FROM tbl_transaksi WHERE id_transaksi = ? LIMIT 1');
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    exit('Transaksi #' . $orderId . ' tidak ditemukan.');
}

// Ambil rincian produk
$detailStmt = $pdo->prepare(
    'SELECT d.jumlah, d.harga_satuan, p.nama_produk
     FROM tbl_detail_transaksi d
     JOIN tbl_produk p ON p.id_produk = d.id_produk
     WHERE d.id_transaksi = ?'
);
$detailStmt->execute([$orderId]);
$items = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

if ($merchantOrderId === '') {
    $merchantOrderId = (string) ($order['merchant_order_id'] ?? ('AKM-' . $orderId . '-' . time()));
}
if ($amount === 0) {
    $amount = (int) round((float) $order['total_belanja']);
}

$merchantCode = (string) ($duitkuConfig['merchant_code'] ?? 'DS38180');
$apiKey = (string) ($duitkuConfig['api_key'] ?? '');
$vaNumber = (string) ($order['duitku_va_number'] ?? '');
$reference = (string) ($order['duitku_reference'] ?? '');
$paymentUrl = (string) ($order['duitku_payment_url'] ?? '');

$actionResult = null;

// Proses simulasi webhook callback langsung (Internal XAMPP)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    $resultCode = ($action === 'success') ? '00' : '01'; // '00' = Sukses, '01' = Dibatalkan
    $simRef = $reference !== '' ? $reference : ('SIM-' . strtoupper(bin2hex(random_bytes(6))));
    $paymentCode = $_POST['channel'] ?? 'BR';

    // Rumus Signature Callback Resmi: MD5(merchantCode + amount + merchantOrderId + apiKey)
    $signature = md5($merchantCode . $amount . $merchantOrderId . $apiKey);

    $callbackPayload = [
        'merchantCode' => $merchantCode,
        'amount' => (string) $amount,
        'merchantOrderId' => $merchantOrderId,
        'productDetail' => 'Belanja AkbarMart #' . $orderId,
        'additionalParam' => (string) $orderId,
        'paymentCode' => $paymentCode,
        'resultCode' => $resultCode,
        'merchantUserId' => 'user-' . $order['id_user'],
        'reference' => $simRef,
        'signature' => $signature,
    ];

    // Eksekusi pemanggilan ke callback.php
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $callbackUrl = $protocol . '://' . $host . '/kasirku/callback.php';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $callbackUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($callbackPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $callbackResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && trim((string) $callbackResponse) === 'SUCCESS') {
        flash(
            'success',
            $action === 'success'
                ? 'Simulasi pembayaran sukses! Status transaksi telah lunas dan stok produk berkurang otomatis.'
                : 'Simulasi pembatalan pesanan berhasil diproses.'
        );
        redirect('belanja_pelanggan.php?order=' . $orderId);
    } else {
        $actionResult = [
            'type' => 'danger',
            'message' => 'Callback webhook mengembalikan respons HTTP ' . $httpCode . ': ' . htmlspecialchars((string) $callbackResponse),
        ];
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panduan &amp; Simulator Duitku Sandbox | AkbarMart</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #0b132b; color: #e2e8f0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .sim-card { background: #1c2541; border: 1px solid #3a506b; border-radius: 16px; }
        .copy-box { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 10px 14px; }
    </style>
</head>
<body class="py-5">
<div class="container" style="max-width: 720px;">

    <div class="text-center mb-4">
        <span class="badge bg-primary text-white px-3 py-2 rounded-pill fw-semibold">
            <i class="bi bi-shield-check me-1"></i> DUITKU SANDBOX SIMULATOR &amp; GUIDE
        </span>
        <h1 class="h3 fw-bold text-white mt-2">Simulasi Pembayaran Non-Tunai</h1>
        <p class="text-secondary small mb-0">AkbarMart — Jl. Gajah Mada No. 12, Ponorogo</p>
    </div>

    <?php if ($actionResult): ?>
        <div class="alert alert-<?= e($actionResult['type']) ?> alert-dismissible fade show" role="alert">
            <?= $actionResult['message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- CARD 1: INFORMASI RESMI DARI RESPONS DUITKU SANDBOX -->
    <div class="sim-card p-4 shadow-lg mb-4">
        <h5 class="text-white fw-bold mb-3 d-flex align-items-center gap-2">
            <i class="bi bi-info-circle text-info"></i> Data Transaksi Sandbox (TRX-<?= str_pad((string) $orderId, 6, '0', STR_PAD_LEFT) ?>)
        </h5>

        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <span class="text-secondary small d-block">Merchant Order ID:</span>
                <div class="copy-box d-flex justify-content-between align-items-center">
                    <code class="text-warning fw-bold font-monospace" id="val-order-id"><?= e($merchantOrderId) ?></code>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="navigator.clipboard.writeText('<?= e($merchantOrderId) ?>'); this.textContent = 'Disalin!';">Salin</button>
                </div>
            </div>
            <div class="col-sm-6">
                <span class="text-secondary small d-block">Total Nominal Tagihan:</span>
                <div class="copy-box">
                    <strong class="text-info fs-5"><?= rupiah($amount) ?></strong>
                </div>
            </div>
        </div>

        <?php if ($vaNumber !== ''): ?>
            <!-- KHUSUS VIRTUAL ACCOUNT -->
            <div class="p-3 rounded-3 border border-primary bg-primary bg-opacity-10 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-info small"><i class="bi bi-bank me-1"></i> Nomor Virtual Account (VA Number Resmi Duitku):</strong>
                    <button type="button" class="btn btn-sm btn-info py-0 px-2 fw-bold" onclick="navigator.clipboard.writeText('<?= e($vaNumber) ?>'); this.textContent = 'Disalin!';">
                        <i class="bi bi-clipboard me-1"></i>Salin Nomor VA
                    </button>
                </div>
                <div class="copy-box my-2">
                    <code class="text-white fs-4 fw-bold font-monospace"><?= e($vaNumber) ?></code>
                </div>
                <div class="text-white small">
                    <strong>📌 Cara Menguji di Demo Duitku:</strong>
                    <ol class="mb-0 ps-3 mt-1">
                        <li>Klik tombol <em>"Buka Halaman Demo Duitku"</em> di bawah.</li>
                        <li>Pilih <strong>Payment Method</strong> yang sesuai (contoh: <em>Bank Rakyat Indonesia</em> untuk BRI VA).</li>
                        <li>Tempelkan nomor VA di atas ke dalam kolom <strong>"Va Number"</strong>.</li>
                        <li>Klik <strong>Check</strong>, lalu konfirmasi pembayaran!</li>
                    </ol>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($reference !== ''): ?>
            <!-- KHUSUS QRIS -->
            <div class="p-3 rounded-3 border border-warning bg-warning bg-opacity-10 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <strong class="text-warning small"><i class="bi bi-qr-code me-1"></i> Duitku Reference (Khusus QRIS):</strong>
                    <button type="button" class="btn btn-sm btn-warning text-dark py-0 px-2 fw-bold" onclick="navigator.clipboard.writeText('<?= e($reference) ?>'); this.textContent = 'Disalin!';">
                        <i class="bi bi-clipboard me-1"></i>Salin Reference
                    </button>
                </div>
                <div class="copy-box my-2">
                    <code class="text-white fs-5 fw-bold font-monospace"><?= e($reference) ?></code>
                </div>
                <div class="text-white small">
                    <strong>📌 Cara Menguji QRIS di Demo Duitku:</strong> Masukkan Reference di atas pada kolom <strong>Reference</strong> di halaman demo lalu klik <strong>Check</strong>.
                </div>
            </div>
        <?php endif; ?>

        <div class="d-grid gap-2 mt-3">
            <a href="https://sandbox.duitku.com/payment/demo/demosuccesstransaction.aspx" target="_blank" rel="noopener" class="btn btn-success btn-lg fw-bold">
                <i class="bi bi-box-arrow-up-right me-2"></i> Buka Halaman Demo Duitku (demosuccesstransaction.aspx)
            </a>
            <?php if ($paymentUrl !== ''): ?>
                <a href="<?= e($paymentUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-info">
                    <i class="bi bi-credit-card me-2"></i> Buka Portal Pembayaran Duitku Langsung (paymentUrl)
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- CARD 2: SIMULATOR WEBHOOK CALLBACK LANGSUNG (OFFLINE / LOCAL TEST) -->
    <div class="sim-card p-4 shadow-lg mb-4">
        <h5 class="text-white fw-bold mb-2 d-flex align-items-center gap-2">
            <i class="bi bi-arrow-repeat text-success"></i> Simulasi Webhook Callback Internal (Lokal)
        </h5>
        <p class="text-secondary small">
            Gunakan tombol ini jika Anda ingin menguji penerimaan webhook secara langsung tanpa membuka browser luar. Sistem akan menghitung Signature MD5 resmi dan mengeksekusi <code>callback.php</code>.
        </p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="d-grid gap-2">
                <button type="submit" name="action" value="success" class="btn btn-primary fw-bold">
                    <i class="bi bi-check2-circle me-1"></i> Kirim Webhook Sukses (resultCode: 00 &middot; Potong Stok Atomik)
                </button>
                <button type="submit" name="action" value="cancel" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-x-circle me-1"></i> Kirim Webhook Batal (resultCode: 01)
                </button>
                <a href="belanja_pelanggan.php?order=<?= $orderId ?>" class="btn btn-link text-secondary btn-sm mt-2">
                    &larr; Kembali ke Halaman Belanja AkbarMart
                </a>
            </div>
        </form>
    </div>

    <div class="text-center text-secondary small">
        AkbarMart POS &middot; Integrasi Duitku Sandbox v2 &middot; Jl. Gajah Mada No. 12, Ponorogo
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
