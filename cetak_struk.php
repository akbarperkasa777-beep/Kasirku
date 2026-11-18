<?php
require_once __DIR__ . '/check_session.php';

$id = valid_int($_GET['id'] ?? null, 1);
if ($id === null) {
    http_response_code(400);
    exit('Nomor transaksi tidak valid.');
}
$transactionCode = 'TRX-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
$transactionSql = 'SELECT t.*, p.nama_pelanggan FROM tbl_transaksi t
    LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
    WHERE t.id_transaksi = ? AND t.status_pembayaran = \'lunas\'';
$transactionParams = [$id];
if ($_SESSION['role'] === 'kasir') {
    $transactionSql .= ' AND t.id_user = ?';
    $transactionParams[] = (int) $_SESSION['id_user'];
} elseif ($_SESSION['role'] === 'pelanggan') {
    $transactionSql .= ' AND t.id_user = ? AND t.id_pelanggan = ?';
    $transactionParams[] = (int) $_SESSION['id_user'];
    $transactionParams[] = (int) $_SESSION['id_pelanggan'];
}
$stmt = $pdo->prepare($transactionSql);
$stmt->execute($transactionParams);
$transaction = $stmt->fetch();
if (!$transaction) {
    http_response_code(404);
    exit('Transaksi tidak ditemukan atau tidak dapat diakses.');
}
$detailColumns = $pdo->query('SHOW COLUMNS FROM tbl_detail_transaksi')->fetchAll(PDO::FETCH_COLUMN, 0);
if (in_array('nama_produk', $detailColumns, true) && in_array('harga', $detailColumns, true)) {
    $detailQuery = 'SELECT d.jumlah, COALESCE(d.harga_satuan, d.harga) AS harga_satuan,
                    COALESCE(d.nama_produk, p.nama_produk) AS nama_produk
                    FROM tbl_detail_transaksi d LEFT JOIN tbl_produk p ON p.id_produk = d.id_produk
                    WHERE d.id_transaksi = ?';
} else {
    $detailQuery = 'SELECT d.jumlah, d.harga_satuan, p.nama_produk
                    FROM tbl_detail_transaksi d LEFT JOIN tbl_produk p ON p.id_produk = d.id_produk
                    WHERE d.id_transaksi = ?';
}
$stmt = $pdo->prepare($detailQuery);
$stmt->execute([$id]);
$details = $stmt->fetchAll();
$subtotal = 0.0;
foreach ($details as $detail) {
    $subtotal += (float) $detail['harga_satuan'] * (int) $detail['jumlah'];
}
$storeName = 'AkbarMart';
$storeAddress = 'Jl. Gajah Mada No. 12, Ponorogo';
$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Struk <?= e($transactionCode) ?> | <?= e($storeName) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/img/akbarmart-icon.svg?v=2">
    <!-- Google Fonts & Bootstrap Icons for Screen Preview -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 24px 16px 40px;
            background: #f1f5f9;
            color: #0f172a;
            font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Screen Preview Action Bar */
        .preview-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            width: 100%;
            max-width: 380px;
            margin-bottom: 20px;
            padding: 10px 14px;
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        }

        .preview-toolbar .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 14px;
            border: 0;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
        }

        .preview-toolbar .btn-back {
            background: #f8fafc;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .preview-toolbar .btn-back:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .preview-toolbar .btn-print {
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(79, 70, 229, 0.25);
        }

        .preview-toolbar .btn-print:hover {
            background: #4338ca;
        }

        /* Struk Thermal Paper Container */
        .receipt {
            width: 76mm;
            max-width: 100%;
            margin: 0 auto;
            padding: 6mm 4mm;
            background: #ffffff;
            color: #000000;
            border-radius: 6px;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08), 0 8px 10px -6px rgba(15, 23, 42, 0.04);
            border: 1px solid #e2e8f0;
            font: 700 11px/1.35 "Courier New", "Lucida Console", monospace;
            text-align: center;
        }

        .center { text-align: center; }
        .store-logo { display: block; width: auto; height: auto; max-width: 44px; max-height: 44px; margin: 0 auto 5px; filter: grayscale(100%) contrast(200%); }
        .store { font-size: 16px; font-weight: 700; letter-spacing: 1px; }
        .muted { color: #000; }
        .rule { border: 0; border-top: 1px dashed #000; margin: 8px 0; }
        .meta { display: flex; justify-content: space-between; gap: 5px; text-align: left; overflow-wrap: anywhere; }
        .meta span:last-child { text-align: right; }
        .item { margin: 6px 0; }
        .item-name { font-weight: 700; text-align: left; overflow-wrap: anywhere; }
        .item-sub { display: flex; justify-content: space-between; gap: 5px; text-align: left; }
        .item-sub span:last-child { text-align: right; }
        .totals .meta { justify-content: space-between; margin: 4px 0; text-align: left; }
        .totals .meta span:last-child { text-align: right; }
        .grand { font-size: 13.5px; font-weight: 700; }
        .barcode-block { margin: 10px auto 4px; }
        .barcode-block svg { display: block; width: auto; max-width: 100%; height: auto; margin: 0 auto; }
        .barcode-block svg text { fill: #000 !important; font-weight: 700; }
        .barcode-label { margin-top: 3px; font-size: 10px; }
        .barcode-error { color: #000; font-size: 10px; overflow-wrap: anywhere; }

        /* Exact Thermal Printer Page Rules */
        @page {
            size: 80mm auto;
            margin: 0;
        }

        @media print {
            html,
            body {
                width: 80mm !important;
                min-width: 80mm;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                color: #000000 !important;
            }

            body {
                display: flex;
                justify-content: center;
                align-items: flex-start;
                font-size: 11px;
                font-weight: 700;
            }

            .preview-toolbar {
                display: none !important;
            }

            .receipt {
                flex: 0 0 72mm;
                width: 72mm;
                max-width: 72mm;
                margin: 0 !important;
                padding: 2mm !important;
                border: 0 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                background: #ffffff !important;
                color: #000000 !important;
            }
        }
    </style>
</head>
<body>

<!-- Modern Action Toolbar for Screen Preview -->
<div class="preview-toolbar">
    <div style="display: flex; gap: 6px;">
        <a class="btn-action btn-back" href="pos.php" title="Kembali ke layar Kasir">
            <i class="bi bi-cart3"></i> Kasir
        </a>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <a class="btn-action btn-back" href="laporan.php" title="Buka Riwayat Laporan">
                <i class="bi bi-journal-text"></i> Laporan
            </a>
        <?php endif; ?>
    </div>
    <button class="btn-action btn-print" type="button" onclick="window.print()">
        <i class="bi bi-printer-fill"></i> Cetak Struk
    </button>
</div>

<!-- Receipt Document -->
<article class="receipt">
    <header class="center">
        <img class="store-logo" src="assets/img/akbarmart-icon.svg?v=2" alt="Logo AkbarMart" width="40" height="40">
        <div class="store"><?= e($storeName) ?></div>
        <div><?= e($storeAddress) ?></div>
        <div>Terima kasih telah berbelanja</div>
    </header>
    <?php if ((int) $transaction['simulasi_pembayaran'] === 1): ?>
        <div class="center" style="border: 1px dashed #000; margin-top: 6px; padding: 4px;">
            SIMULASI - BUKAN PEMBAYARAN NYATA
        </div>
    <?php endif; ?>
    <hr class="rule">
    <div class="meta"><span>No. Transaksi</span><span><?= e($transactionCode) ?></span></div>
    <div class="meta"><span>Tanggal</span><span><?= e(date('d/m/Y H:i', strtotime($transaction['tanggal']))) ?></span></div>
    <div class="meta"><span>Kasir</span><span><?= e($transaction['nama_kasir']) ?></span></div>
    <?php if ($transaction['nama_pelanggan']): ?>
        <div class="meta"><span>Pelanggan</span><span><?= e($transaction['nama_pelanggan']) ?></span></div>
    <?php endif; ?>
    <hr class="rule">
    <?php foreach ($details as $detail): ?>
        <div class="item">
            <div class="item-name"><?= e($detail['nama_produk']) ?></div>
            <div class="item-sub">
                <span><?= (int) $detail['jumlah'] ?> x <?= rupiah($detail['harga_satuan']) ?></span>
                <span><?= rupiah((float) $detail['harga_satuan'] * (int) $detail['jumlah']) ?></span>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$details): ?>
        <div class="center muted">Rincian produk tidak tersedia.</div>
    <?php endif; ?>
    <hr class="rule">
    <div class="totals">
        <div class="meta"><span>Subtotal</span><span><?= rupiah($subtotal) ?></span></div>
        <div class="meta"><span>Diskon</span><span>- <?= rupiah($transaction['diskon']) ?></span></div>
        <div class="meta grand"><span>TOTAL</span><span><?= rupiah($transaction['total_belanja']) ?></span></div>
        <div class="meta">
            <span><?= $transaction['metode_pembayaran'] === 'tunai' ? 'Tunai' : 'Bayar ' . strtoupper((string) $transaction['metode_pembayaran']) ?></span>
            <span><?= (float) $transaction['uang_bayar'] > 0 ? rupiah($transaction['uang_bayar']) : '--' ?></span>
        </div>
        <div class="meta"><span>Kembalian</span><span><?= $transaction['metode_pembayaran'] === 'tunai' ? rupiah($transaction['kembalian']) : '--' ?></span></div>
    </div>
    <hr class="rule">
    <footer class="center">
        <div>Barang yang sudah dibeli</div>
        <div>tidak dapat ditukar atau dikembalikan.</div>
        <br>
        <strong>Terima kasih &amp; sampai jumpa!</strong>
    </footer>
    <hr class="rule">
    <div class="barcode-block center">
        <svg id="transaction-barcode" role="img" aria-label="Barcode <?= e($transactionCode) ?>"></svg>
        <div id="barcode-error" class="barcode-error" hidden>Barcode tidak tersedia. Kode transaksi: <strong><?= e($transactionCode) ?></strong></div>
        <div class="barcode-label">Pindai untuk nomor transaksi</div>
    </div>
</article>

<script>
(function () {
    var barcode = document.getElementById('transaction-barcode');
    var fallback = document.getElementById('barcode-error');
    var transactionCode = <?= json_encode($transactionCode, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    try {
        if (typeof window.JsBarcode !== 'function') {
            throw new Error('Generator barcode tidak berhasil dimuat.');
        }
        window.JsBarcode(barcode, transactionCode, {
            format: 'CODE128',
            width: 1.5,
            height: 40,
            displayValue: true,
            font: 'Courier New',
            fontSize: 11,
            margin: 2
        });
    } catch (error) {
        barcode.hidden = true;
        fallback.hidden = false;
        console.error('Barcode struk gagal dibuat.', error);
    }

    if (<?= $autoPrint ? 'true' : 'false' ?>) {
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                window.print();
            });
        });
    }
})();
</script>
</body>
</html>
