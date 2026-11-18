<?php
require_once __DIR__ . '/check_session.php';
require_role(['admin', 'kasir']);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
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
        error_log('Checkout gagal: ' . $exception->getMessage());
        flash('danger', 'Transaksi tidak dapat diproses. Silakan periksa koneksi database dan coba kembali.');
        redirect('pos.php');
    }
}

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

$pageTitle = 'POS / Kasir';
require __DIR__ . '/includes/header.php';
?>

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
                    <div class="section-subtitle">Daftar item belanja pelanggan saat ini</div>
                </div>
                <span class="badge-soft badge-indigo" id="cart-count">0 item</span>
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
                            <input class="form-control" name="nama_kasir" value="<?= e($_SESSION['nama']) ?>" maxlength="100" readonly>
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

                    <!-- Payment Section -->
                    <div class="mt-3">
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
                    <div class="summary-row mt-3 p-2 rounded" style="background: #ffffff; border: 1px solid var(--border);">
                        <span class="fw-semibold"><i class="bi bi-arrow-repeat text-muted me-1"></i> Kembalian:</span>
                        <strong class="fs-5 text-muted" id="change-label">Rp 0</strong>
                    </div>

                    <!-- Submit Button -->
                    <button class="btn btn-primary btn-lg w-100 mt-3" type="submit">
                        <i class="bi bi-printer-fill me-2"></i> Proses Pembayaran &amp; Cetak
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
