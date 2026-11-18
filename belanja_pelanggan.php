<?php
require_once __DIR__ . '/check_session.php';
require_role('pelanggan');

$customerId = (int) $_SESSION['id_pelanggan'];
$userId = (int) $_SESSION['id_user'];
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
$canPay = $qrisConfigured || $bankConfigured;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'checkout';

    if ($action === 'change_password') {
        $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
        $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
        $passwordQuery = $pdo->prepare('SELECT password FROM tbl_user WHERE id_user = ? AND role = \'pelanggan\' LIMIT 1');
        $passwordQuery->execute([$userId]);
        $currentHash = $passwordQuery->fetchColumn();

        if (
            !is_string($currentHash)
            || !password_verify($currentPassword, $currentHash)
            || strlen($newPassword) < 12
            || strlen($newPassword) > 72
            || !hash_equals($newPassword, $confirmPassword)
        ) {
            flash('danger', 'Password lama tidak sesuai atau password baru tidak valid (minimal 12 karakter dan harus sama).');
        } else {
            $passwordUpdate = $pdo->prepare('UPDATE tbl_user SET password = ? WHERE id_user = ?');
            $passwordUpdate->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
            flash('success', 'Password akun berhasil diperbarui.');
        }
        redirect('belanja_pelanggan.php');
    }

    $quantitiesInput = $_POST['qty'] ?? [];
    if (!is_array($quantitiesInput) || count($quantitiesInput) > 100) {
        flash('danger', 'Format keranjang tidak valid.');
        redirect('belanja_pelanggan.php');
    }

    $quantities = [];
    foreach ($quantitiesInput as $productId => $quantityInput) {
        $productId = valid_int($productId, 1);
        $quantity = valid_int($quantityInput, 0);
        if ($productId === null || $quantity === null || $quantity > 99) {
            flash('danger', 'Produk atau jumlah pada keranjang tidak valid.');
            redirect('belanja_pelanggan.php');
        }
        if ($quantity === 0) {
            continue;
        }
        $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
        if ($quantities[$productId] > 99) {
            flash('danger', 'Jumlah setiap produk maksimal 99.');
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
            ? 'Keranjang belanja masih kosong.'
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
            flash('danger', 'Unggah bukti pembayaran sebelum mengirim pesanan.');
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
                : 'Bukti pembayaran harus berupa JPG, PNG, WEBP, atau PDF.');
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
        if ($memberQuery->fetchColumn() !== 'aktif') {
            throw new DomainException('Akun member tidak aktif. Hubungi admin AkbarMart.');
        }

        $productIds = array_keys($quantities);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $productQuery = $pdo->prepare(
            "SELECT id_produk, nama_produk, harga, stok
             FROM tbl_produk WHERE id_produk IN ({$placeholders})
             ORDER BY id_produk FOR UPDATE"
        );
        $productQuery->execute($productIds);
        $products = $productQuery->fetchAll();
        if (count($products) !== count($productIds)) {
            throw new DomainException('Satu atau lebih produk sudah tidak tersedia.');
        }

        $subtotal = 0.0;
        foreach ($products as $product) {
            $id = (int) $product['id_produk'];
            if ($quantities[$id] > (int) $product['stok']) {
                throw new DomainException('Stok "' . $product['nama_produk'] . '" tidak mencukupi. Perbarui keranjang dan coba lagi.');
            }
            $subtotal += (float) $product['harga'] * $quantities[$id];
        }

        $discount = 0;
        $total = $subtotal;
        $paymentStatus = $instantSuccess ? 'lunas' : 'menunggu_verifikasi';
        $paidAmount = $instantSuccess ? $total : 0;
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
        foreach ($products as $product) {
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
            foreach ($products as $product) {
                $id = (int) $product['id_produk'];
                $quantity = $quantities[$id];
                $updateStock->execute([$quantity, $id, $quantity]);
                if ($updateStock->rowCount() !== 1) {
                    throw new DomainException('Stok berubah saat simulasi pembayaran. Tidak ada perubahan yang disimpan; coba lagi.');
                }
            }
        }
        $pdo->commit();
        flash(
            'success',
            $instantSuccess
                ? 'Simulasi pembayaran berhasil. Transaksi ditandai lunas dan stok dipotong.'
                : ($dummyPayment
                    ? 'Bukti simulasi dikirim untuk verifikasi admin. Stok belum dipotong.'
                    : 'Pesanan berhasil dikirim dan menunggu verifikasi pembayaran. Stok belum dipotong.')
        );
        redirect('belanja_pelanggan.php?order=' . $transactionId);
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($proofFilename !== null && is_file($proofDirectory . '/' . $proofFilename) && !unlink($proofDirectory . '/' . $proofFilename)) {
            error_log('Gagal menghapus bukti pembayaran pesanan yang dibatalkan: ' . $proofFilename);
        }
        flash('warning', $exception->getMessage());
        redirect('belanja_pelanggan.php');
    } catch (PDOException $exception) {
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

$products = $pdo->query(
    'SELECT id_produk, nama_produk, gambar, harga, stok
     FROM tbl_produk WHERE stok > 0 ORDER BY nama_produk'
)->fetchAll();
$ordersQuery = $pdo->prepare(
    'SELECT id_transaksi, tanggal, total_belanja, metode_pembayaran, status_pembayaran,
            simulasi_pembayaran, catatan_pembayaran
     FROM tbl_transaksi
     WHERE id_user = ? AND id_pelanggan = ?
     ORDER BY tanggal DESC, id_transaksi DESC LIMIT 10'
);
$ordersQuery->execute([$userId, $customerId]);
$orders = $ordersQuery->fetchAll();
$customerQuery = $pdo->prepare('SELECT nama_lengkap FROM tbl_user WHERE id_user = ?');
$customerQuery->execute([$userId]);
$customerName = (string) $customerQuery->fetchColumn();
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Belanja Mandiri | AkbarMart</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fb; color: #172033; }
        .store-nav { background: #fff; border-bottom: 1px solid #e5e7eb; }
        .store-nav .container,
        main.container { width: 100%; max-width: 1140px; }
        .product-card { height: 100%; }
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
            gap: 1rem;
        }
        .product-grid > * { min-width: 0; }
        .product-image {
            display: block;
            width: 100%;
            height: 180px;
            padding: 12px;
            object-fit: contain;
            object-position: center;
            background: #fff;
        }
        .cart-line { border-bottom: 1px solid #e5e7eb; padding: 12px 0; }
        .cart-line:last-child { border-bottom: 0; }
        .cart-qty { max-width: 90px; }
        .summary-total { font-size: 1.15rem; }
        .dummy-qr-mockup {
            min-height: 210px;
            max-width: 240px;
            margin: auto;
            border: 1px dashed #94a3b8;
            border-radius: 12px;
            background: repeating-linear-gradient(45deg, #f8fafc, #f8fafc 8px, #eef2f7 8px, #eef2f7 16px);
        }
    </style>
</head>
<body>
<nav class="navbar store-nav">
    <div class="container d-flex flex-wrap gap-3 justify-content-between">
        <a class="navbar-brand fw-bold text-primary" href="belanja_pelanggan.php"><i class="bi bi-shop me-2"></i>AkbarMart</a>
        <div class="d-flex align-items-center gap-2">
            <span class="text-secondary small">Halo, <?= e($customerName) ?></span>
            <a class="btn btn-outline-primary btn-sm" href="#akun"><i class="bi bi-person-gear me-1"></i>Akun</a>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Logout</button>
            </form>
        </div>
    </div>
</nav>

<main class="container py-4">
    <div class="mb-4">
        <div class="text-primary fw-semibold small">Jl. Gajah Mada No. 12, Ponorogo</div>
        <h1 class="h3 fw-bold mt-1">Belanja Mandiri</h1>
        <?php if ($dummyPayment): ?>
            <p class="text-secondary mb-0">Pilih produk dan metode non-tunai. Mode simulasi menyediakan unggah bukti tiruan atau pembayaran instan untuk pengujian.</p>
        <?php else: ?>
            <p class="text-secondary mb-0">Pilih produk, bayar dengan QRIS atau transfer, unggah bukti, lalu tunggu verifikasi admin.</p>
        <?php endif; ?>
    </div>

    <?php if ($flashMessage): ?>
        <div class="alert alert-<?= e($flashMessage['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($flashMessage['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4 align-items-start">
        <section class="col-lg-7" aria-labelledby="catalog-title">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 fw-bold mb-0" id="catalog-title">Katalog Produk</h2>
                <span class="badge text-bg-light"><?= count($products) ?> produk tersedia</span>
            </div>
            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <article class="card product-card border-0 shadow-sm">
                        <?php
                        $imageName = safe_product_image_name($product['gambar'] ?? null);
                        $imagePath = $imageName !== null ? 'uploads/produk/' . $imageName : null;
                        ?>
                        <?php if ($imagePath !== null && is_file(__DIR__ . '/' . $imagePath)): ?>
                            <img class="card-img-top product-image" src="<?= e($imagePath) ?>" alt="<?= e($product['nama_produk']) ?>" loading="lazy">
                        <?php else: ?>
                            <div class="product-image d-flex align-items-center justify-content-center text-secondary"><i class="bi bi-box-seam fs-1"></i></div>
                        <?php endif; ?>
                        <div class="card-body d-flex flex-column">
                            <h3 class="h6 fw-semibold"><?= e($product['nama_produk']) ?></h3>
                            <div class="text-primary fw-bold mb-1"><?= rupiah($product['harga']) ?></div>
                            <small class="text-secondary mb-3">Stok tersedia: <?= (int) $product['stok'] ?></small>
                            <button class="btn btn-outline-primary mt-auto" type="button" data-add-product
                                    data-id="<?= (int) $product['id_produk'] ?>"
                                    data-name="<?= e($product['nama_produk']) ?>"
                                    data-price="<?= e($product['harga']) ?>"
                                    data-stock="<?= (int) $product['stok'] ?>">
                                <i class="bi bi-plus-lg me-1"></i>Tambah ke keranjang
                            </button>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$products): ?>
                    <div class="col-12"><div class="alert alert-info">Belum ada produk dengan stok tersedia.</div></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="col-lg-5" aria-labelledby="cart-title">
            <form method="post" enctype="multipart/form-data" id="customer-order-form" class="card border-0 shadow-sm">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="checkout">
                <div class="card-header bg-white py-3">
                    <h2 class="h5 fw-bold mb-0" id="cart-title"><i class="bi bi-basket2 me-2 text-primary"></i>Keranjang</h2>
                </div>
                <div class="card-body">
                    <div id="customer-cart">
                        <p class="text-secondary small mb-0" id="cart-empty">Keranjang masih kosong.</p>
                    </div>
                    <div class="summary-total border-top pt-3 mt-3 d-flex justify-content-between">
                        <span>Total bayar</span><strong id="cart-total">Rp 0</strong>
                    </div>
                    <div class="small text-secondary mt-2" id="member-discount-note">Diskon RFID 10% hanya berlaku di kasir setelah kartu aktif ditap.</div>

                    <hr>
                    <h3 class="h6 fw-bold">Pembayaran non-tunai</h3>
                    <?php if (!$canPay): ?>
                        <div class="alert alert-warning small">
                            Metode pembayaran belum dikonfigurasi. Admin perlu mengisi <code>config/pembayaran.php</code> sebelum checkout tersedia.
                        </div>
                    <?php else: ?>
                        <?php if ($dummyPayment): ?>
                            <div class="alert alert-warning small" role="alert">
                                <strong>MODE SIMULASI:</strong> tidak ada uang sungguhan yang dibayarkan. Pesanan simulasi ditandai di laporan.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info small" role="alert">
                                Pembayaran dilakukan secara manual. Pastikan informasi rekening/QRIS toko sudah benar.
                            </div>
                        <?php endif; ?>
                        <?php if ($qrisConfigured): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="metode_pembayaran" id="payment-qris" value="qris" required>
                                <label class="form-check-label" for="payment-qris">QRIS</label>
                            </div>
                        <?php endif; ?>
                        <?php if ($bankConfigured): ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="radio" name="metode_pembayaran" id="payment-transfer" value="transfer" required>
                                <label class="form-check-label" for="payment-transfer">Transfer Bank</label>
                            </div>
                        <?php endif; ?>
                        <button class="btn btn-primary w-100" type="button" id="checkout-button">
                            <i class="bi bi-credit-card me-1"></i>Checkout / Bayar Non-Tunai
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    </div>

    <?php if ($canPay): ?>
        <div class="modal fade" id="payment-modal" tabindex="-1" aria-labelledby="payment-modal-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="payment-modal-title">Instruksi Pembayaran</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex justify-content-between align-items-center border rounded p-3 mb-3">
                            <span>Total nominal</span>
                            <strong class="fs-5 text-primary" id="payment-modal-total">Rp 0</strong>
                        </div>
                        <?php if ($dummyPayment): ?>
                            <div class="alert alert-danger small">
                                <strong>SIMULASI / BUKAN PEMBAYARAN NYATA.</strong> Jangan transfer uang atau memindai gambar ini. Data transaksi akan ditandai sebagai dummy.
                            </div>
                            <?php if ($qrisConfigured): ?>
                                <div class="border rounded p-3 mb-3 d-none" id="qris-instructions">
                                    <div class="small fw-semibold mb-2">QRIS mockup (tidak dapat dipindai)</div>
                                    <div class="dummy-qr-mockup d-flex flex-column align-items-center justify-content-center text-secondary">
                                        <i class="bi bi-qr-code fs-1"></i>
                                        <strong>QRIS DUMMY</strong>
                                        <small>Tidak ada pembayaran sungguhan</small>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ($bankConfigured): ?>
                                <div class="border rounded p-3 mb-3 d-none" id="transfer-instructions">
                                    <div class="small text-secondary">Informasi transfer dummy</div>
                                    <div class="fw-semibold">Bank: BANK DUMMY</div>
                                    <div>Nomor rekening: <code>DUMMY-0000</code></div>
                                    <div class="small">Atas nama AkbarMart (simulasi)</div>
                                    <div class="small text-danger mt-2">Nomor ini bukan rekening dan tidak dapat digunakan untuk transfer.</div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($qrisConfigured): ?>
                                <div class="border rounded p-3 mb-3 d-none" id="qris-instructions">
                                    <div class="small fw-semibold mb-2">Pindai QRIS resmi AkbarMart</div>
                                    <img src="<?= e($qrisRelativePath) ?>" alt="QRIS AkbarMart" class="img-fluid" style="max-width: 240px">
                                </div>
                            <?php endif; ?>
                            <?php if ($bankConfigured): ?>
                                <div class="border rounded p-3 mb-3 d-none" id="transfer-instructions">
                                    <div class="small text-secondary">Bank tujuan</div>
                                    <div class="fw-semibold"><?= e($bankName) ?> · <?= e($bankAccountNumber) ?></div>
                                    <div class="small">Atas nama <?= e($bankAccountName) ?></div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label for="proof-file" class="form-label"><?= $dummyPayment ? 'Bukti simulasi (gambar)' : 'Bukti pembayaran' ?></label>
                            <input class="form-control" type="file" id="proof-file" name="bukti_pembayaran"
                                   form="customer-order-form"
                                   accept="<?= $dummyPayment ? 'image/jpeg,image/png,image/webp,image/gif' : 'image/jpeg,image/png,image/webp,application/pdf' ?>"
                                   required>
                            <div class="form-text">
                                <?= $dummyPayment ? 'Gambar JPG, PNG, WEBP, atau GIF; maksimal 5 MB.' : 'JPG, PNG, WEBP, atau PDF; maksimal 5 MB.' ?>
                            </div>
                        </div>
                        <?php if ($dummyPayment): ?>
                            <div class="alert alert-info small mb-0">
                                Unggah gambar tiruan untuk menunggu persetujuan admin, atau gunakan tombol auto-success untuk menguji pemotongan stok.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info small mb-0">
                                Pesanan menunggu verifikasi admin. Stok baru dipotong setelah pembayaran diperiksa dan disetujui.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer flex-wrap">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                        <button class="btn btn-outline-primary" type="submit" name="payment_action" value="submit_proof"
                                form="customer-order-form">
                            <i class="bi bi-upload me-1"></i><?= $dummyPayment ? 'Simulasi Unggah Bukti Bayar' : 'Kirim Bukti Pembayaran' ?>
                        </button>
                        <?php if ($dummyPayment): ?>
                            <button class="btn btn-success" type="submit" name="payment_action" value="simulate_success"
                                    form="customer-order-form" formnovalidate>
                                <i class="bi bi-check2-circle me-1"></i>Simulasi Bayar Instan (Auto-Success)
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <section class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white py-3"><h2 class="h5 fw-bold mb-0">Pesanan Saya</h2></div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Transaksi</th><th>Tanggal</th><th>Metode</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if (!$orders): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">Belum ada pesanan.</td></tr>
                <?php else: foreach ($orders as $order): ?>
                    <tr>
                        <td>TRX-<?= str_pad((string) $order['id_transaksi'], 6, '0', STR_PAD_LEFT) ?></td>
                        <td><?= e(date('d/m/Y H:i', strtotime($order['tanggal']))) ?></td>
                        <td><?= e(strtoupper($order['metode_pembayaran'])) ?></td>
                        <td><?= rupiah($order['total_belanja']) ?></td>
                        <td>
                            <?php if ((int) $order['simulasi_pembayaran'] === 1): ?>
                                <?php if ($order['status_pembayaran'] === 'lunas'): ?>
                                    <span class="badge text-bg-warning">Berhasil (Simulasi)</span>
                                <?php elseif ($order['status_pembayaran'] === 'menunggu_verifikasi'): ?>
                                    <span class="badge text-bg-warning">Menunggu verifikasi (Simulasi)</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Dibatalkan (Simulasi)</span>
                                <?php endif; ?>
                            <?php elseif ($order['status_pembayaran'] === 'lunas'): ?>
                                <span class="badge text-bg-success">Lunas</span>
                            <?php elseif ($order['status_pembayaran'] === 'menunggu_verifikasi'): ?>
                                <span class="badge text-bg-warning">Menunggu verifikasi</span>
                            <?php else: ?>
                                <span class="badge text-bg-danger">Dibatalkan</span>
                                <?php if ($order['catatan_pembayaran']): ?><small class="d-block text-secondary"><?= e($order['catatan_pembayaran']) ?></small><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($order['status_pembayaran'] === 'lunas'): ?>
                                <a class="btn btn-sm btn-outline-primary" href="cetak_struk.php?id=<?= (int) $order['id_transaksi'] ?>" target="_blank" rel="noopener">Struk</a>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card border-0 shadow-sm mt-4" id="akun">
        <div class="card-body">
            <h2 class="h5 fw-bold">Keamanan Akun</h2>
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="change_password">
                <div class="col-12 col-md-3">
                    <label class="form-label" for="current-password">Password lama</label>
                    <input class="form-control" id="current-password" name="current_password" type="password" required autocomplete="current-password">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="new-password">Password baru</label>
                    <input class="form-control" id="new-password" name="new_password" type="password" minlength="12" maxlength="72" required autocomplete="new-password">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="confirm-password">Ulangi password baru</label>
                    <input class="form-control" id="confirm-password" name="confirm_password" type="password" minlength="12" maxlength="72" required autocomplete="new-password">
                </div>
                <div class="col-12 col-md-3"><button class="btn btn-outline-primary w-100" type="submit">Ubah password</button></div>
            </form>
        </div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var cart = document.getElementById('customer-cart');
    var totalElement = document.getElementById('cart-total');
    var modalTotalElement = document.getElementById('payment-modal-total');
    var emptyState = document.getElementById('cart-empty');
    var formatter = new Intl.NumberFormat('id-ID');

    function updateTotal() {
        var subtotal = 0;
        var activeItems = 0;
        var items = Array.from(cart.querySelectorAll('.cart-item'));
        items.forEach(function (item) {
            var input = item.querySelector('.cart-qty');
            var quantity = Math.max(0, Math.min(parseInt(input.value, 10) || 0, parseInt(input.max, 10)));
            input.value = quantity;
            if (quantity > 0) activeItems++;
            subtotal += (parseFloat(item.dataset.price) || 0) * quantity;
        });
        var formattedTotal = 'Rp ' + formatter.format(subtotal);
        totalElement.textContent = formattedTotal;
        if (modalTotalElement) modalTotalElement.textContent = formattedTotal;
        if (emptyState) emptyState.classList.toggle('d-none', activeItems > 0);
    }

    document.querySelectorAll('[data-add-product]').forEach(function (button) {
        button.addEventListener('click', function () {
            var id = button.dataset.id;
            var item = cart.querySelector('.cart-item[data-id="' + id + '"]');
            if (!item) {
                item = document.createElement('div');
                item.className = 'cart-line cart-item d-flex justify-content-between align-items-center gap-2';
                item.dataset.id = id;
                item.dataset.price = button.dataset.price;
                var name = document.createElement('span');
                name.className = 'small fw-semibold';
                name.textContent = button.dataset.name;
                var input = document.createElement('input');
                input.className = 'form-control form-control-sm cart-qty';
                input.type = 'number';
                input.name = 'qty[' + id + ']';
                input.min = '0';
                input.max = button.dataset.stock;
                input.value = '1';
                input.setAttribute('aria-label', 'Jumlah ' + button.dataset.name);
                item.appendChild(name);
                item.appendChild(input);
                cart.appendChild(item);
                input.addEventListener('input', updateTotal);
            } else {
                var quantity = item.querySelector('.cart-qty');
                if (parseInt(quantity.value, 10) < parseInt(quantity.max, 10)) quantity.value = parseInt(quantity.value, 10) + 1;
            }
            updateTotal();
        });
    });

    var qris = document.getElementById('payment-qris');
    var transfer = document.getElementById('payment-transfer');
    function updatePaymentInstructions() {
        var qrisInfo = document.getElementById('qris-instructions');
        var transferInfo = document.getElementById('transfer-instructions');
        if (qrisInfo) qrisInfo.classList.toggle('d-none', !qris || !qris.checked);
        if (transferInfo) transferInfo.classList.toggle('d-none', !transfer || !transfer.checked);
    }
    if (qris) qris.addEventListener('change', updatePaymentInstructions);
    if (transfer) transfer.addEventListener('change', updatePaymentInstructions);
    updatePaymentInstructions();

    var form = document.getElementById('customer-order-form');
    var checkoutButton = document.getElementById('checkout-button');
    if (checkoutButton) {
        checkoutButton.addEventListener('click', function () {
            var hasItems = Array.from(cart.querySelectorAll('.cart-qty')).some(function (input) {
                return parseInt(input.value, 10) > 0;
            });
            if (!hasItems) {
                window.alert('Pilih minimal satu produk.');
                return;
            }
            if ((!qris || !qris.checked) && (!transfer || !transfer.checked)) {
                window.alert('Pilih metode pembayaran non-tunai.');
                return;
            }
            bootstrap.Modal.getOrCreateInstance(document.getElementById('payment-modal')).show();
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            var hasItems = Array.from(cart.querySelectorAll('.cart-qty')).some(function (input) {
                return parseInt(input.value, 10) > 0;
            });
            if (!hasItems) {
                event.preventDefault();
                window.alert('Pilih minimal satu produk.');
            }
        });
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
