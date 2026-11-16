<?php
require_once __DIR__ . '/check_session.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = valid_int($_POST['id_produk'] ?? null, 1);
    $addition = valid_int($_POST['tambah_stok'] ?? null, 1);
    if ($id === null || $addition === null) {
        flash('danger', 'Produk dan jumlah penambahan stok harus valid (minimal 1 unit).');
        redirect('inventory.php');
    }
    $stmt = $pdo->prepare('UPDATE tbl_produk SET stok = stok + ? WHERE id_produk = ?');
    $stmt->execute([$addition, $id]);
    flash($stmt->rowCount() ? 'success' : 'warning', $stmt->rowCount() ? 'Stok produk berhasil ditambah sebanyak ' . $addition . ' unit.' : 'Produk tidak ditemukan atau stok tidak berubah.');
    redirect('inventory.php');
}

$search = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT p.id_produk, p.nama_produk, p.stok, p.harga, k.nama_kategori 
        FROM tbl_produk p 
        JOIN tbl_kategori k ON k.id_kategori = p.id_kategori';
$params = [];
if ($search !== '') {
    $sql .= ' WHERE p.nama_produk LIKE ? OR k.nama_kategori LIKE ?';
    $params = ['%' . $search . '%', '%' . $search . '%'];
}
$sql .= ' ORDER BY p.stok ASC, p.nama_produk';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$lowCount = (int) $pdo->query('SELECT COUNT(*) FROM tbl_produk WHERE stok < 5')->fetchColumn();
$totalUnits = (int) $pdo->query('SELECT COALESCE(SUM(stok), 0) FROM tbl_produk')->fetchColumn();

$pageTitle = 'Inventory';
require __DIR__ . '/includes/header.php';
?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="stat-card card-sky">
            <div class="stat-content">
                <span class="stat-label">Total Jenis Produk</span>
                <div class="stat-value text-info"><?= count($products) ?> <span class="fs-6 fw-normal text-muted">SKU</span></div>
                <div class="stat-note"><i class="bi bi-box-seam text-info"></i> Seluruh produk dalam katalog</div>
            </div>
            <div class="stat-icon icon-sky">
                <i class="bi bi-boxes"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card card-amber">
            <div class="stat-content">
                <span class="stat-label">Stok Kritis / Menipis</span>
                <div class="stat-value text-warning"><?= $lowCount ?> <span class="fs-6 fw-normal text-muted">Item</span></div>
                <div class="stat-note"><i class="bi bi-exclamation-triangle text-warning"></i> Stok di bawah 5 pcs</div>
            </div>
            <div class="stat-icon icon-amber">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card card-emerald">
            <div class="stat-content">
                <span class="stat-label">Total Unit Fisik Toko</span>
                <div class="stat-value text-success"><?= number_format($totalUnits, 0, ',', '.') ?> <span class="fs-6 fw-normal text-muted">Unit</span></div>
                <div class="stat-note"><i class="bi bi-check2-circle text-success"></i> Akumulasi ketersediaan fisik</div>
            </div>
            <div class="stat-icon icon-emerald">
                <i class="bi bi-layers-fill"></i>
            </div>
        </div>
    </div>
</div>

<?php if ($lowCount > 0): ?>
    <div class="alert-stock mb-4">
        <div class="alert-stock-icon">
            <i class="bi bi-exclamation-triangle-fill"></i>
        </div>
        <div class="flex-grow-1">
            <strong>Perhatian: Butuh Restock Segera!</strong>
            <span>Terdapat <strong><?= $lowCount ?> produk</strong> dengan sisa stok kurang dari 5 pcs. Masukkan kuantitas stok baru pada kolom Tambah Stok di bawah barang terkait.</span>
        </div>
    </div>
<?php endif; ?>

<!-- Inventory Monitoring Panel -->
<div class="card-panel">
    <div class="panel-heading">
        <div>
            <h2 class="section-title">
                <i class="bi bi-clipboard2-pulse-fill text-primary"></i>
                Pemantauan &amp; Penyesuaian Stok
            </h2>
            <div class="section-subtitle">Stok tersinkronisasi otomatis dengan setiap transaksi kasir</div>
        </div>
        
        <form class="filter-bar" method="get">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0 text-muted">
                    <i class="bi bi-search"></i>
                </span>
                <input class="form-control border-start-0 ps-0" 
                       type="search" 
                       name="q" 
                       value="<?= e($search) ?>" 
                       placeholder="Cari produk...">
            </div>
            <button class="btn btn-primary btn-sm" type="submit">
                <i class="bi bi-search me-1"></i> Cari
            </button>
            <?php if ($search !== ''): ?>
                <a href="inventory.php" class="btn btn-light btn-sm text-muted">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Nama Produk</th>
                    <th>Kategori</th>
                    <th>Harga Jual</th>
                    <th>Stok Tersedia</th>
                    <th>Status Stok</th>
                    <th style="min-width: 220px;">Tambah Stok Masuk</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$products): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <i class="bi bi-box2"></i>
                            <div class="empty-state-text">
                                Tidak ada data produk yang ditemukan pada inventaris.
                            </div>
                        </div>
                    </td>
                </tr>
            <?php else: foreach ($products as $product): ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="avatar" style="width: 32px; height: 32px; font-size: 13px; background: #eef2ff; color: #4f46e5; border-radius: 8px;">
                                <i class="bi bi-box-seam"></i>
                            </div>
                            <strong class="product-name"><?= e($product['nama_produk']) ?></strong>
                        </div>
                    </td>
                    <td>
                        <span class="badge-soft badge-indigo">
                            <?= e($product['nama_kategori']) ?>
                        </span>
                    </td>
                    <td>
                        <strong class="text-slate-800"><?= rupiah($product['harga']) ?></strong>
                    </td>
                    <td>
                        <span class="fs-6 fw-bold <?= (int) $product['stok'] < 5 ? 'text-danger' : 'text-slate-800' ?>">
                            <?= (int) $product['stok'] ?> pcs
                        </span>
                    </td>
                    <td>
                        <?php if ((int) $product['stok'] === 0): ?>
                            <span class="badge-soft badge-rose">
                                <i class="bi bi-x-circle-fill me-1"></i> Habis
                            </span>
                        <?php elseif ((int) $product['stok'] < 5): ?>
                            <span class="badge-soft badge-amber">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> Menipis
                            </span>
                        <?php else: ?>
                            <span class="badge-soft badge-emerald">
                                <i class="bi bi-check-circle-fill me-1"></i> Aman
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" class="d-flex align-items-center gap-2">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                            <div class="input-group input-group-sm" style="max-width: 120px;">
                                <input class="form-control" 
                                       type="number" 
                                       name="tambah_stok" 
                                       min="1" 
                                       step="1" 
                                       required 
                                       placeholder="+Qty">
                                <span class="input-group-text">pcs</span>
                            </div>
                            <button class="btn btn-sm btn-outline-primary text-nowrap" type="submit">
                                <i class="bi bi-plus-lg me-1"></i> Tambah
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
