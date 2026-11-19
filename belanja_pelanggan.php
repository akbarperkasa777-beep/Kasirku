<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_role('pelanggan');

$customerId = (int) $_SESSION['id_pelanggan'];
$userId = (int) $_SESSION['id_user'];
$customerName = (string) ($_SESSION['nama'] ?? 'Pelanggan');

// Konfigurasi Pembayaran Manual & Dummy
$paymentSettings = require __DIR__ . '/config/pembayaran.php';
$paymentMode = is_string($paymentSettings['mode'] ?? null) ? $paymentSettings['mode'] : 'disabled';
$dummyPayment = $paymentMode === 'dummy';
$manualPayment = $paymentMode === 'manual';
$qrisRelativePath = is_string($paymentSettings['qris_image'] ?? null) ? $paymentSettings['qris_image'] : '';
$realQrisConfigured = preg_match('/\Aassets\/img\/[A-Za-z0-9._-]+\.(?:png|jpe?g|webp)\z/i', $qrisRelativePath) === 1
    && is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $qrisRelativePath));
$bankName = trim((string) ($paymentSettings['bank_name'] ?? ''));
$bankAccountNumber = trim((string) ($paymentSettings['bank_account_number'] ?? ''));
$bankAccountName = trim((string) ($paymentSettings['bank_account_name'] ?? ''));
$realBankConfigured = $bankName !== '' && $bankAccountNumber !== '' && $bankAccountName !== '';
$qrisConfigured = $dummyPayment || ($manualPayment && $realQrisConfigured);
$bankConfigured = $dummyPayment || ($manualPayment && $realBankConfigured);

// Konfigurasi Payment Gateway Duitku (Otomatis Sandbox)
$duitkuConfigFile = __DIR__ . '/config/duitku.php';
$hasDuitkuConfig = is_file($duitkuConfigFile);
$duitkuConfig = $hasDuitkuConfig ? require $duitkuConfigFile : [];
$duitkuConfigured = $hasDuitkuConfig
    && !empty($duitkuConfig['merchant_code'])
    && !empty($duitkuConfig['api_key']);
$demoSimulatorUrl = (string) ($duitkuConfig['demo_simulator_url'] ?? 'https://sandbox.duitku.com/payment/demo/demosuccesstransaction.aspx');

// Wajib Non-Tunai: Duitku, QRIS, atau Transfer Bank (Tanpa Opsi Tunai)
$canPay = $duitkuConfigured || $qrisConfigured || $bankConfigured;

// Handle Checkout Manual (QRIS Toko Fisik / Transfer Bank jika memilih metode manual upload bukti)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'checkout';

    $quantitiesInput = $_POST['qty'] ?? [];
    if (!is_array($quantitiesInput) || count($quantitiesInput) > 100) {
        flash('danger', 'Format keranjang belanja tidak valid.');
        redirect('belanja_pelanggan.php');
    }

    $quantities = [];
    foreach ($quantitiesInput as $productId => $quantityInput) {
        $productId = valid_int($productId, 1);
        $quantity = valid_int($quantityInput, 0);
        if ($productId === null || $quantity === null || $quantity > 99) {
            flash('danger', 'Produk atau kuantitas pada keranjang tidak valid.');
            redirect('belanja_pelanggan.php');
        }
        if ($quantity === 0) {
            continue;
        }
        $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
        if ($quantities[$productId] > 99) {
            flash('danger', 'Jumlah setiap produk maksimal 99 pcs.');
            redirect('belanja_pelanggan.php');
        }
    }

    $paymentMethod = is_string($_POST['metode_pembayaran'] ?? null) ? $_POST['metode_pembayaran'] : '';
    $paymentAction = is_string($_POST['payment_action'] ?? null) ? $_POST['payment_action'] : '';
    $instantSuccess = $dummyPayment && $paymentAction === 'simulate_success';
    $proofSubmission = $paymentAction === 'submit_proof' && ($dummyPayment || $manualPayment);
    $methodAvailable = ($paymentMethod === 'qris' && $qrisConfigured)
        || ($paymentMethod === 'transfer' && $bankConfigured);

    if (!$quantities || !$methodAvailable || (!$instantSuccess && !$proofSubmission)) {
        $message = !$quantities
            ? 'Keranjang belanja Anda masih kosong.'
            : (!$methodAvailable
                ? 'Metode pembayaran non-tunai belum dikonfigurasi atau tidak valid.'
                : 'Tindakan pembayaran tidak valid untuk mode pembayaran saat ini.');
        flash('danger', $message);
        redirect('belanja_pelanggan.php');
    }

    $proofDirectory = __DIR__ . '/storage/pembayaran';
    $proofFilename = null;
    if (!$instantSuccess) {
        $upload = $_FILES['bukti_pembayaran'] ?? null;
        if (
            !is_array($upload)
            || !isset($upload['error'], $upload['tmp_name'], $upload['name'])
            || is_array($upload['error'])
            || (int) $upload['error'] !== UPLOAD_ERR_OK
        ) {
            flash('danger', 'Silakan unggah bukti pembayaran sebelum mengirim pesanan.');
            redirect('belanja_pelanggan.php');
        }

        $temporaryPath = (string) $upload['tmp_name'];
        $fileSize = is_uploaded_file($temporaryPath) ? filesize($temporaryPath) : false;
        if ($fileSize === false || $fileSize < 1 || $fileSize > 5 * 1024 * 1024 || !class_exists('finfo')) {
            flash('danger', 'File bukti pembayaran tidak valid atau melebihi 5 MB.');
            redirect('belanja_pelanggan.php');
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fileInfo->file($temporaryPath);
        $proofTypes = [
            'image/jpeg' => ['extension' => 'jpg', 'image_type' => IMAGETYPE_JPEG],
            'image/png' => ['extension' => 'png', 'image_type' => IMAGETYPE_PNG],
            'image/webp' => ['extension' => 'webp', 'image_type' => IMAGETYPE_WEBP],
        ];
        if ($dummyPayment) {
            $proofTypes['image/gif'] = ['extension' => 'gif', 'image_type' => IMAGETYPE_GIF];
        } else {
            $proofTypes['application/pdf'] = ['extension' => 'pdf', 'image_type' => null];
        }
        if (!is_string($mime) || !isset($proofTypes[$mime])) {
            flash('danger', $dummyPayment
                ? 'Bukti simulasi harus berupa gambar JPG, PNG, WEBP, atau GIF.'
                : 'Bukti pembayaran harus berupa gambar JPG, PNG, WEBP, atau file PDF.');
            redirect('belanja_pelanggan.php');
        }
        if ($mime !== 'application/pdf') {
            $imageInfo = @getimagesize($temporaryPath);
            if (!is_array($imageInfo) || $imageInfo[2] !== $proofTypes[$mime]['image_type']) {
                flash('danger', 'Isi file gambar bukti pembayaran tidak valid.');
                redirect('belanja_pelanggan.php');
            }
        }

        if (!is_dir($proofDirectory) && !mkdir($proofDirectory, 0700, true) && !is_dir($proofDirectory)) {
            flash('danger', 'Folder penyimpanan bukti pembayaran tidak dapat dibuat.');
            redirect('belanja_pelanggan.php');
        }
        $proofFilename = bin2hex(random_bytes(16)) . '.' . $proofTypes[$mime]['extension'];
        if (!move_uploaded_file($temporaryPath, $proofDirectory . '/' . $proofFilename)) {
            flash('danger', 'Bukti pembayaran gagal disimpan. Silakan coba kembali.');
            redirect('belanja_pelanggan.php');
        }
    }

    try {
        $pdo->beginTransaction();
        $memberQuery = $pdo->prepare(
            "SELECT status_member FROM tbl_pelanggan
             WHERE id_pelanggan = ? AND status_member = 'aktif'
             FOR UPDATE"
        );
        $memberQuery->execute([$customerId]);
        $isMemberActiveNow = ($memberQuery->fetchColumn() === 'aktif');

        $productIds = array_keys($quantities);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $productQuery = $pdo->prepare(
            "SELECT id_produk, nama_produk, harga, stok
             FROM tbl_produk WHERE id_produk IN ({$placeholders})
             ORDER BY id_produk FOR UPDATE"
        );
        $productQuery->execute($productIds);
        $productsList = $productQuery->fetchAll();
        if (count($productsList) !== count($productIds)) {
            throw new DomainException('Satu atau lebih produk sudah tidak tersedia.');
        }

        $subtotal = 0.0;
        foreach ($productsList as $product) {
            $id = (int) $product['id_produk'];
            if ($quantities[$id] > (int) $product['stok']) {
                throw new DomainException('Stok "' . $product['nama_produk'] . '" tidak mencukupi.');
            }
            $subtotal += (float) $product['harga'] * $quantities[$id];
        }

        $discount = 0.0;
        if ($isMemberActiveNow && $subtotal > 0) {
            $discount = round($subtotal * 0.10);
        }
        $total = max(0.0, $subtotal - $discount);
        $paymentStatus = $instantSuccess ? 'lunas' : 'menunggu_verifikasi';
        $paidAmount = $instantSuccess ? $total : 0.0;
        $paidAt = $instantSuccess ? date('Y-m-d H:i:s') : null;

        $insertOrder = $pdo->prepare(
            "INSERT INTO tbl_transaksi
             (id_user, id_pelanggan, total_belanja, diskon, uang_bayar, kembalian, nama_kasir,
              metode_pembayaran, status_pembayaran, simulasi_pembayaran, bukti_pembayaran, tanggal_pembayaran)
             VALUES (?, ?, ?, ?, ?, 0, 'Belanja Mandiri', ?, ?, ?, ?, ?)"
        );
        $insertOrder->execute([
            $userId,
            $customerId,
            $total,
            $discount,
            $paidAmount,
            $paymentMethod,
            $paymentStatus,
            (int) $dummyPayment,
            $proofFilename,
            $paidAt,
        ]);
        $transactionId = (int) $pdo->lastInsertId();

        $detailColumns = $pdo->query('SHOW COLUMNS FROM tbl_detail_transaksi')->fetchAll(PDO::FETCH_COLUMN, 0);
        $hasLegacyColumns = in_array('nama_produk', $detailColumns, true) && in_array('harga', $detailColumns, true);
        if ($hasLegacyColumns) {
            $insertDetail = $pdo->prepare(
                'INSERT INTO tbl_detail_transaksi (id_transaksi, id_produk, nama_produk, jumlah, harga, harga_satuan)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
        } else {
            $insertDetail = $pdo->prepare(
                'INSERT INTO tbl_detail_transaksi (id_transaksi, id_produk, jumlah, harga_satuan) VALUES (?, ?, ?, ?)'
            );
        }
        foreach ($productsList as $product) {
            $id = (int) $product['id_produk'];
            $quantity = $quantities[$id];
            if ($hasLegacyColumns) {
                $insertDetail->execute([$transactionId, $id, $product['nama_produk'], $quantity, $product['harga'], $product['harga']]);
            } else {
                $insertDetail->execute([$transactionId, $id, $quantity, $product['harga']]);
            }
        }

        if ($instantSuccess) {
            $updateStock = $pdo->prepare('UPDATE tbl_produk SET stok = stok - ? WHERE id_produk = ? AND stok >= ?');
            foreach ($productsList as $product) {
                $id = (int) $product['id_produk'];
                $quantity = $quantities[$id];
                $updateStock->execute([$quantity, $id, $quantity]);
                if ($updateStock->rowCount() !== 1) {
                    throw new DomainException('Stok berubah saat simulasi pembayaran. Coba lagi.');
                }
            }
        }
        $pdo->commit();

        flash(
            'success',
            $instantSuccess
                ? 'Simulasi pembayaran berhasil! Pesanan Anda ditandai lunas.'
                : 'Bukti pembayaran pesanan TRX-' . str_pad((string)$transactionId, 6, '0', STR_PAD_LEFT) . ' berhasil dikirim dan menunggu verifikasi admin.'
        );
        redirect('riwayat_transaksi.php');
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($proofFilename !== null && is_file($proofDirectory . '/' . $proofFilename) && !unlink($proofDirectory . '/' . $proofFilename)) {
            error_log('Gagal menghapus bukti pembayaran pesanan yang dibatalkan: ' . $proofFilename);
        }
        flash('warning', $exception->getMessage());
        redirect('belanja_pelanggan.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($proofFilename !== null && is_file($proofDirectory . '/' . $proofFilename) && !unlink($proofDirectory . '/' . $proofFilename)) {
            error_log('Gagal menghapus bukti pembayaran pesanan yang gagal: ' . $proofFilename);
        }
        error_log('Pembuatan pesanan pelanggan gagal: ' . $exception->getMessage());
        flash('danger', 'Pesanan tidak dapat disimpan. Silakan coba kembali.');
        redirect('belanja_pelanggan.php');
    }
}

// Ambil data produk & kategori
$categories = [];
try {
    $categories = $pdo->query('SELECT id_kategori, nama_kategori FROM tbl_kategori ORDER BY nama_kategori')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$products = $pdo->query(
    'SELECT p.id_produk, p.nama_produk, p.gambar, p.harga, p.stok, p.id_kategori, COALESCE(k.nama_kategori, \'Umum\') AS nama_kategori
     FROM tbl_produk p
     LEFT JOIN tbl_kategori k ON k.id_kategori = p.id_kategori
     WHERE p.stok > 0
     ORDER BY p.nama_produk'
)->fetchAll(PDO::FETCH_ASSOC);

// Ambil info pelanggan & status member RFID untuk diskon 10%
$memberQuery = $pdo->prepare('SELECT nama_pelanggan, id_card_rfid, status_member FROM tbl_pelanggan WHERE id_pelanggan = ? LIMIT 1');
$memberQuery->execute([$customerId]);
$customerMember = $memberQuery->fetch(PDO::FETCH_ASSOC) ?: [];
$isMemberActive = (($customerMember['status_member'] ?? '') === 'aktif');
$rfidCardNumber = (string) ($customerMember['id_card_rfid'] ?? '');

$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$isDuitkuReturn = isset($_GET['duitku']) && $_GET['duitku'] === '1';
$pageTitle = 'Belanja Mandiri Non-Tunai';
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
        .product-card {
            height: 100%;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #ffffff;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.06);
            border-color: #cbd5e1;
        }
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 1.25rem;
        }
        .product-image {
            display: block;
            width: 100%;
            height: 160px;
            padding: 10px;
            object-fit: contain;
            object-position: center;
            background: #ffffff;
            border-top-left-radius: 14px;
            border-top-right-radius: 14px;
        }
        .cart-panel {
            position: sticky;
            top: 75px;
            z-index: 10;
        }
        .cart-line {
            border-bottom: 1px solid #f1f5f9;
            padding: 10px 0;
        }
        .cart-line:last-child {
            border-bottom: 0;
        }
        .cart-qty {
            max-width: 60px;
            text-align: center;
            font-weight: 600;
        }
        .duitku-box {
            background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
            border: 1.5px solid #bfdbfe;
            border-radius: 14px;
            padding: 16px;
        }
        .btn-pay-duitku {
            background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%);
            border: 0;
            color: #ffffff;
            font-weight: 700;
            padding: 12px;
            border-radius: 10px;
            transition: all 0.2s ease;
        }
        .btn-pay-duitku:hover {
            background: linear-gradient(135deg, #4338ca 0%, #0891b2 100%);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
        }
        .info-copy-box {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .category-pill {
            cursor: pointer;
            transition: all 0.2s ease;
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/navbar_pelanggan.php'; ?>

<main class="container py-4" style="max-width: 1200px;">
    <!-- Banner Header Belanja & Ketentuan Non-Tunai -->
    <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <div class="d-flex align-items-center gap-2 text-primary fw-semibold small mb-1">
                    <i class="bi bi-geo-alt-fill"></i> Jl. Gajah Mada No. 12, Ponorogo &bull; Kasir &amp; Belanja Mandiri
                </div>
                <h1 class="h4 fw-bold text-slate-900 mb-1">Katalog Belanja Mandiri Non-Tunai</h1>
                <p class="text-secondary small mb-0">
                    Sistem transaksi AkbarMart <strong>wajib non-tunai (cashless)</strong>. Pilih barang favorit Anda, nikmati <strong>diskon 10%</strong> untuk member RFID aktif, dan selesaikan pembayaran online secara aman via <strong>Payment Gateway Duitku Sandbox</strong>.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="d-inline-flex flex-wrap gap-2">
                    <a href="riwayat_transaksi.php" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-2 fw-semibold">
                        <i class="bi bi-clock-history me-1"></i> Riwayat Transaksi
                    </a>
                    <a href="profil.php" class="btn btn-light border btn-sm rounded-pill px-3 py-2 fw-semibold text-slate-700">
                        <i class="bi bi-person-circle me-1"></i> Profil Saya
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isDuitkuReturn): ?>
        <div class="alert alert-success d-flex align-items-center gap-3 p-3 shadow-sm rounded-3 mb-4" role="alert">
            <i class="bi bi-check-circle-fill fs-2 text-success"></i>
            <div>
                <strong class="d-block text-success-emphasis">Status Pembayaran Sedang Diproses Duitku!</strong>
                <span class="small text-muted">
                    Terima kasih telah melakukan pembayaran. Webhook Duitku sedang memperbarui status transaksi dan mengurangi stok secara otomatis. Periksa riwayat di <a href="riwayat_transaksi.php" class="fw-bold text-success text-decoration-underline">Riwayat Transaksi Saya</a>.
                </span>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($flashMessage): ?>
        <div class="alert alert-<?= e($flashMessage['type']) ?> alert-dismissible fade show rounded-3 shadow-sm mb-4" role="alert">
            <?= e($flashMessage['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4 align-items-start">
        <!-- Kolom Kiri: Katalog Produk & Filter -->
        <section class="col-lg-7" aria-labelledby="catalog-title">
            <!-- Filter Kategori & Pencarian Cepat -->
            <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
                <div class="row g-2 align-items-center">
                    <div class="col-md-6">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control border-start-0 bg-light" id="product-search" placeholder="Cari nama produk...">
                        </div>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <span class="badge text-bg-light border text-secondary px-3 py-2 rounded-pill small" id="product-count-badge">
                            <?= count($products) ?> produk tersedia
                        </span>
                    </div>
                </div>

                <?php if ($categories): ?>
                    <div class="d-flex gap-1 overflow-x-auto pt-3 pb-1" id="category-filter-list">
                        <span class="badge bg-primary text-white px-3 py-2 rounded-pill category-pill" data-category="all">Semua Kategori</span>
                        <?php foreach ($categories as $cat): ?>
                            <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill category-pill" data-category="<?= e($cat['nama_kategori']) ?>">
                                <?= e($cat['nama_kategori']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Grid Produk -->
            <div class="product-grid" id="product-grid-container">
                <?php foreach ($products as $product): ?>
                    <?php
                    $imageName = safe_product_image_name($product['gambar'] ?? null);
                    $imagePath = $imageName !== null ? 'uploads/produk/' . $imageName : null;
                    ?>
                    <article class="card product-card shadow-sm product-item" 
                             data-product-id="<?= (int) $product['id_produk'] ?>"
                             data-product-name="<?= e(mb_strtolower($product['nama_produk'])) ?>"
                             data-category="<?= e($product['nama_kategori']) ?>">
                        <?php if ($imagePath !== null && is_file(__DIR__ . '/' . $imagePath)): ?>
                            <img class="card-img-top product-image" src="<?= e($imagePath) ?>" alt="<?= e($product['nama_produk']) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="product-image d-flex align-items-center justify-content-center text-secondary bg-light">
                                <i class="bi bi-box-seam fs-1"></i>
                            </div>
                        <?php endif; ?>
                        <div class="card-body d-flex flex-column p-3">
                            <div class="mb-1">
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-0" style="font-size: 10px;">
                                    <?= e($product['nama_kategori']) ?>
                                </span>
                            </div>
                            <h3 class="h6 fw-bold text-slate-900 mb-1 text-truncate" title="<?= e($product['nama_produk']) ?>">
                                <?= e($product['nama_produk']) ?>
                            </h3>
                            <div class="text-primary fw-bold fs-6 mb-1">
                                <?= rupiah($product['harga']) ?>
                            </div>
                            <small class="text-muted mb-3" style="font-size: 11.5px;">
                                <i class="bi bi-boxes me-1"></i>Stok: <strong><?= (int) $product['stok'] ?></strong> pcs
                            </small>
                            <button class="btn btn-outline-primary btn-sm rounded-pill mt-auto py-2 fw-semibold" type="button" data-add-product
                                    data-id="<?= (int) $product['id_produk'] ?>"
                                    data-name="<?= e($product['nama_produk']) ?>"
                                    data-price="<?= e($product['harga']) ?>"
                                    data-stock="<?= (int) $product['stok'] ?>">
                                <i class="bi bi-cart-plus me-1"></i> + Keranjang
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$products): ?>
                    <div class="col-12"><div class="alert alert-info">Belum ada produk dengan stok tersedia saat ini.</div></div>
                <?php endif; ?>
            </div>
            <div id="no-product-match" class="alert alert-light border text-center text-muted py-4 d-none mt-3">
                <i class="bi bi-search fs-2 d-block text-secondary mb-2"></i>
                Tidak ada produk yang cocok dengan pencarian atau filter yang dipilih.
            </div>
        </section>

        <!-- Kolom Kanan: Keranjang Belanja Mandiri (Sticky) -->
        <section class="col-lg-5" aria-labelledby="cart-title">
            <div class="cart-panel">
                <form method="post" enctype="multipart/form-data" id="customer-order-form" class="card border-0 shadow-sm rounded-4 bg-white">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="checkout">
                    
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                        <h2 class="h5 fw-bold mb-0 text-slate-800" id="cart-title">
                            <i class="bi bi-basket2-fill me-2 text-primary"></i>Keranjang Belanja
                        </h2>
                        <button type="button" class="btn btn-link text-danger text-decoration-none btn-sm p-0 fw-semibold d-none" id="btn-clear-cart" title="Hapus semua barang dari keranjang">
                            <i class="bi bi-trash3 me-1"></i>Kosongkan
                        </button>
                    </div>

                    <div class="card-body p-4">
                        <div id="customer-cart">
                            <p class="text-secondary small mb-0 py-4 text-center" id="cart-empty">
                                <i class="bi bi-cart-x fs-2 d-block text-muted mb-2"></i>Keranjang Anda masih kosong.<br>Silakan pilih produk dari katalog.
                            </p>
                        </div>

                        <?php if ($isMemberActive): ?>
                            <div id="cart-member-badge" class="alert alert-success py-2 px-3 small d-flex align-items-center justify-content-between mt-3 mb-0 rounded-3">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-patch-check-fill text-success fs-5"></i>
                                    <div>
                                        <strong class="d-block text-slate-900" style="font-size: 12.5px;">Member AkbarMart Aktif</strong>
                                        <small class="text-success fw-semibold">Diskon Otomatis 10% Terpasang</small>
                                    </div>
                                </div>
                                <span class="badge bg-success py-1 px-2">10% OFF</span>
                            </div>
                        <?php endif; ?>

                        <div class="summary-details border-top pt-3 mt-3">
                            <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
                                <span>Subtotal Belanja:</span>
                                <span id="cart-subtotal" class="fw-semibold text-slate-800">Rp 0</span>
                            </div>
                            <div id="cart-discount-row" class="<?= $isMemberActive ? 'd-flex' : 'd-none' ?> justify-content-between align-items-center text-success small mb-1">
                                <span>Diskon Member (10%):</span>
                                <span id="cart-discount" class="fw-bold">-Rp 0</span>
                            </div>
                            <div class="summary-total d-flex justify-content-between align-items-center pt-2 border-top">
                                <span class="text-dark fw-bold">Total Pembayaran:</span>
                                <strong class="text-primary fs-4" id="cart-total">Rp 0</strong>
                            </div>
                        </div>

                        <div class="alert alert-light border small text-muted mt-3 py-2 px-3 d-flex align-items-center gap-2 rounded-3">
                            <i class="bi bi-shield-check text-success fs-5"></i>
                            <span>Aturan Transaksi: <strong>Wajib Non-Tunai (Cashless)</strong>. Tanpa uang tunai.</span>
                        </div>

                        <hr class="my-3">

                        <!-- Pilihan Pembayaran Non-Tunai -->
                        <h3 class="h6 fw-bold text-slate-900 mb-2">Metode Pembayaran Non-Tunai</h3>

                        <?php if (!$canPay): ?>
                            <div class="alert alert-warning small">
                                Metode pembayaran non-tunai belum dikonfigurasi. Hubungi Admin AkbarMart.
                            </div>
                        <?php else: ?>

                            <!-- OPSI 1: PAYMENT GATEWAY DUITKU SANDBOX -->
                            <?php if ($duitkuConfigured): ?>
                                <div class="duitku-box mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="metode_pembayaran" id="payment-duitku" value="duitku" checked required>
                                        <label class="form-check-label fw-bold text-dark d-flex align-items-center justify-content-between" for="payment-duitku">
                                            <span><i class="bi bi-credit-card-2-front-fill text-primary me-1"></i> Payment Gateway Duitku (Otomatis)</span>
                                            <span class="badge bg-primary text-white" style="font-size: 10px;">Sandbox</span>
                                        </label>
                                        <div class="small text-muted mt-1" style="font-size: 11.5px;">
                                            Mendukung <strong>Virtual Account</strong>, <strong>QRIS</strong>, dan <strong>E-Wallet</strong> resmi. Terverifikasi instan tanpa unggah struk manual.
                                        </div>

                                        <!-- Selector Kanal Pembayaran Duitku Lengkap -->
                                        <div class="mt-3 pt-2 border-top border-primary-subtle" id="duitku-channel-container">
                                            <label class="form-label small fw-bold text-slate-800 mb-1" for="duitku-payment-method">
                                                <i class="bi bi-wallet2 me-1 text-primary"></i>Pilih Metode / Kanal Pembayaran Duitku:
                                            </label>
                                            <select class="form-select form-select-sm" name="duitku_payment_method" id="duitku-payment-method">
                                                <optgroup label="Virtual Account (Simulasi via Nomor VA Asli)">
                                                    <option value="BR" selected>BRI Virtual Account (BR)</option>
                                                    <option value="BC">BCA Virtual Account (BC)</option>
                                                    <option value="M2">Mandiri Virtual Account (M2)</option>
                                                    <option value="I1">BNI Virtual Account (I1)</option>
                                                    <option value="VA">Maybank Virtual Account (VA)</option>
                                                    <option value="BT">Permata Bank Virtual Account (BT)</option>
                                                    <option value="B1">CIMB Niaga Virtual Account (B1)</option>
                                                    <option value="BV">BSI Virtual Account (BV)</option>
                                                    <option value="DM">Danamon Virtual Account (DM)</option>
                                                    <option value="AG">Bank Artha Graha VA (AG)</option>
                                                    <option value="NC">Bank Neo Commerce / BNC VA (NC)</option>
                                                    <option value="S1">Bank Sahabat Sampoerna VA (S1)</option>
                                                    <option value="A1">ATM Bersama / Alto VA (A1)</option>
                                                </optgroup>
                                                <optgroup label="QRIS Real-Time (Simulasi via Duitku Reference - Rekomendasi Nominal Kecil)">
                                                    <option value="SP">QRIS ShopeePay / All E-Wallet (SP)</option>
                                                    <option value="NQ">QRIS Nobu / All E-Wallet (NQ)</option>
                                                </optgroup>
                                                <optgroup label="E-Wallet">
                                                    <option value="OV">OVO (OV)</option>
                                                    <option value="DA">DANA (DA)</option>
                                                    <option value="SA">ShopeePay App Direct (SA)</option>
                                                    <option value="LA">LinkAja (LA)</option>
                                                    <option value="AP">AstraPay (AP)</option>
                                                </optgroup>
                                                <optgroup label="Gerai Retail (Convenience Store)">
                                                    <option value="FT">Indomaret (FT)</option>
                                                    <option value="IR">Pos Indonesia / Pegadaian (IR)</option>
                                                </optgroup>
                                                <optgroup label="Portal Checkout Lengkap">
                                                    <option value="">Semua Metode (Buka Portal Duitku)</option>
                                                </optgroup>
                                            </select>
                                            <div class="form-text text-muted" style="font-size: 11px;">
                                                ⭐ <em>Panduan Demo: Untuk <strong>Virtual Account (BRI, BCA, Mandiri, dll.)</strong> gunakan <strong>Nomor VA Resmi</strong>. Untuk <strong>QRIS</strong> gunakan <strong>Duitku Reference</strong>.</em>
                                            </div>
                                            <!-- Alert Tips Rekomendasi QRIS untuk Nominal Kecil < 10.000 -->
                                            <div id="qris-small-nominal-tip" class="d-none alert alert-info py-2 px-2 mt-2 mb-0" style="font-size: 11.5px;">
                                                <i class="bi bi-info-circle-fill me-1 text-primary"></i><strong>Saran Transaksi Nominal Kecil:</strong> Belanja di bawah Rp 10.000 disarankan menggunakan kanal <strong>QRIS ShopeePay (SP)</strong> karena Virtual Account perbankan mensyaratkan batas minimal Rp 10.000.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- OPSI 2 & 3: MANUAL QRIS / TRANSFER (JIKA DIKONFIGURASI) -->
                            <?php if ($qrisConfigured): ?>
                                <div class="form-check mb-2 ps-4">
                                    <input class="form-check-input" type="radio" name="metode_pembayaran" id="payment-qris" value="qris" <?= !$duitkuConfigured ? 'checked' : '' ?> required>
                                    <label class="form-check-label small fw-semibold" for="payment-qris">QRIS Toko Fisik (Manual Upload Bukti)</label>
                                </div>
                            <?php endif; ?>

                            <?php if ($bankConfigured): ?>
                                <div class="form-check mb-3 ps-4">
                                    <input class="form-check-input" type="radio" name="metode_pembayaran" id="payment-transfer" value="transfer" required>
                                    <label class="form-check-label small fw-semibold" for="payment-transfer">Transfer Bank (Manual Upload Bukti)</label>
                                </div>
                            <?php endif; ?>

                            <!-- Tombol Checkout Utama -->
                            <div class="d-grid gap-2 mt-3">
                                <?php if ($duitkuConfigured): ?>
                                    <button class="btn btn-pay-duitku w-100" type="button" id="pay-duitku-button">
                                        <i class="bi bi-lightning-charge-fill me-1"></i> Bayar Online via Duitku Sandbox
                                    </button>
                                <?php endif; ?>

                                <?php if ($qrisConfigured || $bankConfigured): ?>
                                    <button class="btn btn-outline-secondary w-100 <?= $duitkuConfigured ? 'btn-sm' : 'py-2 fw-semibold' ?>" type="button" id="manual-checkout-button">
                                        <i class="bi bi-receipt me-1"></i> Bayar Manual &amp; Upload Bukti
                                    </button>
                                <?php endif; ?>
                            </div>

                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <!-- MODAL INFORMASI TRANSAKSI & SIMULASI DEMO DUITKU SANDBOX -->
    <div class="modal fade" id="duitku-info-modal" tabindex="-1" aria-labelledby="duitkuModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-primary text-white py-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-shield-check fs-5"></i>
                        <h5 class="modal-title fs-6 fw-bold mb-0" id="duitkuModalLabel">Tagihan Pembayaran Duitku Sandbox Siap</h5>
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
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 fs-6" id="modal-display-method">QRIS Realtime</span>
                            </div>
                        </div>

                        <!-- INDIKATOR DETEKSI OTOMATIS REAL-TIME -->
                        <div class="d-flex align-items-center justify-content-center gap-2 small text-primary bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-3 py-2 px-3 mb-3" id="polling-indicator">
                            <div class="spinner-grow spinner-grow-sm text-primary" role="status"></div>
                            <span class="fw-semibold" style="font-size: 12px;">Mendeteksi status pembayaran secara otomatis (real-time)...</span>
                        </div>

                        <!-- KOTAK 1: VIRTUAL ACCOUNT NUMBER (UTAMA UNTUK BRI, BCA, MANDIRI, BNI, DLL.) -->
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
                                <code class="fs-4 fw-bold text-dark font-monospace" id="modal-display-va-number">-</code>
                            </div>
                            <small class="text-dark d-block mt-2" style="font-size: 11.5px;">
                                👉 <strong>Langkah Simulasi VA di Demo Duitku:</strong> Buka halaman demo Duitku, pilih <strong>Payment Method</strong> (contoh: <em>Bank Rakyat Indonesia</em> untuk BRI VA), lalu tempelkan <strong>Nomor Virtual Account</strong> di atas pada kolom <strong>Va Number</strong>, dan klik tombol <strong>Check</strong>.
                            </small>
                        </div>

                        <!-- KOTAK 2: DUITKU REFERENCE (UTAMA UNTUK QRIS) -->
                        <div class="p-3 bg-warning bg-opacity-10 border border-warning rounded-3 mb-3" id="box-qris-ref">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="text-dark small d-flex align-items-center gap-1">
                                    <i class="bi bi-qr-code text-warning"></i> Duitku Reference (Khusus Kanal QRIS):
                                </strong>
                                <button type="button" class="btn btn-sm btn-outline-warning text-dark py-0 px-2 fw-semibold" id="btn-copy-ref">
                                    <i class="bi bi-clipboard me-1"></i>Salin Referensi
                                </button>
                            </div>
                            <div class="info-copy-box">
                                <code class="fs-5 fw-bold text-dark font-monospace" id="modal-display-ref">-</code>
                            </div>
                            <small class="text-dark d-block mt-2" style="font-size: 11.5px;">
                                👉 <strong>Langkah Simulasi QRIS:</strong> Buka halaman demo Duitku, tempelkan <strong>Duitku Reference</strong> di atas pada kolom input <strong>Reference</strong> (atau scan QRIS bila membuka paymentUrl), lalu klik <strong>Check</strong>.
                            </small>
                        </div>

                        <!-- KOTAK 3: ORDER ID (INFORMASI TAMBAHAN) -->
                        <div class="p-3 bg-light border border-secondary-subtle rounded-3 mb-4" id="box-order-id">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="text-dark small">Merchant Order ID (Internal AkbarMart):</strong>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fw-semibold" id="btn-copy-order">
                                    <i class="bi bi-clipboard me-1"></i>Salin Order ID
                                </button>
                            </div>
                            <div class="info-copy-box">
                                <code class="fs-6 fw-bold text-secondary font-monospace" id="modal-display-order-id">-</code>
                            </div>
                        </div>

                        <!-- PANDUAN RINGKAS -->
                        <div class="alert alert-info py-2 px-3 small mb-4">
                            <i class="bi bi-info-circle-fill me-1"></i>
                            Setelah Anda menekan <strong>Check</strong> pada halaman simulasi Duitku, webhook Duitku otomatis diverifikasi, memotong stok produk secara atomik, dan layar ini akan langsung berganti menjadi <strong>Pembayaran Berhasil</strong> tanpa perlu refresh!
                        </div>

                        <!-- TOMBOL AKSI SIMULASI -->
                        <div class="d-grid gap-2">
                            <a href="https://sandbox.duitku.com/payment/demo/demosuccesstransaction.aspx" target="_blank" rel="noopener" class="btn btn-success btn-lg fw-bold py-3 shadow-sm" id="btn-open-demo">
                                <i class="bi bi-box-arrow-up-right me-2"></i> Buka Halaman Simulasi Duitku (demosuccesstransaction.aspx)
                            </a>
                            <a href="#" target="_blank" rel="noopener" class="btn btn-outline-primary fw-semibold py-2" id="btn-open-portal">
                                <i class="bi bi-credit-card me-2"></i> Buka Portal Pembayaran Duitku (paymentUrl)
                            </a>
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Tutup &amp; Lihat Riwayat Transaksi</button>
                        </div>
                    </div>

                    <!-- TAMPILAN 2: LAYAR PEMBAYARAN BERHASIL (OTOMATIS TAMPIL KETIKA LUNAS) -->
                    <div id="duitku-success-view" class="d-none text-center py-4">
                        <div class="mb-3">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 4.5rem;"></i>
                        </div>
                        <h4 class="fw-bold text-dark mb-1">Pembayaran Berhasil &amp; Lunas!</h4>
                        <p class="text-muted small mb-4">
                            Terima kasih! Pembayaran Anda telah terverifikasi resmi oleh Payment Gateway Duitku. Stok barang telah terpotong otomatis secara aman.
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
                            <div class="d-flex justify-content-between py-1 border-bottom border-secondary-subtle">
                                <span class="text-muted small">Metode Pembayaran:</span>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" id="success-display-method">DUITKU</span>
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
                            <a href="riwayat_transaksi.php" class="btn btn-primary px-4 fw-bold">
                                <i class="bi bi-clock-history me-1"></i> Buka Riwayat Transaksi
                            </a>
                        </div>
                    </div>

                    <!-- TAMPILAN 3: LAYAR KEDALUWARSA / WAKTU HABIS (EXPIRED) -->
                    <div id="duitku-expired-view" class="d-none text-center py-4">
                        <div class="mb-3">
                            <i class="bi bi-hourglass-bottom text-danger" style="font-size: 4rem;"></i>
                        </div>
                        <h4 class="fw-bold text-danger mb-1">Batas Waktu Pembayaran Habis</h4>
                        <p class="text-muted small mb-4">
                            Waktu 10:00 menit untuk menyelesaikan pembayaran telah berakhir. Tagihan ini telah otomatis dibatalkan oleh sistem.
                        </p>
                        <button type="button" class="btn btn-primary px-4 fw-semibold" onclick="location.reload();">
                            <i class="bi bi-arrow-repeat me-1"></i> Buat Pesanan Baru
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL PEMBAYARAN MANUAL (QRIS / TRANSFER BANK DENGAN BUKTI) -->
    <?php if ($qrisConfigured || $bankConfigured): ?>
        <div class="modal fade" id="payment-modal" tabindex="-1" aria-labelledby="payment-modal-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content rounded-4 border-0 shadow-lg">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5 fw-bold" id="payment-modal-title">Instruksi Pembayaran Manual</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="d-flex justify-content-between align-items-center bg-light rounded-3 p-3 mb-3">
                            <span class="text-muted">Total Pembayaran:</span>
                            <strong class="fs-5 text-primary" id="payment-modal-total">Rp 0</strong>
                        </div>
                        <?php if ($dummyPayment): ?>
                            <div class="alert alert-warning small">
                                <strong>MODE SIMULASI LOKAL:</strong> Tidak ada uang sungguhan yang dibayarkan.
                            </div>
                        <?php endif; ?>
                        <?php if ($qrisConfigured): ?>
                            <div class="border rounded-3 p-3 mb-3 d-none text-center" id="qris-instructions">
                                <div class="small fw-semibold mb-2">Pindai QRIS Resmi Toko AkbarMart:</div>
                                <?php if ($dummyPayment || !$realQrisConfigured): ?>
                                    <div class="dummy-qr-mockup d-flex flex-column align-items-center justify-content-center text-secondary py-4">
                                        <i class="bi bi-qr-code fs-1"></i>
                                        <strong>QRIS SIMULASI</strong>
                                    </div>
                                <?php else: ?>
                                    <img src="<?= e($qrisRelativePath) ?>" alt="QRIS AkbarMart" class="img-fluid rounded-3" style="max-width: 240px">
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($bankConfigured): ?>
                            <div class="border rounded-3 p-3 mb-3 d-none" id="transfer-instructions">
                                <div class="small text-secondary">Bank Tujuan:</div>
                                <div class="fw-bold text-dark"><?= e($bankName ?: 'BANK DUMMY') ?> &bull; <?= e($bankAccountNumber ?: 'DUMMY-000') ?></div>
                                <div class="small text-muted">Atas Nama: <?= e($bankAccountName ?: 'AkbarMart') ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="proof-file" class="form-label fw-semibold small">Unggah Bukti Transfer / Resi Non-Tunai:</label>
                            <input class="form-control" type="file" id="proof-file" name="bukti_pembayaran"
                                   form="customer-order-form"
                                   accept="image/jpeg,image/png,image/webp,application/pdf">
                            <div class="form-text">Format JPG, PNG, WEBP, atau PDF (maksimal 5 MB).</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                        <button class="btn btn-primary" type="submit" name="payment_action" value="submit_proof" form="customer-order-form">
                            <i class="bi bi-upload me-1"></i>Kirim Bukti Pembayaran
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<script>
// Variabel state global untuk polling status & countdown timer
var activeOrderId = null;
var activeMerchantOrderId = null;
var statusPollingInterval = null;
var countdownInterval = null;
var remainingSeconds = 600; // Default 10:00 menit (600 detik)

function formatMMSS(seconds) {
    var m = Math.floor(seconds / 60);
    var s = seconds % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
}

function startCountdown(durationSeconds) {
    stopCountdown();
    remainingSeconds = durationSeconds || 600;
    var timerEl = document.getElementById('duitku-countdown-timer');
    if (timerEl) timerEl.textContent = formatMMSS(remainingSeconds);

    countdownInterval = setInterval(function () {
        remainingSeconds--;
        if (timerEl) {
            timerEl.textContent = formatMMSS(Math.max(0, remainingSeconds));
            if (remainingSeconds <= 60) {
                timerEl.className = 'badge bg-danger text-white fs-5 fw-bold font-monospace px-3 py-1 shadow-sm animate-pulse';
            }
        }
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

    // Cek langsung 1 kali
    checkPaymentStatus();

    // Jalankan polling setiap 3 detik
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

    fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
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
        console.warn('Polling cek status:', err);
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
        document.getElementById('success-display-trx').textContent = data.trx_code || ('TRX-' + data.order_id);
        document.getElementById('success-display-order-id').textContent = data.merchant_order_id || '-';
        document.getElementById('success-display-amount').textContent = data.total_belanja_formatted || 'Rp 0';
        document.getElementById('success-display-method').textContent = data.metode_pembayaran || 'DUITKU';
        document.getElementById('success-display-time').textContent = data.tanggal_pembayaran || '-';

        var receiptBtn = document.getElementById('success-btn-receipt');
        if (receiptBtn && data.receipt_url) {
            receiptBtn.href = data.receipt_url;
        }
    }

    // Kosongkan keranjang belanja pelanggan karena pembayaran telah lunas
    var cartItems = document.querySelectorAll('#customer-cart .cart-item');
    cartItems.forEach(function (ci) { ci.remove(); });
    var emptyEl = document.getElementById('cart-empty');
    if (emptyEl) emptyEl.classList.remove('d-none');
    var cartTotal = document.getElementById('cart-total');
    if (cartTotal) cartTotal.textContent = 'Rp 0';
    var cartSubtotal = document.getElementById('cart-subtotal');
    if (cartSubtotal) cartSubtotal.textContent = 'Rp 0';
    var cartDiscount = document.getElementById('cart-discount');
    if (cartDiscount) cartDiscount.textContent = '-Rp 0';
    var clearCartBtn = document.getElementById('btn-clear-cart');
    if (clearCartBtn) clearCartBtn.classList.add('d-none');
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

    // Beri tahu backend untuk memperbarui status transaksi menjadi dibatalkan
    if (activeOrderId) {
        fetch('cek_status_transaksi.php?action=expire&order_id=' + encodeURIComponent(activeOrderId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).catch(function () {});
    }
}

// Fungsi menampilkan modal rincian tagihan & simulasi Duitku
function showDuitkuDetails(orderId, reference, vaNumber, amountFormatted, methodName, paymentUrl, durationSeconds) {
    var pendingView = document.getElementById('duitku-pending-view');
    var successView = document.getElementById('duitku-success-view');
    var expiredView = document.getElementById('duitku-expired-view');

    if (pendingView) pendingView.classList.remove('d-none');
    if (successView) successView.classList.add('d-none');
    if (expiredView) expiredView.classList.add('d-none');

    document.getElementById('modal-display-order-id').textContent = orderId || '-';
    document.getElementById('modal-display-ref').textContent = reference || '-';
    document.getElementById('modal-display-amount').textContent = amountFormatted || '-';
    document.getElementById('modal-display-method').textContent = methodName || 'Duitku';

    var vaEl = document.getElementById('modal-display-va-number');
    if (vaEl) {
        vaEl.textContent = vaNumber || '-';
    }

    var vaBox = document.getElementById('box-va-number');
    var qrisBox = document.getElementById('box-qris-ref');

    // Tampilkan box VA jika memiliki nomor VA nyata dari respons Duitku
    if (vaNumber && vaNumber !== '-' && vaNumber.trim() !== '') {
        if (vaBox) vaBox.classList.remove('d-none');
        if (qrisBox) qrisBox.classList.add('d-none');
    } else {
        if (vaBox) vaBox.classList.add('d-none');
        if (qrisBox) qrisBox.classList.remove('d-none');
    }

    var portalLink = document.getElementById('btn-open-portal');
    if (portalLink) {
        if (paymentUrl) {
            portalLink.href = paymentUrl;
            portalLink.classList.remove('d-none');
        } else {
            portalLink.classList.add('d-none');
        }
    }

    // Jalankan timer hitung mundur (default 10:00 menit)
    startCountdown(durationSeconds || 600);

    bootstrap.Modal.getOrCreateInstance(document.getElementById('duitku-info-modal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
    var cart = document.getElementById('customer-cart');
    var totalElement = document.getElementById('cart-total');
    var modalTotalElement = document.getElementById('payment-modal-total');
    var emptyState = document.getElementById('cart-empty');
    var formatter = new Intl.NumberFormat('id-ID');

    var isMemberActive = <?= $isMemberActive ? 'true' : 'false' ?>;
    var clearCartBtn = document.getElementById('btn-clear-cart');
    var subtotalElement = document.getElementById('cart-subtotal');
    var discountElement = document.getElementById('cart-discount');

    // Filter Kategori & Pencarian Produk
    var searchInput = document.getElementById('product-search');
    var categoryPills = document.querySelectorAll('.category-pill');
    var productItems = document.querySelectorAll('.product-item');
    var noProductMatch = document.getElementById('no-product-match');
    var countBadge = document.getElementById('product-count-badge');
    var currentCategory = 'all';

    function filterProducts() {
        var query = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var visibleCount = 0;

        productItems.forEach(function (item) {
            var pName = item.dataset.productName || '';
            var pCat = item.dataset.category || '';
            var matchSearch = query === '' || pName.indexOf(query) !== -1;
            var matchCat = currentCategory === 'all' || pCat === currentCategory;

            if (matchSearch && matchCat) {
                item.style.display = '';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        if (noProductMatch) {
            noProductMatch.classList.toggle('d-none', visibleCount > 0);
        }
        if (countBadge) {
            countBadge.textContent = visibleCount + ' produk ditampilkan';
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', filterProducts);
    }

    categoryPills.forEach(function (pill) {
        pill.addEventListener('click', function () {
            categoryPills.forEach(function (p) {
                p.classList.remove('bg-primary', 'text-white');
                p.classList.add('bg-light', 'text-secondary');
            });
            pill.classList.remove('bg-light', 'text-secondary');
            pill.classList.add('bg-primary', 'text-white');

            currentCategory = pill.dataset.category || 'all';
            filterProducts();
        });
    });

    // Hentikan timer dan polling saat modal ditutup oleh user
    var duitkuModalEl = document.getElementById('duitku-info-modal');
    if (duitkuModalEl) {
        duitkuModalEl.addEventListener('hidden.bs.modal', function () {
            stopStatusPolling();
            stopCountdown();
        });
    }

    function updateTotal() {
        var subtotal = 0;
        var activeItems = 0;
        var items = Array.from(cart.querySelectorAll('.cart-item'));
        items.forEach(function (item) {
            var input = item.querySelector('.cart-qty');
            var quantity = Math.max(0, Math.min(parseInt(input.value, 10) || 0, parseInt(input.max, 10)));
            input.value = quantity;
            if (quantity > 0) {
                activeItems++;
                subtotal += (parseFloat(item.dataset.price) || 0) * quantity;
            }
        });

        var discount = 0;
        if (isMemberActive && subtotal > 0) {
            discount = Math.round(subtotal * 0.10);
        }
        var total = Math.max(0, subtotal - discount);

        if (subtotalElement) subtotalElement.textContent = 'Rp ' + formatter.format(subtotal);
        if (discountElement) discountElement.textContent = '-Rp ' + formatter.format(discount);
        totalElement.textContent = 'Rp ' + formatter.format(total);
        if (modalTotalElement) modalTotalElement.textContent = 'Rp ' + formatter.format(total);

        if (emptyState) emptyState.classList.toggle('d-none', activeItems > 0);
        if (clearCartBtn) clearCartBtn.classList.toggle('d-none', activeItems === 0);

        var smallTip = document.getElementById('qris-small-nominal-tip');
        if (smallTip) {
            smallTip.classList.toggle('d-none', total >= 10000 || total <= 0);
        }
    }

    // Tombol Bersihkan / Kosongkan Seluruh Keranjang
    if (clearCartBtn) {
        clearCartBtn.addEventListener('click', function () {
            if (window.confirm('Kosongkan semua barang dari keranjang belanja?')) {
                cart.querySelectorAll('.cart-item').forEach(function (el) { el.remove(); });
                updateTotal();
            }
        });
    }

    // Event Delegation: Hapus item satuan atau ubah kuantitas di keranjang
    cart.addEventListener('click', function (e) {
        var removeBtn = e.target.closest('.btn-remove-item');
        if (removeBtn) {
            var item = removeBtn.closest('.cart-item');
            if (item) {
                item.remove();
                updateTotal();
            }
        }
    });

    cart.addEventListener('input', function (e) {
        if (e.target.classList.contains('cart-qty')) {
            var val = parseInt(e.target.value, 10) || 0;
            if (val <= 0) {
                var item = e.target.closest('.cart-item');
                if (item) item.remove();
            }
            updateTotal();
        }
    });

    // Tambah Produk dari Katalog ke Keranjang
    document.querySelectorAll('[data-add-product]').forEach(function (button) {
        if (button.__hasClickListener) return;
        button.__hasClickListener = true;

        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            // Cegah multi-trigger / double-click cepat dalam 250ms
            var now = Date.now();
            if (button.__lastClickTime && (now - button.__lastClickTime) < 250) {
                return;
            }
            button.__lastClickTime = now;

            var id = button.dataset.id;
            var item = cart.querySelector('.cart-item[data-id="' + id + '"]');
            var unitPrice = parseFloat(button.dataset.price) || 0;

            if (!item) {
                item = document.createElement('div');
                item.className = 'cart-line cart-item p-2 mb-2 rounded-3 border bg-white shadow-xs d-flex justify-content-between align-items-center gap-2';
                item.dataset.id = id;
                item.dataset.price = unitPrice;

                var infoBox = document.createElement('div');
                infoBox.className = 'flex-grow-1 min-w-0';
                var nameEl = document.createElement('span');
                nameEl.className = 'small fw-bold text-slate-800 d-block text-truncate';
                nameEl.textContent = button.dataset.name;
                var priceEl = document.createElement('small');
                priceEl.className = 'text-muted';
                priceEl.textContent = 'Rp ' + formatter.format(unitPrice) + ' / pcs';
                infoBox.appendChild(nameEl);
                infoBox.appendChild(priceEl);

                var actionBox = document.createElement('div');
                actionBox.className = 'd-flex align-items-center gap-2 flex-shrink-0';

                var input = document.createElement('input');
                input.className = 'form-control form-control-sm text-center cart-qty px-1';
                input.style.width = '60px';
                input.type = 'number';
                input.name = 'qty[' + id + ']';
                input.min = '1';
                input.max = button.dataset.stock;
                input.value = '1';
                input.setAttribute('aria-label', 'Jumlah ' + button.dataset.name);

                var deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'btn btn-outline-danger btn-sm p-1 px-2 btn-remove-item';
                deleteBtn.title = 'Hapus barang ini dari keranjang';
                deleteBtn.innerHTML = '<i class="bi bi-trash"></i>';

                actionBox.appendChild(input);
                actionBox.appendChild(deleteBtn);

                item.appendChild(infoBox);
                item.appendChild(actionBox);
                cart.appendChild(item);
            } else {
                var quantity = item.querySelector('.cart-qty');
                if (parseInt(quantity.value, 10) < parseInt(quantity.max, 10)) {
                    quantity.value = parseInt(quantity.value, 10) + 1;
                }
            }
            updateTotal();
        });
    });

    // Helper salin teks ke clipboard
    function copyText(elementId, btnElement, label) {
        var text = document.getElementById(elementId).textContent;
        if (text && text !== '-') {
            navigator.clipboard.writeText(text).then(function () {
                var oldText = btnElement.innerHTML;
                btnElement.innerHTML = '<i class="bi bi-check2 text-success me-1"></i>Tersalin!';
                setTimeout(function () { btnElement.innerHTML = oldText; }, 1800);
            });
        }
    }

    var btnCopyVa = document.getElementById('btn-copy-va');
    if (btnCopyVa) {
        btnCopyVa.addEventListener('click', function () {
            copyText('modal-display-va-number', btnCopyVa, 'Nomor VA');
        });
    }

    var btnCopyRef = document.getElementById('btn-copy-ref');
    if (btnCopyRef) {
        btnCopyRef.addEventListener('click', function () {
            copyText('modal-display-ref', btnCopyRef, 'Referensi');
        });
    }

    var btnCopyOrder = document.getElementById('btn-copy-order');
    if (btnCopyOrder) {
        btnCopyOrder.addEventListener('click', function () {
            copyText('modal-display-order-id', btnCopyOrder, 'Order ID');
        });
    }

    // Eksekusi Pembayaran Duitku via Fetch API
    var payDuitkuBtn = document.getElementById('pay-duitku-button');
    if (payDuitkuBtn) {
        payDuitkuBtn.addEventListener('click', function () {
            var cartLines = Array.from(cart.querySelectorAll('.cart-item'));
            var formData = new FormData();
            formData.append('source', 'customer');
            var csrfInput = document.querySelector('input[name="csrf_token"]');
            if (csrfInput) {
                formData.append('csrf_token', csrfInput.value);
            }

            var methodSelector = document.getElementById('duitku-payment-method');
            if (methodSelector) {
                formData.append('payment_method', methodSelector.value);
            }

            var totalQty = 0;
            cartLines.forEach(function (item) {
                var input = item.querySelector('.cart-qty');
                var qty = parseInt(input.value, 10) || 0;
                if (qty > 0) {
                    totalQty += qty;
                    formData.append('qty[' + item.dataset.id + ']', qty);
                }
            });

            if (totalQty === 0) {
                window.alert('Keranjang belanja Anda masih kosong. Silakan pilih minimal 1 produk dari katalog.');
                return;
            }

            payDuitkuBtn.disabled = true;
            var originalBtnHtml = payDuitkuBtn.innerHTML;
            payDuitkuBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Menghubungkan ke Duitku Sandbox...';

            fetch('proses_duitku.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { status: response.status, data: data };
                });
            })
            .then(function (res) {
                payDuitkuBtn.disabled = false;
                payDuitkuBtn.innerHTML = originalBtnHtml;

                if (res.data.success) {
                    showDuitkuDetails(
                        res.data.merchant_order_id,
                        res.data.reference,
                        res.data.va_number,
                        res.data.total_belanja_formatted,
                        res.data.payment_method_name,
                        res.data.payment_url,
                        res.data.expiry_seconds || 600
                    );

                    // Mulai polling deteksi otomatis status transaksi setiap 3 detik
                    startStatusPolling(res.data.order_id, res.data.merchant_order_id);

                    var demoBtn = document.getElementById('btn-open-demo');
                    if (demoBtn && res.data.demo_simulator_url) {
                        demoBtn.href = res.data.demo_simulator_url;
                    }
                } else {
                    if (res.data.suggest_qris) {
                        var changeToQris = window.confirm(
                            (res.data.message || 'Pembayaran nominal kecil tidak didukung oleh kanal VA.') +
                            '\n\nApakah Anda ingin beralih ke QRIS (ShopeePay) yang mendukung nominal di bawah Rp 10.000?'
                        );
                        if (changeToQris && methodSelector) {
                            methodSelector.value = 'SP';
                            payDuitkuBtn.click();
                            return;
                        }
                    } else {
                        window.alert(res.data.message || 'Gagal memproses inisiasi pembayaran Duitku.');
                    }
                }
            })
            .catch(function (error) {
                payDuitkuBtn.disabled = false;
                payDuitkuBtn.innerHTML = originalBtnHtml;
                console.error('Fetch Duitku error:', error);
                window.alert('Gagal terhubung ke server Duitku Sandbox. Periksa koneksi jaringan.');
            });
        });
    }

    // Handle Pembayaran Manual
    var manualCheckoutBtn = document.getElementById('manual-checkout-button');
    var qrisRadio = document.getElementById('payment-qris');
    var transferRadio = document.getElementById('payment-transfer');

    function updateManualInstructions() {
        var qrisInfo = document.getElementById('qris-instructions');
        var transferInfo = document.getElementById('transfer-instructions');
        if (qrisInfo) qrisInfo.classList.toggle('d-none', !qrisRadio || !qrisRadio.checked);
        if (transferInfo) transferInfo.classList.toggle('d-none', !transferRadio || !transferRadio.checked);
    }
    if (qrisRadio) qrisRadio.addEventListener('change', updateManualInstructions);
    if (transferRadio) transferRadio.addEventListener('change', updateManualInstructions);
    updateManualInstructions();

    if (manualCheckoutBtn) {
        manualCheckoutBtn.addEventListener('click', function () {
            var hasItems = Array.from(cart.querySelectorAll('.cart-qty')).some(function (input) {
                return parseInt(input.value, 10) > 0;
            });
            if (!hasItems) {
                window.alert('Pilih minimal satu produk untuk melakukan checkout.');
                return;
            }
            if ((!qrisRadio || !qrisRadio.checked) && (!transferRadio || !transferRadio.checked)) {
                if (qrisRadio) qrisRadio.checked = true;
                updateManualInstructions();
            }
            var modalEl = document.getElementById('payment-modal');
            if (modalEl) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
