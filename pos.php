<?php
require_once __DIR__ . '/check_session.php';
require_role(['admin', 'kasir']);

// Endpoint lookup barcode produk via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && array_key_exists('barcode_lookup', $_GET)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $barcode = trim((string) $_GET['barcode_lookup']);

    if ($barcode === '' || mb_strlen($barcode) > 50 || preg_match('/[\x00-\x1F\x7F]/', $barcode)) {
        http_response_code(400);
        echo json_encode(['message' => 'Kode barcode tidak valid.']);
        exit;
    }

    try {
        $barcodeQuery = $pdo->prepare('SELECT id_produk, nama_produk, harga, stok FROM tbl_produk WHERE kode_barcode = ? LIMIT 1');
        $barcodeQuery->execute([$barcode]);
        $product = $barcodeQuery->fetch();
    } catch (PDOException $exception) {
        error_log('Pencarian barcode gagal: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['message' => 'Pencarian barcode gagal. Silakan coba kembali.']);
        exit;
    }

    if (!$product) {
        http_response_code(404);
        echo json_encode(['message' => 'Produk dengan barcode tersebut tidak ditemukan.']);
        exit;
    }

    if ((int) $product['stok'] < 1) {
        http_response_code(409);
        echo json_encode(['message' => 'Stok produk habis. Hubungi Admin untuk memperbarui stok.']);
        exit;
    }

    echo json_encode([
        'product' => [
            'id' => (int) $product['id_produk'],
            'name' => $product['nama_produk'],
            'price' => (float) $product['harga'],
            'stock' => (int) $product['stok'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Handler Checkout Pembayaran TUNAI (Khusus Role Admin & Kasir)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Pencegahan: Jangan pernah proses pembayaran Duitku lewat handler form tunai
    $paymentType = trim((string) ($_POST['pos_payment_type'] ?? 'cash'));
    if ($paymentType === 'duitku') {
        flash('warning', 'Pembayaran Duitku Non-Tunai diproses secara otomatis melalui Gateway Duitku Sandbox.');
        redirect('pos.php');
    }

    $postedQuantities = $_POST['qty'] ?? [];
    if (!is_array($postedQuantities)) {
        flash('danger', 'Format keranjang tidak valid.');
        redirect('pos.php');
    }
    $quantities = [];
    foreach ($postedQuantities as $productId => $quantityValue) {
        $id = valid_int($productId, 1);
        $quantity = valid_int($quantityValue, 0);
        if ($id === null || $quantity === null) {
            flash('danger', 'Jumlah produk pada keranjang tidak valid.');
            redirect('pos.php');
        }
        if ($quantity > 0) {
            $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
        }
    }
    if (!$quantities) {
        flash('warning', 'Keranjang masih kosong. Silakan pilih produk terlebih dahulu.');
        redirect('pos.php');
    }

    $discount = 0;
    $payment = valid_int($_POST['uang_bayar'] ?? null);
    $cashier = (string) $_SESSION['nama'];
    $customer = ($_POST['id_pelanggan'] ?? '') === '' ? null : valid_int($_POST['id_pelanggan'], 1);
    $memberCardInput = $_POST['id_card_rfid'] ?? '';
    $memberCard = is_string($memberCardInput) ? trim($memberCardInput) : '';
    $memberCardProvided = !is_string($memberCardInput) || $memberCard !== '';
    if ($payment === null || $cashier === '' || mb_strlen($cashier) > 100 || (($_POST['id_pelanggan'] ?? '') !== '' && $customer === null)) {
        flash('danger', 'Diskon, pembayaran, kasir, atau pelanggan tidak valid.');
        redirect('pos.php');
    }

    try {
        $pdo->beginTransaction();
        $ids = array_keys($quantities);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id_produk, nama_produk, harga, stok FROM tbl_produk WHERE id_produk IN ({$placeholders}) FOR UPDATE");
        $stmt->execute($ids);
        $products = $stmt->fetchAll();
        if (count($products) !== count($ids)) {
            throw new DomainException('Satu atau lebih produk tidak ditemukan dalam database.');
        }
        $subtotal = 0.0;
        foreach ($products as $product) {
            $id = (int) $product['id_produk'];
            if ($quantities[$id] > (int) $product['stok']) {
                throw new DomainException('Stok "' . $product['nama_produk'] . '" tidak mencukupi. Stok saat ini: ' . $product['stok'] . ' pcs.');
            }
            $subtotal += (float) $product['harga'] * $quantities[$id];
        }
        if ($memberCard !== '' && mb_strlen($memberCard) <= 50 && !preg_match('/[\x00-\x1F\x7F]/', $memberCard)) {
            $memberQuery = $pdo->prepare(
                "SELECT id_pelanggan FROM tbl_pelanggan
                 WHERE id_card_rfid = ? AND status_member = 'aktif'
                 LIMIT 1 FOR UPDATE"
            );
            $memberQuery->execute([$memberCard]);
            $verifiedMember = $memberQuery->fetch();
            if ($verifiedMember) {
                $customer = (int) $verifiedMember['id_pelanggan'];
                $discount = (int) round($subtotal * 0.10, 0, PHP_ROUND_HALF_UP);
            } else {
                $customer = null;
            }
        } elseif ($memberCardProvided) {
            $customer = null;
        }
        if ($customer !== null) {
            $customerCheck = $pdo->prepare('SELECT COUNT(*) FROM tbl_pelanggan WHERE id_pelanggan = ?');
            $customerCheck->execute([$customer]);
            if (!(int) $customerCheck->fetchColumn()) {
                throw new DomainException('Pelanggan yang dipilih tidak terdaftar.');
            }
        }
        if ($discount > $subtotal) {
            throw new DomainException('Diskon tidak boleh lebih besar dari subtotal belanja.');
        }
        $total = $subtotal - $discount;
        if ($payment < $total) {
            throw new DomainException('Nominal pembayaran kurang dari total belanja yang harus dibayar.');
        }
        $change = $payment - $total;
        $insertTransaction = $pdo->prepare(
            "INSERT INTO tbl_transaksi
             (id_user, id_pelanggan, total_belanja, diskon, uang_bayar, kembalian, nama_kasir, metode_pembayaran, status_pembayaran, tanggal_pembayaran)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'tunai', 'lunas', CURRENT_TIMESTAMP)"
        );
        $insertTransaction->execute([(int) $_SESSION['id_user'], $customer, $total, $discount, $payment, $change, $cashier]);
        $transactionId = (int) $pdo->lastInsertId();
        $detailColumns = $pdo->query('SHOW COLUMNS FROM tbl_detail_transaksi')->fetchAll(PDO::FETCH_COLUMN, 0);
        $hasLegacyColumns = in_array('nama_produk', $detailColumns, true) && in_array('harga', $detailColumns, true);
        if ($hasLegacyColumns) {
            $insertDetail = $pdo->prepare('INSERT INTO tbl_detail_transaksi (id_transaksi, id_produk, nama_produk, jumlah, harga, harga_satuan) VALUES (?, ?, ?, ?, ?, ?)');
        } else {
            $insertDetail = $pdo->prepare('INSERT INTO tbl_detail_transaksi (id_transaksi, id_produk, jumlah, harga_satuan) VALUES (?, ?, ?, ?)');
        }
        $updateStock = $pdo->prepare('UPDATE tbl_produk SET stok = stok - ? WHERE id_produk = ? AND stok >= ?');
        foreach ($products as $product) {
            $id = (int) $product['id_produk'];
            $quantity = $quantities[$id];
            if ($hasLegacyColumns) {
                $insertDetail->execute([$transactionId, $id, $product['nama_produk'], $quantity, $product['harga'], $product['harga']]);
            } else {
                $insertDetail->execute([$transactionId, $id, $quantity, $product['harga']]);
            }
            $updateStock->execute([$quantity, $id, $quantity]);
            if ($updateStock->rowCount() !== 1) {
                throw new DomainException('Terjadi perubahan stok saat transaksi berlangsung. Silakan coba kembali.');
            }
        }
        $pdo->commit();
        redirect('cetak_struk.php?id=' . $transactionId . '&print=1');
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception->getMessage());
        redirect('pos.php');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Checkout tunai gagal: ' . $exception->getMessage());
        flash('danger', 'Transaksi tidak dapat diproses. Silakan periksa koneksi database dan coba kembali.');
        redirect('pos.php');
    }
}

// Ambil Katalog Produk Siap Jual
$search = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT p.id_produk, p.nama_produk, p.kode_barcode, p.gambar, p.harga, p.stok, k.nama_kategori 
        FROM tbl_produk p 
        JOIN tbl_kategori k ON k.id_kategori = p.id_kategori 
        WHERE p.stok > 0';
$params = [];
if ($search !== '') {
    $sql .= ' AND (p.nama_produk LIKE ? OR k.nama_kategori LIKE ? OR p.kode_barcode LIKE ?)';
    $params = ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%'];
}
$sql .= ' ORDER BY p.nama_produk';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query('SELECT id_kategori, nama_kategori FROM tbl_kategori ORDER BY nama_kategori')->fetchAll();
try {
    $customers = $pdo->query('SELECT id_pelanggan, nama_pelanggan, id_card_rfid, status_member FROM tbl_pelanggan ORDER BY nama_pelanggan')->fetchAll();
} catch (PDOException $e) {
    $customers = $pdo->query('SELECT id_pelanggan, nama_pelanggan FROM tbl_pelanggan ORDER BY nama_pelanggan')->fetchAll();
}

// Konfigurasi Payment Gateway Duitku Sandbox
$duitkuConfigFile = __DIR__ . '/config/duitku.php';
$hasDuitkuConfig = is_file($duitkuConfigFile);
$duitkuConfig = $hasDuitkuConfig ? require $duitkuConfigFile : [];
$duitkuConfigured = $hasDuitkuConfig
    && !empty($duitkuConfig['merchant_code'])
    && !empty($duitkuConfig['api_key']);
$demoSimulatorUrl = (string) ($duitkuConfig['demo_simulator_url'] ?? 'https://sandbox.duitku.com/payment/demo/demosuccesstransaction.aspx');

$pageTitle = 'POS / Kasir';
require __DIR__ . '/includes/header.php';
?>

<style>
.info-copy-box {
    background: #f8fafc;
    border: 1.5px dashed #cbd5e1;
    border-radius: 8px;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.duitku-box {
    background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
    border: 1.5px solid #bfdbfe;
    border-radius: 12px;
}
.btn-pay-duitku {
    background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%);
    border: 0;
    color: #ffffff;
    font-weight: 700;
}
.btn-pay-duitku:hover {
    background: linear-gradient(135deg, #4338ca 0%, #0891b2 100%);
    color: #ffffff;
}
</style>

<div class="row g-4">
    <!-- Left Column: Product Catalog -->
    <div class="col-xl-7">
        <div class="card-panel">
            <div class="panel-heading">
                <div>
                    <h2 class="section-title">
                        <i class="bi bi-grid text-primary"></i>
                        Katalog Produk
                    </h2>
                    <div class="section-subtitle">Klik kartu produk untuk memasukkan ke keranjang belanja</div>
                </div>
                <span class="badge-soft badge-indigo">
                    <i class="bi bi-box-seam me-1"></i> <?= count($products) ?> Produk Siap Jual
                </span>
            </div>

            <div class="panel-body pb-0">
                <!-- Search & Filters -->
                <div class="barcode-scan mb-3">
                    <label class="form-label fw-semibold" for="barcode-input">
                        <i class="bi bi-upc-scan text-primary me-1"></i> Scan Barcode Produk
                    </label>
                    <div class="input-group">
                        <span class="input-group-text bg-white text-primary"><i class="bi bi-upc-scan"></i></span>
                        <input
                            class="form-control"
                            id="barcode-input"
                            type="text"
                            autocomplete="off"
                            autocapitalize="off"
                            spellcheck="false"
                            placeholder="Scan barcode produk, lalu tekan Enter..."
                            aria-describedby="barcode-status"
                        >
                        <button class="btn btn-outline-primary" type="button" id="barcode-submit">
                            <i class="bi bi-plus-lg me-1"></i> Tambah
                        </button>
                    </div>
                    <div class="barcode-status text-muted" id="barcode-status" role="status" aria-live="polite">
                        Siap memindai. Scanner barcode akan mengirim kode dan menekan Enter.
                    </div>
                </div>

                <form class="filter-bar mb-3" method="get">
                    <div class="search-field">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted">
                                <i class="bi bi-search"></i>
                            </span>
                            <input class="form-control border-start-0 ps-0" type="search" name="q" value="<?= e($search) ?>" placeholder="Cari nama, kategori, atau kode barcode...">
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">
                        <i class="bi bi-search me-1"></i> Cari
                    </button>
                    <?php if ($search !== ''): ?>
                        <a href="pos.php" class="btn btn-light btn-sm text-muted">Reset</a>
                    <?php endif; ?>
                </form>

                <!-- Category Quick Filter Pills -->
                <?php if ($categories): ?>
                    <div class="category-pills">
                        <button type="button" class="category-pill active" data-category-filter="all">
                            Semua (<?= count($products) ?>)
                        </button>
                        <?php foreach ($categories as $cat): ?>
                            <button type="button" class="category-pill" data-category-filter="<?= e($cat['nama_kategori']) ?>">
                                <?= e($cat['nama_kategori']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Product Grid -->
            <div class="panel-body pt-0">
                <div class="row g-3" id="product-catalog">
                    <?php if (!$products): ?>
                        <div class="col-12">
                            <div class="empty-state">
                                <i class="bi bi-search"></i>
                                <div class="empty-state-text">
                                    Tidak ada produk yang sesuai dengan pencarian atau semua stok sedang habis.
                                </div>
                                <?php if ($_SESSION['role'] === 'admin'): ?>
                                    <a href="inventory.php" class="btn btn-sm btn-outline-primary mt-3">
                                        <i class="bi bi-plus-lg me-1"></i> Kelola Stok Inventory
                                    </a>
                                <?php else: ?>
                                    <div class="small text-muted mt-3">Hubungi Admin untuk memperbarui stok produk.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: foreach ($products as $product): ?>
                        <div class="col-6 col-sm-4 col-md-4 product-catalog-item" data-category="<?= e($product['nama_kategori']) ?>">
                            <button type="button" 
                                    class="product-card w-100 text-start" 
                                    data-add-product 
                                    data-id="<?= (int) $product['id_produk'] ?>" 
                                    data-name="<?= e($product['nama_produk']) ?>" 
                                    data-price="<?= (int) $product['harga'] ?>" 
                                    data-stock="<?= (int) $product['stok'] ?>">
                                
                                <div class="product-symbol position-relative">
                                    <?php $imageName = safe_product_image_name($product['gambar'] ?? null); ?>
                                    <?php if ($imageName !== null && is_file(__DIR__ . '/uploads/produk/' . $imageName)): ?>
                                        <img src="<?= e('uploads/produk/' . $imageName) ?>" alt="Gambar <?= e($product['nama_produk']) ?>" loading="lazy">
                                    <?php else: ?>
                                        <i class="bi bi-box2-heart"></i>
                                    <?php endif; ?>
                                    <span class="badge-soft badge-indigo position-absolute top-0 end-0 m-2" style="font-size: 9.5px; padding: 2px 7px;">
                                        <?= e($product['nama_kategori']) ?>
                                    </span>
                                </div>
                                
                                <h3><?= e($product['nama_produk']) ?></h3>
                                <div class="price"><?= rupiah($product['harga']) ?></div>
                                
                                <div class="stock-tag">
                                    <span>
                                        <i class="bi bi-check-circle-fill text-success" style="font-size: 10px;"></i> Tersedia
                                    </span>
                                    <span class="badge-soft <?= (int) $product['stok'] < 10 ? 'badge-amber' : 'badge-emerald' ?> py-0 px-2">
                                        <?= (int) $product['stok'] ?> pcs
                                    </span>
                                </div>
                            </button>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Cart & Checkout Panel -->
    <div class="col-xl-5">
        <form method="post" id="pos-form" class="card-panel cart-sticky-panel">
            <input type="hidden" name="csrf_token" id="pos-csrf-token" value="<?= e(csrf_token()) ?>">
            
            <div class="panel-heading">
                <div>
                    <h2 class="section-title">
                        <i class="bi bi-cart3 text-primary"></i>
                        Keranjang Belanja
                    </h2>
                    <div class="section-subtitle">Daftar item belanja kasir &amp; ritel</div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-outline-danger btn-sm py-1 px-2 d-none" id="btn-clear-pos-cart" title="Kosongkan seluruh keranjang belanja">
                        <i class="bi bi-trash3 me-1"></i> Kosongkan
                    </button>
                    <span class="badge-soft badge-indigo" id="cart-count">0 item</span>
                </div>
            </div>

            <div class="panel-body">
                <!-- Cart Item Container -->
                <div class="cart-items-wrapper">
                    <div id="cart-items">
                        <div class="empty-state py-4" id="cart-empty">
                            <i class="bi bi-basket3"></i>
                            <div class="empty-state-text">
                                Keranjang masih kosong. Klik salah satu produk di sebelah kiri untuk memasukkan ke keranjang.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customer & Cashier Section -->
                <div class="pt-3 mt-3 border-top">
                    <!-- Member Card / RFID Scanner Section -->
                    <div class="member-scan-box p-3 rounded-3 border bg-light mb-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label mb-0 fw-bold text-slate-800" for="rfid-card-input">
                                <i class="bi bi-person-vcard text-primary me-1"></i> Tap Member Card (RFID)
                            </label>
                            <span class="badge-soft badge-emerald py-0 px-2" style="font-size: 11px;">
                                <i class="bi bi-percent me-1"></i> Diskon 10%
                            </span>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text bg-white text-primary border-end-0">
                                <i class="bi bi-broadcast-pin"></i>
                            </span>
                            <input
                                type="text"
                                id="rfid-card-input"
                                class="visually-hidden"
                                aria-label="Input pemindai kartu RFID member"
                                autocomplete="off"
                                spellcheck="false"
                                autofocus
                            >
                            <button class="btn btn-outline-primary" type="button" id="rfid-submit-btn">
                                <i class="bi bi-broadcast-pin me-1"></i> Fokus Pemindai
                            </button>
                        </div>
                        <div id="rfid-status-msg" class="small text-muted mt-2 d-flex align-items-center gap-1">
                            <i class="bi bi-info-circle"></i>
                            <span>Pemindai siap. Tap kartu RFID; diskon 10% hanya berlaku untuk member aktif. (Shortcut: F2)</span>
                        </div>
                        <div id="member-active-box" class="d-none mt-2 alert alert-success py-2 px-3 mb-0 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-patch-check-fill text-success fs-5"></i>
                                <div>
                                    <strong id="member-active-name" class="d-block text-slate-900" style="font-size: 12.5px;">-</strong>
                                    <small class="text-success fw-semibold" id="member-active-info">Diskon Member 10% Aktif</small>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-link text-danger p-0 text-decoration-none fw-bold" id="cancel-member-btn" title="Hapus Diskon Member">
                                <i class="bi bi-x-circle-fill"></i> Batalkan
                            </button>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-12 col-sm-6">
                            <label class="form-label">
                                <i class="bi bi-person me-1"></i> Pelanggan
                            </label>
                            <select class="form-select" name="id_pelanggan" id="customer-select">
                                <option value="">Pelanggan Umum (Walk-in)</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= (int) $customer['id_pelanggan'] ?>"
                                            data-rfid="<?= e($customer['id_card_rfid'] ?? '') ?>"
                                            data-status="<?= e($customer['status_member'] ?? 'aktif') ?>">
                                        <?= e($customer['nama_pelanggan']) ?><?= !empty($customer['id_card_rfid']) ? ' (Member)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label">
                                <i class="bi bi-person-badge me-1"></i> Nama Kasir
                            </label>
                            <input class="form-control" name="nama_kasir" id="pos-cashier-name" value="<?= e($_SESSION['nama']) ?>" maxlength="100" readonly>
                        </div>
                    </div>

                    <!-- Summary & Quick Calculation Box -->
                    <div class="summary-box">
                        <input type="hidden" name="id_card_rfid" id="member-card-id">
                        <div class="summary-row">
                            <span>Subtotal Belanja</span>
                            <strong id="subtotal-label">Rp 0</strong>
                        </div>
                        
                        <div class="summary-row align-items-center">
                            <label for="discount-input" class="mb-0 text-muted" style="font-size: 12.5px;">Potongan Diskon (Rp)</label>
                            <div class="input-group input-group-sm" style="max-width: 150px;">
                                <span class="input-group-text py-0">Rp</span>
                                <input class="form-control text-end py-1" type="number" name="diskon" id="discount-input" min="0" step="1" value="0" readonly>
                            </div>
                        </div>
                        
                        <div class="summary-row">
                            <span>Total Diskon</span>
                            <strong class="text-danger" id="discount-label">-Rp 0</strong>
                        </div>

                        <div class="summary-row summary-total">
                            <span>TOTAL BAYAR</span>
                            <span id="total-label" class="fs-4 text-primary">Rp 0</span>
                        </div>
                    </div>

                    <!-- PILIHAN METODE PEMBAYARAN KASIR: TUNAI VS DUITKU NON-TUNAI -->
                    <div class="mt-4 pt-3 border-top">
                        <label class="form-label fw-bold d-flex justify-content-between align-items-center mb-2">
                            <span><i class="bi bi-credit-card-2-front text-primary me-1"></i> Metode Pembayaran:</span>
                            <span class="badge bg-secondary-subtle text-secondary" style="font-size: 11px;">Admin &amp; Kasir</span>
                        </label>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="pos_payment_type" id="pay-type-cash" value="cash" checked autocomplete="off">
                                <label class="btn btn-outline-success w-100 py-2 d-flex flex-column align-items-center justify-content-center" for="pay-type-cash" id="label-pay-cash" style="cursor: pointer;">
                                    <span class="fw-bold"><i class="bi bi-cash-stack me-1"></i> Tunai (Cash)</span>
                                    <small style="font-size: 11px;">Nominal Uang &amp; Kembalian</small>
                                </label>
                            </div>
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="pos_payment_type" id="pay-type-duitku" value="duitku" autocomplete="off">
                                <label class="btn btn-outline-primary w-100 py-2 d-flex flex-column align-items-center justify-content-center" for="pay-type-duitku" id="label-pay-duitku" style="cursor: pointer;">
                                    <span class="fw-bold"><i class="bi bi-qr-code-scan me-1"></i> Duitku (Non-Tunai)</span>
                                    <small style="font-size: 11px;">VA Bank / QRIS / E-Wallet</small>
                                </label>
                            </div>
                        </div>

                        <!-- CONTAINER 1: PEMBAYARAN TUNAI (CASH) -->
                        <div id="pos-cash-container">
                            <div class="mb-3">
                                <label class="form-label d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-cash me-1"></i> Uang Diterima / Tunai (Rp)</span>
                                    <small class="text-muted fw-normal">Pilih nominal cepat:</small>
                                </label>
                                
                                <!-- Quick Cash Pills -->
                                <div class="quick-cash-pills">
                                    <button type="button" class="quick-cash-pill" data-cash-preset="exact">Uang Pas</button>
                                    <button type="button" class="quick-cash-pill" data-cash-preset="10000">10.000</button>
                                    <button type="button" class="quick-cash-pill" data-cash-preset="20000">20.000</button>
                                    <button type="button" class="quick-cash-pill" data-cash-preset="50000">50.000</button>
                                    <button type="button" class="quick-cash-pill" data-cash-preset="100000">100.000</button>
                                </div>

                                <div class="input-group">
                                    <span class="input-group-text bg-light fw-bold">Rp</span>
                                    <input class="form-control form-control-lg fw-bold" 
                                           type="number" 
                                           name="uang_bayar" 
                                           id="payment-input" 
                                           min="0" 
                                           step="1" 
                                           required 
                                           placeholder="0">
                                </div>
                            </div>

                            <!-- Change Display -->
                            <div class="summary-row p-2 rounded mb-3" style="background: #ffffff; border: 1px solid var(--border);">
                                <span class="fw-semibold"><i class="bi bi-arrow-repeat text-muted me-1"></i> Kembalian:</span>
                                <strong class="fs-5 text-muted" id="change-label">Rp 0</strong>
                            </div>

                            <!-- Submit Cash Button -->
                            <button class="btn btn-success btn-lg w-100 fw-bold py-3 shadow-sm" type="submit" id="pos-submit-cash-btn">
                                <i class="bi bi-printer-fill me-2"></i> Proses Pembayaran Tunai &amp; Cetak Struk
                            </button>
                        </div>

                        <!-- CONTAINER 2: PEMBAYARAN DUITKU (NON-TUNAI) - PERSIS SEPERTI DI BELANJA_PELANGGAN.PHP -->
                        <div id="pos-duitku-container" class="d-none">
                            <div class="duitku-box p-3 mb-3">
                                <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom border-primary-subtle">
                                    <span class="fw-bold text-primary-emphasis d-flex align-items-center gap-1">
                                        <i class="bi bi-credit-card-2-front-fill text-primary"></i>
                                        Payment Gateway Duitku (Otomatis Sandbox)
                                    </span>
                                    <span class="badge bg-primary text-white" style="font-size: 10px;">Sandbox</span>
                                </div>
                                
                                <div class="small text-muted mb-2">
                                    Mendukung <strong>Virtual Account Bank</strong>, <strong>QRIS Realtime</strong>, dan <strong>E-Wallet</strong> resmi. Terverifikasi instan tanpa unggah struk manual.
                                </div>

                                <label class="form-label small fw-bold text-slate-800 mb-1" for="duitku-payment-method">
                                    <i class="bi bi-wallet2 me-1 text-primary"></i>Pilih Metode / Kanal Pembayaran Duitku:
                                </label>
                                <select class="form-select form-select-sm mb-2" name="duitku_payment_method" id="duitku-payment-method">
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

                                <div class="form-text text-muted mb-2" style="font-size: 11px;">
                                    ⭐ <em>Panduan Demo: Untuk <strong>Virtual Account (BRI, BCA, Mandiri, BNI, dll.)</strong> gunakan <strong>Nomor VA Resmi</strong>. Untuk <strong>QRIS</strong> gunakan <strong>Duitku Reference</strong> di halaman simulasi Duitku.</em>
                                </div>

                                <div id="pos-qris-small-nominal-tip" class="d-none alert alert-info py-2 px-2 mb-0" style="font-size: 11.5px;">
                                    <i class="bi bi-info-circle-fill me-1 text-primary"></i><strong>Saran Transaksi Nominal Kecil:</strong> Belanja di bawah Rp 10.000 disarankan menggunakan kanal <strong>QRIS ShopeePay (SP)</strong> karena Virtual Account perbankan mensyaratkan batas minimal Rp 10.000.
                                </div>
                            </div>

                            <!-- Tombol Bayar via Duitku Sandbox -->
                            <button class="btn btn-pay-duitku btn-lg w-100 py-3 shadow-sm fw-bold" type="button" id="pay-duitku-button">
                                <i class="bi bi-qr-code-scan me-2"></i> Bayar via Duitku Sandbox (Non-Tunai)
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- MODAL INFORMASI TRANSAKSI & SIMULASI DEMO DUITKU SANDBOX (PERSIS SEPERTI DI PELANGGAN) -->
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
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 fs-6" id="modal-display-method">BRI Virtual Account</span>
                        </div>
                    </div>

                    <!-- INDIKATOR DETEKSI OTOMATIS REAL-TIME -->
                    <div class="d-flex align-items-center justify-content-center gap-2 small text-primary bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded-3 py-2 px-3 mb-3" id="polling-indicator">
                        <div class="spinner-grow spinner-grow-sm text-primary" role="status"></div>
                        <span class="fw-semibold" style="font-size: 12px;">Mendeteksi status pembayaran secara otomatis (real-time)...</span>
                    </div>

                    <!-- KOTAK 1: VIRTUAL ACCOUNT NUMBER (UTAMA UNTUK BRI, BCA, MANDIRI, BNI) -->
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
                            👉 <strong>Langkah Simulasi VA di Demo Duitku:</strong> Buka halaman demo Duitku, pilih <strong>Payment Method</strong> (contoh: <em>Bank Rakyat Indonesia</em> untuk BRI VA), lalu tempelkan <strong>Nomor Virtual Account</strong> di atas pada kolom <strong>Va Number</strong>, dan klik tombol <strong>Check</strong>.
                        </small>
                    </div>

                    <!-- KOTAK 2: DUITKU REFERENCE (UTAMA UNTUK QRIS) -->
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
                            <code class="fs-6 fw-bold text-secondary font-monospace user-select-all" id="modal-display-order-id">-</code>
                        </div>
                    </div>

                    <!-- PANDUAN RINGKAS -->
                    <div class="alert alert-info py-2 px-3 small mb-4">
                        <i class="bi bi-info-circle-fill me-1"></i>
                        Setelah Anda menekan <strong>Check</strong> pada halaman simulasi Duitku, transaksi otomatis terverifikasi lunas, memotong stok produk secara atomik, dan layar ini akan langsung berganti menjadi <strong>Pembayaran Berhasil</strong>!
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

                <!-- TAMPILAN 2: LAYAR PEMBAYARAN BERHASIL (OTOMATIS TAMPIL KETIKA LUNAS) -->
                <div id="duitku-success-view" class="d-none text-center py-4">
                    <div class="mb-3">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4.5rem;"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-1">Pembayaran Berhasil &amp; Lunas!</h4>
                    <p class="text-muted small mb-4">
                        Terima kasih! Pembayaran telah diverifikasi resmi oleh Payment Gateway Duitku. Stok barang telah terpotong otomatis secara aman &amp; atomik.
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
                        <button type="button" class="btn btn-primary px-4 fw-bold" id="success-btn-done" data-bs-dismiss="modal">
                            <i class="bi bi-cart-check me-1"></i> Selesai &amp; Transaksi Baru
                        </button>
                    </div>
                </div>

                <!-- TAMPILAN 3: LAYAR KEDALUWARSA / WAKTU HABIS (EXPIRED) -->
                <div id="duitku-expired-view" class="d-none text-center py-4">
                    <div class="mb-3">
                        <i class="bi bi-hourglass-bottom text-danger" style="font-size: 4rem;"></i>
                    </div>
                    <h4 class="fw-bold text-danger mb-1">Batas Waktu Pembayaran Habis</h4>
                    <p class="text-muted small mb-4">
                        Waktu 10:00 menit untuk menyelesaikan pembayaran Duitku telah berakhir. Pesanan ini telah otomatis dibatalkan oleh sistem.
                    </p>
                    <button type="button" class="btn btn-primary px-4 fw-semibold" data-bs-dismiss="modal">
                        <i class="bi bi-arrow-repeat me-1"></i> Kembali ke Kasir
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JAVASCRIPT INLINE LENGKAP & MANDIRI KHUSUS POS (BEBAS DARI MASALAH CACHE BROWSER) -->
<script>
window.__POS_INITIALIZED__ = true;
document.addEventListener('DOMContentLoaded', function () {
    var posForm = document.getElementById('pos-form');
    if (!posForm) return;

    var cart = document.getElementById('cart-items');
    var emptyState = document.getElementById('cart-empty');
    var discountInput = document.getElementById('discount-input');
    var paymentInput = document.getElementById('payment-input');
    var currentSubtotal = 0;
    var currentTotal = 0;
    var activeMemberDiscountPercent = 0;
    var activeMemberData = null;

    // Toggle Metode Pembayaran: Tunai vs Duitku
    var payTypeCash = document.getElementById('pay-type-cash');
    var payTypeDuitku = document.getElementById('pay-type-duitku');
    var labelPayCash = document.getElementById('label-pay-cash');
    var labelPayDuitku = document.getElementById('label-pay-duitku');
    var cashContainer = document.getElementById('pos-cash-container');
    var duitkuContainer = document.getElementById('pos-duitku-container');

    function switchPaymentMethod(method) {
        if (method === 'duitku') {
            if (cashContainer) cashContainer.classList.add('d-none');
            if (duitkuContainer) duitkuContainer.classList.remove('d-none');
            if (paymentInput) paymentInput.required = false;
        } else {
            if (cashContainer) cashContainer.classList.remove('d-none');
            if (duitkuContainer) duitkuContainer.classList.add('d-none');
            if (paymentInput) paymentInput.required = true;
        }
    }

    if (payTypeCash) {
        payTypeCash.addEventListener('change', function () {
            if (this.checked) switchPaymentMethod('cash');
        });
    }
    if (payTypeDuitku) {
        payTypeDuitku.addEventListener('change', function () {
            if (this.checked) switchPaymentMethod('duitku');
        });
    }
    if (labelPayCash) {
        labelPayCash.addEventListener('click', function () {
            if (payTypeCash) payTypeCash.checked = true;
            switchPaymentMethod('cash');
        });
    }
    if (labelPayDuitku) {
        labelPayDuitku.addEventListener('click', function () {
            if (payTypeDuitku) payTypeDuitku.checked = true;
            switchPaymentMethod('duitku');
        });
    }

    function formatRupiah(value) {
        return 'Rp ' + new Intl.NumberFormat('id-ID').format(value);
    }

    function updateTotals() {
        var items = Array.from(cart.querySelectorAll('.cart-item'));
        var subtotal = 0;
        var totalQty = 0;

        items.forEach(function (item) {
            var quantityInput = item.querySelector('.cart-qty');
            var quantity = Math.max(0, parseInt(quantityInput.value, 10) || 0);
            var price = parseInt(item.dataset.price, 10);
            var stock = parseInt(quantityInput.max, 10);
            if (quantity > stock) {
                quantity = stock;
                quantityInput.value = stock;
            }
            totalQty += quantity;
            var lineTotal = price * quantity;
            subtotal += lineTotal;
            var subtotalEl = item.querySelector('.line-subtotal');
            if (subtotalEl) {
                subtotalEl.textContent = formatRupiah(lineTotal);
            }
        });

        currentSubtotal = subtotal;

        // Diskon member 10% jika member RFID aktif
        if (activeMemberDiscountPercent > 0) {
            var memberDiscount = Math.round(subtotal * (activeMemberDiscountPercent / 100));
            if (discountInput) {
                discountInput.value = memberDiscount;
            }
        }

        var discount = Math.min(subtotal, Math.max(0, parseInt(discountInput ? discountInput.value : 0, 10) || 0));
        var total = subtotal - discount;
        currentTotal = total;
        var payment = Math.max(0, parseInt(paymentInput ? paymentInput.value : 0, 10) || 0);

        var subtotalEl = document.getElementById('subtotal-label');
        if (subtotalEl) subtotalEl.textContent = formatRupiah(subtotal);

        var discountEl = document.getElementById('discount-label');
        if (discountEl) {
            if (activeMemberDiscountPercent > 0 && discount > 0) {
                discountEl.textContent = '-' + formatRupiah(discount) + ' (Member ' + activeMemberDiscountPercent + '%)';
            } else {
                discountEl.textContent = '-' + formatRupiah(discount);
            }
        }

        var totalEl = document.getElementById('total-label');
        if (totalEl) totalEl.textContent = formatRupiah(total);

        var changeEl = document.getElementById('change-label');
        if (changeEl) {
            var change = Math.max(0, payment - total);
            changeEl.textContent = formatRupiah(change);
            if (payment >= total && total > 0) {
                changeEl.classList.remove('text-muted');
                changeEl.classList.add('text-success');
            } else {
                changeEl.classList.remove('text-success');
                changeEl.classList.add('text-muted');
            }
        }

        var cartCountEl = document.getElementById('cart-count');
        if (cartCountEl) {
            cartCountEl.textContent = totalQty + ' item';
        }

        if (emptyState) {
            emptyState.classList.toggle('d-none', items.length > 0);
        }

        var clearCartBtn = document.getElementById('btn-clear-pos-cart');
        if (clearCartBtn) {
            clearCartBtn.classList.toggle('d-none', items.length === 0);
        }

        // Tampilkan tip saran QRIS jika nominal belanja di bawah Rp 10.000
        var qrisTip = document.getElementById('pos-qris-small-nominal-tip');
        if (qrisTip) {
            qrisTip.classList.toggle('d-none', !(total > 0 && total < 10000));
        }
    }

    function addProductToCart(product) {
        var id = String(product.id);
        var name = String(product.name);
        var price = parseInt(product.price, 10);
        var stock = parseInt(product.stock, 10);
        var existing = cart.querySelector('.cart-item[data-id="' + id + '"]');

        if (existing) {
            var existingQuantity = existing.querySelector('.cart-qty');
            var currentQuantity = parseInt(existingQuantity.value, 10) || 0;
            if (currentQuantity >= stock) return false;
            existingQuantity.value = currentQuantity + 1;
        } else {
            var item = document.createElement('div');
            item.className = 'cart-line cart-item';
            item.dataset.id = id;
            item.dataset.price = price;

            var info = document.createElement('div');
            info.className = 'cart-item-info';
            var productName = document.createElement('strong');
            productName.textContent = name;
            var detail = document.createElement('small');
            detail.textContent = formatRupiah(price);
            info.appendChild(productName);
            info.appendChild(detail);

            var controls = document.createElement('div');
            controls.className = 'cart-qty-wrapper';

            var quantity = document.createElement('input');
            quantity.type = 'number';
            quantity.className = 'form-control cart-qty';
            quantity.name = 'qty[' + id + ']';
            quantity.min = '0';
            quantity.max = stock;
            quantity.value = '1';
            quantity.setAttribute('aria-label', 'Jumlah ' + name);

            var subtotal = document.createElement('span');
            subtotal.className = 'line-subtotal';
            subtotal.textContent = formatRupiah(price);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'remove-item';
            remove.setAttribute('aria-label', 'Hapus ' + name);
            remove.innerHTML = '<i class="bi bi-trash3"></i>';

            controls.appendChild(quantity);
            controls.appendChild(subtotal);
            controls.appendChild(remove);
            item.appendChild(info);
            item.appendChild(controls);
            cart.appendChild(item);
        }

        updateTotals();
        return true;
    }

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

            addProductToCart({
                id: button.dataset.id,
                name: button.dataset.name,
                price: button.dataset.price,
                stock: button.dataset.stock
            });
        });
    });

    // Barcode Scanner
    var barcodeInput = document.getElementById('barcode-input');
    var barcodeStatus = document.getElementById('barcode-status');
    var barcodeSubmit = document.getElementById('barcode-submit');
    var barcodeLookupPending = false;

    function setBarcodeStatus(message, statusClass) {
        if (!barcodeStatus) return;
        barcodeStatus.textContent = message;
        barcodeStatus.classList.remove('text-muted', 'text-success', 'text-danger');
        barcodeStatus.classList.add(statusClass);
    }

    function lookupBarcode() {
        if (!barcodeInput || barcodeLookupPending) return;
        var barcode = barcodeInput.value.trim();
        if (!barcode) return;

        barcodeLookupPending = true;
        setBarcodeStatus('Mencari produk...', 'text-muted');

        fetch('pos.php?barcode_lookup=' + encodeURIComponent(barcode), {
            headers: { Accept: 'application/json' },
            cache: 'no-store'
        })
        .then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) throw new Error(data.message || 'Produk tidak ditemukan.');
                return data;
            });
        })
        .then(function (data) {
            if (addProductToCart(data.product)) {
                setBarcodeStatus('Ditambahkan ke keranjang: ' + data.product.name, 'text-success');
            } else {
                setBarcodeStatus('Jumlah produk sudah mencapai stok maksimal.', 'text-danger');
            }
        })
        .catch(function (error) {
            setBarcodeStatus(error.message || 'Pencarian barcode gagal.', 'text-danger');
        })
        .finally(function () {
            barcodeLookupPending = false;
            barcodeInput.value = '';
            barcodeInput.focus();
        });
    }

    if (barcodeInput) {
        barcodeInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                lookupBarcode();
            }
        });
    }
    if (barcodeSubmit) {
        barcodeSubmit.addEventListener('click', lookupBarcode);
    }

    // RFID Member Scanner
    var rfidInput = document.getElementById('rfid-card-input');
    var rfidSubmitBtn = document.getElementById('rfid-submit-btn');
    var rfidStatusMsg = document.getElementById('rfid-status-msg');
    var memberActiveBox = document.getElementById('member-active-box');
    var memberActiveName = document.getElementById('member-active-name');
    var memberActiveInfo = document.getElementById('member-active-info');
    var cancelMemberBtn = document.getElementById('cancel-member-btn');
    var customerSelect = document.getElementById('customer-select');
    var posCsrfToken = document.getElementById('pos-csrf-token');
    var memberCardId = document.getElementById('member-card-id');
    var rfidLookupPending = false;

    function setRfidStatus(message, statusClass) {
        if (!rfidStatusMsg) return;
        rfidStatusMsg.innerHTML = message;
        rfidStatusMsg.className = 'small mt-2 ' + (statusClass || 'text-muted');
    }

    function applyMember(member) {
        activeMemberDiscountPercent = Number(member.diskon_persen) === 10 && member.status_member === 'aktif' ? 10 : 0;
        if (activeMemberDiscountPercent === 0 || !member.id_card_rfid) {
            removeMember();
            return;
        }
        activeMemberData = member;
        if (memberCardId) memberCardId.value = member.id_card_rfid || '';

        if (memberActiveBox && memberActiveName) {
            memberActiveName.textContent = member.nama_pelanggan || member.nama_member;
            if (memberActiveInfo) {
                memberActiveInfo.textContent = 'ID: ' + (member.id_card_rfid || '-') + ' · Diskon Member ' + activeMemberDiscountPercent + '% Aktif';
            }
            memberActiveBox.classList.remove('d-none');
        }

        if (customerSelect) {
            for (var i = 0; i < customerSelect.options.length; i++) {
                if (parseInt(customerSelect.options[i].value, 10) === parseInt(member.id_pelanggan, 10)) {
                    customerSelect.selectedIndex = i;
                    break;
                }
            }
        }

        setRfidStatus('<i class="bi bi-check-circle-fill text-success me-1"></i> Member aktif! Diskon 10% diterapkan.', 'text-success');
        updateTotals();
    }

    function removeMember() {
        activeMemberDiscountPercent = 0;
        activeMemberData = null;
        if (memberCardId) memberCardId.value = '';
        if (memberActiveBox) memberActiveBox.classList.add('d-none');
        if (discountInput) discountInput.value = 0;
        setRfidStatus('<i class="bi bi-info-circle me-1"></i> Diskon member dibatalkan.', 'text-muted');
        updateTotals();
    }

    function lookupMember() {
        if (!rfidInput || rfidLookupPending) return;
        var idCard = rfidInput.value.trim();
        if (!idCard) {
            setRfidStatus('<i class="bi bi-exclamation-triangle-fill text-warning me-1"></i> Tap kartu RFID terlebih dahulu.', 'text-warning');
            rfidInput.focus();
            return;
        }

        rfidLookupPending = true;
        setRfidStatus('<span class="spinner-border spinner-border-sm me-1" role="status"></span> Memeriksa kartu member...', 'text-primary');

        var formData = new URLSearchParams();
        formData.append('id_card', idCard);
        if (posCsrfToken) formData.append('csrf_token', posCsrfToken.value);

        fetch('ajax_cek_member.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json'
            },
            body: formData.toString()
        })
        .then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || data.status !== 'success' || data.status_member !== 'aktif') {
                    throw new Error(data.message || 'Verifikasi kartu member gagal.');
                }
                return data;
            });
        })
        .then(function (data) {
            applyMember(data);
            rfidInput.value = '';
            if (barcodeInput) barcodeInput.focus();
        })
        .catch(function (error) {
            removeMember();
            setRfidStatus('<i class="bi bi-x-circle-fill text-danger me-1"></i> ' + error.message, 'text-danger');
            rfidInput.select();
        })
        .finally(function () {
            rfidLookupPending = false;
        });
    }

    if (rfidInput) {
        rfidInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                lookupMember();
            }
        });
    }
    if (rfidSubmitBtn) {
        rfidSubmitBtn.addEventListener('click', function () {
            if (rfidInput) rfidInput.focus();
        });
    }
    if (cancelMemberBtn) cancelMemberBtn.addEventListener('click', removeMember);

    // Jika kasir mengganti nama pelanggan di dropdown secara manual, pastikan diskon RFID dicabut jika tidak cocok
    if (customerSelect) {
        customerSelect.addEventListener('change', function () {
            var selectedId = parseInt(this.value, 10) || 0;
            if (activeMemberData && parseInt(activeMemberData.id_pelanggan, 10) !== selectedId) {
                removeMember();
                setRfidStatus('<i class="bi bi-info-circle me-1"></i> Diskon member dibatalkan karena nama pelanggan diubah.', 'text-muted');
            }
        });
    }

    // Shortcuts F2 (Member) & F1 (Barcode)
    window.addEventListener('keydown', function (event) {
        if (event.key === 'F2') {
            event.preventDefault();
            if (rfidInput) { rfidInput.focus(); rfidInput.select(); }
        } else if (event.key === 'F1') {
            event.preventDefault();
            if (barcodeInput) { barcodeInput.focus(); barcodeInput.select(); }
        }
    });

    // Cart Events
    cart.addEventListener('input', function (event) {
        if (event.target.classList.contains('cart-qty')) updateTotals();
    });

    cart.addEventListener('click', function (event) {
        var removeButton = event.target.closest('.remove-item');
        if (removeButton) {
            var item = removeButton.closest('.cart-item');
            if (item) {
                item.remove();
                updateTotals();
            }
        }
    });

    var clearCartBtn = document.getElementById('btn-clear-pos-cart');
    if (clearCartBtn) {
        clearCartBtn.addEventListener('click', function () {
            if (!window.confirm('Kosongkan semua item dari keranjang belanja kasir?')) return;
            var cartLines = cart.querySelectorAll('.cart-item');
            cartLines.forEach(function (line) { line.remove(); });
            if (paymentInput) paymentInput.value = '';
            removeMember();
            updateTotals();
        });
    }

    if (paymentInput) paymentInput.addEventListener('input', updateTotals);

    // Quick Cash Pills
    document.querySelectorAll('[data-cash-preset]').forEach(function (button) {
        button.addEventListener('click', function () {
            var preset = button.dataset.cashPreset;
            if (preset === 'exact') {
                if (paymentInput) paymentInput.value = currentTotal;
            } else {
                var amount = parseInt(preset, 10);
                if (paymentInput && !isNaN(amount)) paymentInput.value = amount;
            }
            updateTotals();
        });
    });

    // Category Filter Pills
    var categoryPills = document.querySelectorAll('[data-category-filter]');
    var productItems = document.querySelectorAll('.product-catalog-item');
    if (categoryPills.length > 0 && productItems.length > 0) {
        categoryPills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                categoryPills.forEach(function (p) { p.classList.remove('active'); });
                pill.classList.add('active');
                var targetCat = pill.dataset.categoryFilter;

                productItems.forEach(function (item) {
                    if (targetCat === 'all' || item.dataset.category === targetCat) {
                        item.classList.remove('d-none');
                    } else {
                        item.classList.add('d-none');
                    }
                });
            });
        });
    }

    // ==========================================
    // LOGIKA MODAL, TIMER & POLLING DUITKU POS
    // ==========================================
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
        if (timerEl) {
            timerEl.textContent = formatMMSS(remainingSeconds);
            timerEl.className = 'badge bg-danger text-white fs-5 fw-bold font-monospace px-3 py-1 shadow-sm';
        }

        countdownInterval = setInterval(function () {
            remainingSeconds--;
            if (timerEl) {
                timerEl.textContent = formatMMSS(Math.max(0, remainingSeconds));
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
            console.warn('Polling status Duitku kasir:', err);
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
            document.getElementById('success-display-amount').textContent = data.total_belanja_formatted || formatRupiah(data.total_belanja || currentTotal);
            document.getElementById('success-display-method').textContent = data.metode_pembayaran || 'DUITKU';
            document.getElementById('success-display-time').textContent = data.tanggal_pembayaran || '-';

            var receiptBtn = document.getElementById('success-btn-receipt');
            if (receiptBtn) {
                receiptBtn.href = data.redirect_url || data.receipt_url || ('cetak_struk.php?id=' + data.order_id + '&print=1');
            }
        }

        // Kosongkan keranjang belanja kasir karena transaksi telah lunas
        var cartLines = cart.querySelectorAll('.cart-item');
        cartLines.forEach(function (line) { line.remove(); });
        if (paymentInput) paymentInput.value = '';
        removeMember();
        updateTotals();
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
        if (vaEl) vaEl.textContent = vaNumber || '-';

        var vaBox = document.getElementById('box-va-number');
        var qrisBox = document.getElementById('box-qris-ref');

        if (vaNumber && vaNumber.trim() !== '' && vaNumber !== '-') {
            if (vaBox) vaBox.classList.remove('d-none');
            if (qrisBox) qrisBox.classList.add('d-none');
        } else if (reference && reference.trim() !== '') {
            if (qrisBox) qrisBox.classList.remove('d-none');
            if (vaBox) vaBox.classList.add('d-none');
        } else {
            if (vaBox) vaBox.classList.add('d-none');
            if (qrisBox) qrisBox.classList.remove('d-none');
        }

        var portalBtn = document.getElementById('btn-open-portal');
        if (portalBtn) {
            if (paymentUrl && paymentUrl.trim() !== '') {
                portalBtn.href = paymentUrl;
                portalBtn.classList.remove('d-none');
            } else {
                portalBtn.classList.add('d-none');
            }
        }

        var infoModalEl = document.getElementById('duitku-info-modal');
        if (infoModalEl) {
            bootstrap.Modal.getOrCreateInstance(infoModalEl).show();
        }

        startCountdown(durationSeconds || 600);
    }

    // Tombol Salin Nilai
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
    if (btnCopyRef) btnCopyRef.addEventListener('click', function () { copyText('modal-display-ref', btnCopyRef, 'Referensi Duitku'); });

    var btnCopyOrder = document.getElementById('btn-copy-order');
    if (btnCopyOrder) btnCopyOrder.addEventListener('click', function () { copyText('modal-display-order-id', btnCopyOrder, 'Order ID'); });

    // Handle Eksekusi Pembayaran Duitku via Fetch API
    function executeDuitkuPayment() {
        var cartLines = Array.from(cart.querySelectorAll('.cart-item'));
        var totalQty = 0;
        var formData = new FormData();

        var isRfidVerified = (activeMemberDiscountPercent === 10 && activeMemberData && memberCardId && memberCardId.value !== '');

        if (posCsrfToken) formData.append('csrf_token', posCsrfToken.value);
        formData.append('source', 'pos');
        formData.append('rfid_verified', isRfidVerified ? '1' : '0');
        if (customerSelect && customerSelect.value) formData.append('id_pelanggan', customerSelect.value);
        if (isRfidVerified && memberCardId && memberCardId.value) {
            formData.append('id_card_rfid', memberCardId.value);
        } else {
            formData.append('id_card_rfid', '');
        }

        var methodSelector = document.getElementById('duitku-payment-method');
        if (methodSelector) formData.append('payment_method', methodSelector.value);

        cartLines.forEach(function (item) {
            var input = item.querySelector('.cart-qty');
            var qty = parseInt(input.value, 10) || 0;
            if (qty > 0) {
                totalQty += qty;
                formData.append('qty[' + item.dataset.id + ']', qty);
            }
        });

        if (totalQty === 0) {
            window.alert('Keranjang belanja kasir masih kosong. Silakan masukkan minimal 1 produk dari katalog.');
            return;
        }

        var payDuitkuBtn = document.getElementById('pay-duitku-button');
        var origHtml = payDuitkuBtn ? payDuitkuBtn.innerHTML : '';
        if (payDuitkuBtn) {
            payDuitkuBtn.disabled = true;
            payDuitkuBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Menghubungkan ke Duitku Sandbox...';
        }

        fetch('proses_duitku.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (response) {
            return response.json().then(function (data) {
                return { status: response.status, data: data };
            });
        })
        .then(function (res) {
            if (payDuitkuBtn) {
                payDuitkuBtn.disabled = false;
                payDuitkuBtn.innerHTML = origHtml;
            }

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

                startStatusPolling(res.data.order_id, res.data.merchant_order_id);

                var demoBtn = document.getElementById('btn-open-demo');
                if (demoBtn && res.data.demo_simulator_url) {
                    demoBtn.href = res.data.demo_simulator_url;
                }
            } else {
                if (res.data.suggest_qris) {
                    var changeToQris = window.confirm(
                        (res.data.message || 'Nominal kecil tidak didukung kanal Virtual Account perbankan.') +
                        '\n\nBeralih ke QRIS (ShopeePay) yang mendukung nominal di bawah Rp 10.000?'
                    );
                    if (changeToQris && methodSelector) {
                        methodSelector.value = 'SP';
                        executeDuitkuPayment();
                        return;
                    }
                } else {
                    window.alert(res.data.message || 'Gagal memproses inisiasi pembayaran Duitku.');
                }
            }
        })
        .catch(function (error) {
            if (payDuitkuBtn) {
                payDuitkuBtn.disabled = false;
                payDuitkuBtn.innerHTML = origHtml;
            }
            console.error('Fetch Duitku kasir error:', error);
            window.alert('Gagal terhubung ke server Duitku Sandbox. Periksa koneksi jaringan.');
        });
    }

    var payDuitkuBtn = document.getElementById('pay-duitku-button');
    if (payDuitkuBtn) payDuitkuBtn.addEventListener('click', executeDuitkuPayment);

    // Stop polling & timer saat modal ditutup
    var infoModalEl = document.getElementById('duitku-info-modal');
    if (infoModalEl) {
        infoModalEl.addEventListener('hidden.bs.modal', function () {
            stopStatusPolling();
            stopCountdown();
        });
    }

    // Submit Validation pada POS Form (Khusus Tunai)
    posForm.addEventListener('submit', function (event) {
        if (payTypeDuitku && payTypeDuitku.checked) {
            event.preventDefault();
            executeDuitkuPayment();
            return false;
        }

        var items = Array.from(cart.querySelectorAll('.cart-item'));
        var hasItems = items.some(function (item) {
            return (parseInt(item.querySelector('.cart-qty').value, 10) || 0) > 0;
        });
        if (!hasItems) {
            event.preventDefault();
            window.alert('Keranjang belanja masih kosong. Pilih produk terlebih dahulu.');
            return false;
        }

        var payment = Math.max(0, parseInt(paymentInput ? paymentInput.value : 0, 10) || 0);
        if (payment < currentTotal) {
            event.preventDefault();
            window.alert('Uang pembayaran masih kurang dari total belanja! Total: ' + formatRupiah(currentTotal) + ', Diterima: ' + formatRupiah(payment));
            return false;
        }
    });

    // Inisialisasi awal
    updateTotals();
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>