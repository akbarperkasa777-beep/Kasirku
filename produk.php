<?php
require_once __DIR__ . '/check_session.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = valid_int($_POST['id_produk'] ?? null, 1);
        if ($id === null) {
            flash('danger', 'Produk tidak valid.');
            redirect('produk.php');
        }
        try {
            $imageQuery = $pdo->prepare('SELECT gambar FROM tbl_produk WHERE id_produk = ?');
            $imageQuery->execute([$id]);
            $imageName = $imageQuery->fetchColumn();
            $stmt = $pdo->prepare('DELETE FROM tbl_produk WHERE id_produk = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() > 0) {
                if (!delete_product_image($imageName)) {
                    error_log('Gagal menghapus gambar produk: ' . (string) $imageName);
                    flash('warning', 'Produk terhapus, tetapi file gambarnya gagal dihapus.');
                } else {
                    flash('success', 'Produk berhasil dihapus dari sistem.');
                }
            } else {
                flash('warning', 'Produk tidak ditemukan.');
            }
        } catch (PDOException $exception) {
            flash('danger', 'Produk tidak dapat dihapus karena sudah tercatat dalam riwayat transaksi kasir.');
        }
        redirect('produk.php');
    }

    $name = trim((string) ($_POST['nama_produk'] ?? ''));
    $barcodeValue = trim((string) ($_POST['kode_barcode'] ?? ''));
    $barcode = $barcodeValue === '' ? null : $barcodeValue;
    $category = valid_int($_POST['id_kategori'] ?? null, 1);
    $priceRaw = valid_int($_POST['harga'] ?? null);
    $stock = valid_int($_POST['stok'] ?? null);
    $id = valid_int($_POST['id_produk'] ?? null, 1);

    if (
        $name === ''
        || mb_strlen($name) > 150
        || ($barcode !== null && (mb_strlen($barcode) > 50 || preg_match('/[\x00-\x1F\x7F]/', $barcode)))
        || $category === null
        || $priceRaw === null
        || $stock === null
    ) {
        flash('danger', 'Data produk tidak valid. Periksa nama, barcode (maksimal 50 karakter), kategori, harga, dan stok.');
        redirect($id ? 'produk.php?edit=' . $id : 'produk.php');
    }

    $categoryExists = $pdo->prepare('SELECT COUNT(*) FROM tbl_kategori WHERE id_kategori = ?');
    $categoryExists->execute([$category]);
    if (!(int) $categoryExists->fetchColumn()) {
        flash('danger', 'Kategori yang dipilih tidak valid atau tidak ditemukan.');
        redirect('produk.php');
    }

    if ($barcode !== null) {
        $barcodeCheckSql = 'SELECT COUNT(*) FROM tbl_produk WHERE kode_barcode = ?';
        $barcodeCheckParams = [$barcode];
        if ($action === 'update' && $id !== null) {
            $barcodeCheckSql .= ' AND id_produk <> ?';
            $barcodeCheckParams[] = $id;
        }
        $barcodeCheck = $pdo->prepare($barcodeCheckSql);
        $barcodeCheck->execute($barcodeCheckParams);
        if ((int) $barcodeCheck->fetchColumn() > 0) {
            flash('danger', 'Kode barcode sudah digunakan oleh produk lain.');
            redirect($id ? 'produk.php?edit=' . $id : 'produk.php');
        }
    }

    $oldImageName = null;
    if ($action === 'update' && $id !== null) {
        $imageQuery = $pdo->prepare('SELECT gambar FROM tbl_produk WHERE id_produk = ?');
        $imageQuery->execute([$id]);
        $oldImageName = $imageQuery->fetchColumn();
        if ($oldImageName === false) {
            flash('danger', 'Produk yang akan diperbarui tidak ditemukan.');
            redirect('produk.php');
        }
    }

    $newImageName = null;
    if (isset($_FILES['gambar'])) {
        $upload = $_FILES['gambar'];
        if (!is_array($upload) || !isset($upload['error']) || is_array($upload['error'])) {
            flash('danger', 'Data upload gambar tidak valid.');
            redirect($id ? 'produk.php?edit=' . $id : 'produk.php');
        }

        $uploadError = (int) $upload['error'];
        if ($uploadError !== UPLOAD_ERR_NO_FILE) {
            if ($uploadError !== UPLOAD_ERR_OK) {
                flash('danger', $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
                    ? 'Ukuran gambar terlalu besar. Batas maksimal 5 MB.'
                    : 'Gambar gagal diunggah. Silakan pilih file kembali.');
                redirect($id ? 'produk.php?edit=' . $id : 'produk.php');
            }

            $temporaryPath = (string) ($upload['tmp_name'] ?? '');
            $originalName = (string) ($upload['name'] ?? '');
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $allowedImages = [
                'jpg' => ['mime' => 'image/jpeg', 'type' => IMAGETYPE_JPEG, 'saved_extension' => 'jpg'],
                'jpeg' => ['mime' => 'image/jpeg', 'type' => IMAGETYPE_JPEG, 'saved_extension' => 'jpg'],
                'png' => ['mime' => 'image/png', 'type' => IMAGETYPE_PNG, 'saved_extension' => 'png'],
                'webp' => ['mime' => 'image/webp', 'type' => IMAGETYPE_WEBP, 'saved_extension' => 'webp'],
            ];
            $fileSize = is_file($temporaryPath) ? filesize($temporaryPath) : false;
            $imageInfo = is_uploaded_file($temporaryPath) ? @getimagesize($temporaryPath) : false;

            if (
                !isset($allowedImages[$extension])
                || $fileSize === false
                || $fileSize < 1
                || $fileSize > 5 * 1024 * 1024
                || !is_array($imageInfo)
                || $imageInfo[2] !== $allowedImages[$extension]['type']
                || !class_exists('finfo')
            ) {
                flash('danger', 'Gambar harus berupa JPG, PNG, atau WEBP yang valid dengan ukuran maksimal 5 MB.');
                redirect($id ? 'produk.php?edit=' . $id : 'produk.php');
            }

            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            if ($fileInfo->file($temporaryPath) !== $allowedImages[$extension]['mime']) {
                flash('danger', 'Isi file tidak sesuai dengan format gambar yang diizinkan.');
                redirect($id ? 'produk.php?edit=' . $id : 'produk.php');
            }

            if (
                (int) $imageInfo[0] < 1
                || (int) $imageInfo[1] < 1
                || (int) $imageInfo[0] > 8000
                || (int) $imageInfo[1] > 8000
                || (int) $imageInfo[0] * (int) $imageInfo[1] > 40000000
            ) {
                flash('danger', 'Dimensi gambar terlalu besar. Gunakan gambar maksimal 8000 × 8000 piksel.');
                redirect($id ? 'produk.php?edit=' . $id : 'produk.php');
            }

            $uploadDirectory = __DIR__ . '/uploads/produk';
            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                flash('danger', 'Folder penyimpanan gambar tidak dapat dibuat.');
                redirect($id ? 'produk.php?edit=' . $id : 'produk.php');
            }

            $newImageName = bin2hex(random_bytes(16)) . '.' . $allowedImages[$extension]['saved_extension'];
            if (!move_uploaded_file($temporaryPath, $uploadDirectory . '/' . $newImageName)) {
                $newImageName = null;
                flash('danger', 'Gambar gagal disimpan ke folder produk.');
                redirect($id ? 'produk.php?edit=' . $id : 'produk.php');
            }
        }
    }

    $imageName = $newImageName ?? ($oldImageName === false ? null : $oldImageName);
    try {
        if ($action === 'update' && $id !== null) {
            $stmt = $pdo->prepare('UPDATE tbl_produk SET nama_produk = ?, id_kategori = ?, kode_barcode = ?, gambar = ?, harga = ?, stok = ? WHERE id_produk = ?');
            $stmt->execute([$name, $category, $barcode, $imageName, $priceRaw, $stock, $id]);
            $successMessage = 'Data produk "' . $name . '" berhasil diperbarui.';
        } elseif ($action === 'create') {
            $stmt = $pdo->prepare('INSERT INTO tbl_produk (nama_produk, id_kategori, kode_barcode, gambar, harga, stok) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $category, $barcode, $imageName, $priceRaw, $stock]);
            $successMessage = 'Produk baru "' . $name . '" berhasil ditambahkan.';
        } else {
            if ($newImageName !== null && !delete_product_image($newImageName)) {
                error_log('Gagal membersihkan gambar produk setelah tindakan tidak valid: ' . $newImageName);
            }
            flash('danger', 'Tindakan tidak dikenali.');
            redirect('produk.php');
        }
    } catch (PDOException $exception) {
        if ($newImageName !== null && !delete_product_image($newImageName)) {
            error_log('Gagal membersihkan upload gambar produk yang tidak tersimpan: ' . $newImageName);
        }
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            flash('danger', 'Kode barcode sudah digunakan oleh produk lain.');
            redirect($id ? 'produk.php?edit=' . $id : 'produk.php');
        }
        throw $exception;
    }

    if ($newImageName !== null && $oldImageName !== null && $oldImageName !== false && !delete_product_image($oldImageName)) {
        error_log('Gagal menghapus gambar lama produk: ' . (string) $oldImageName);
        flash('warning', $successMessage . ' Namun, gambar lama gagal dihapus dari penyimpanan.');
    } else {
        flash('success', $successMessage);
    }
    redirect('produk.php');
}

$editProduct = null;
if (isset($_GET['edit'])) {
    $editId = valid_int($_GET['edit'], 1);
    if ($editId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM tbl_produk WHERE id_produk = ?');
        $stmt->execute([$editId]);
        $editProduct = $stmt->fetch() ?: null;
    }
}

$categories = $pdo->query('SELECT id_kategori, nama_kategori FROM tbl_kategori ORDER BY nama_kategori')->fetchAll();
$editImageName = safe_product_image_name($editProduct['gambar'] ?? null);
$search = trim((string) ($_GET['q'] ?? ''));
$query = "SELECT pr.*, ka.nama_kategori FROM tbl_produk pr JOIN tbl_kategori ka ON ka.id_kategori = pr.id_kategori";
$params = [];
if ($search !== '') {
    $query .= ' WHERE pr.nama_produk LIKE ? OR ka.nama_kategori LIKE ? OR pr.kode_barcode LIKE ?';
    $params = ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%'];
}
$query .= ' ORDER BY pr.nama_produk';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll();

$pageTitle = 'Produk';
require __DIR__ . '/includes/header.php';
?>

<div class="row g-4">
    <!-- Left Column: Add / Edit Product Form -->
    <div class="col-xl-4">
        <div class="card-panel">
            <div class="panel-heading">
                <div>
                    <h2 class="section-title">
                        <i class="bi <?= $editProduct ? 'bi-pencil-square text-primary' : 'bi-plus-circle-fill text-primary' ?>"></i>
                        <?= $editProduct ? 'Edit Informasi Produk' : 'Tambah Produk Baru' ?>
                    </h2>
                    <div class="section-subtitle">
                        <?= $editProduct ? 'Perbarui rincian harga atau stok produk' : 'Lengkapi detail produk untuk inventaris toko' ?>
                    </div>
                </div>
                <?php if ($editProduct): ?>
                    <span class="badge-soft badge-amber">Mode Edit</span>
                <?php endif; ?>
            </div>

            <div class="panel-body">
                <?php if (!$categories): ?>
                    <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-exclamation-circle-fill fs-5"></i>
                        <div>Belum ada kategori. Tambahkan kategori terlebih dahulu di menu <strong>Kategori</strong>.</div>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $editProduct ? 'update' : 'create' ?>">
                    <?php if ($editProduct): ?>
                        <input type="hidden" name="id_produk" value="<?= (int) $editProduct['id_produk'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-tag text-muted me-1"></i> Nama Produk
                        </label>
                        <input class="form-control" 
                               name="nama_produk" 
                               maxlength="150" 
                               required 
                               value="<?= e($editProduct['nama_produk'] ?? '') ?>" 
                               placeholder="Contoh: Indomie Goreng Spesial">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="product-barcode">
                            <i class="bi bi-upc text-muted me-1"></i> Kode Barcode <span class="text-muted fw-normal">(opsional)</span>
                        </label>
                        <div class="input-group">
                            <input
                                class="form-control"
                                id="product-barcode"
                                name="kode_barcode"
                                type="text"
                                maxlength="50"
                                autocomplete="off"
                                value="<?= e($editProduct['kode_barcode'] ?? '') ?>"
                                placeholder="Scan atau ketik kode barcode"
                            >
                            <button class="btn btn-outline-secondary" type="button" data-generate-barcode>
                                <i class="bi bi-magic me-1"></i> Generate
                            </button>
                        </div>
                        <div class="form-text">Kode harus unik. Produk tanpa barcode tetap dapat dijual dari katalog POS.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="product-image">
                            <i class="bi bi-image text-muted me-1"></i> Gambar Produk <span class="text-muted fw-normal">(opsional)</span>
                        </label>
                        <input
                            class="form-control"
                            id="product-image"
                            name="gambar"
                            type="file"
                            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                        >
                        <div class="form-text">Format JPG, PNG, atau WEBP; ukuran maksimal 5 MB.</div>
                        <?php if ($editImageName !== null && is_file(__DIR__ . '/uploads/produk/' . $editImageName)): ?>
                            <div class="current-product-image mt-2">
                                <img src="<?= e('uploads/produk/' . $editImageName) ?>" alt="Gambar saat ini: <?= e($editProduct['nama_produk']) ?>">
                                <span class="small text-muted">Gambar saat ini. Pilih file baru untuk menggantinya.</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-folder2 text-muted me-1"></i> Kategori Produk
                        </label>
                        <select class="form-select" name="id_kategori" required>
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id_kategori'] ?>" <?= (int) ($editProduct['id_kategori'] ?? 0) === (int) $category['id_kategori'] ? 'selected' : '' ?>>
                                    <?= e($category['nama_kategori']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-cash-stack text-muted me-1"></i> Harga Jual Satuan
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">Rp</span>
                            <input type="number" 
                                   class="form-control" 
                                   name="harga" 
                                   min="0" 
                                   step="1" 
                                   required 
                                   value="<?= $editProduct ? (int) $editProduct['harga'] : '' ?>" 
                                   placeholder="Contoh: 15000">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="bi bi-boxes text-muted me-1"></i> Jumlah Stok
                        </label>
                        <div class="input-group">
                            <input type="number" 
                                   class="form-control" 
                                   name="stok" 
                                   min="0" 
                                   step="1" 
                                   required 
                                   value="<?= e($editProduct['stok'] ?? '0') ?>">
                            <span class="input-group-text">pcs</span>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary flex-grow-1" type="submit" <?= !$categories ? 'disabled' : '' ?>>
                            <i class="bi bi-check-circle me-1"></i>
                            <?= $editProduct ? 'Simpan Perubahan' : 'Tambah Produk' ?>
                        </button>
                        <?php if ($editProduct): ?>
                            <a href="produk.php" class="btn btn-light">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Product List Table -->
    <div class="col-xl-8">
        <div class="card-panel">
            <div class="panel-heading">
                <div>
                    <h2 class="section-title">
                        <i class="bi bi-box-seam text-primary"></i>
                        Daftar Produk
                    </h2>
                    <div class="section-subtitle"><?= count($products) ?> jenis produk terdaftar dalam katalog</div>
                </div>
            </div>

            <!-- Search Filter -->
            <div class="panel-body pb-0">
                <form class="filter-bar mb-3" method="get">
                    <div class="search-field">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0 text-muted">
                                <i class="bi bi-search"></i>
                            </span>
                            <input class="form-control border-start-0 ps-0" 
                                   type="search" 
                                   name="q" 
                                   value="<?= e($search) ?>" 
                                   placeholder="Cari berdasarkan nama produk atau kategori...">
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">
                        <i class="bi bi-search me-1"></i> Cari
                    </button>
                    <?php if ($search !== ''): ?>
                        <a href="produk.php" class="btn btn-light btn-sm text-muted">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Table -->
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Nama Produk</th>
                            <th>Barcode</th>
                            <th>Kategori</th>
                            <th>Harga Jual</th>
                            <th>Stok Fisik</th>
                            <th>Status</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$products): ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="bi bi-box"></i>
                                    <div class="empty-state-text">
                                        Tidak ada produk yang sesuai dengan kriteria pencarian.
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php else: foreach ($products as $product): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php $imageName = safe_product_image_name($product['gambar'] ?? null); ?>
                                    <?php if ($imageName !== null && is_file(__DIR__ . '/uploads/produk/' . $imageName)): ?>
                                        <img class="product-thumbnail" src="<?= e('uploads/produk/' . $imageName) ?>" alt="Gambar <?= e($product['nama_produk']) ?>" loading="lazy">
                                    <?php else: ?>
                                        <div class="avatar product-thumbnail-placeholder">
                                            <i class="bi bi-box-seam"></i>
                                        </div>
                                    <?php endif; ?>
                                    <strong class="product-name"><?= e($product['nama_produk']) ?></strong>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($product['kode_barcode'])): ?>
                                    <code class="product-barcode"><?= e($product['kode_barcode']) ?></code>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
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
                                <span class="fw-semibold"><?= (int) $product['stok'] ?> pcs</span>
                            </td>
                            <td>
                                <?php if ((int) $product['stok'] < 5): ?>
                                    <span class="badge-soft badge-amber">
                                        <i class="bi bi-exclamation-circle-fill me-1"></i> Menipis
                                    </span>
                                <?php else: ?>
                                    <span class="badge-soft badge-emerald">
                                        <i class="bi bi-check-circle-fill me-1"></i> Aman
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <a class="btn btn-sm btn-icon btn-light" 
                                       href="produk.php?edit=<?= (int) $product['id_produk'] ?>" 
                                       title="Edit Produk" 
                                       aria-label="Edit">
                                        <i class="bi bi-pencil-fill text-primary"></i>
                                    </a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                                        <button class="btn btn-sm btn-icon btn-light text-danger" 
                                                data-confirm="Apakah Anda yakin ingin menghapus produk '<?= e($product['nama_produk']) ?>'?" 
                                                title="Hapus Produk" 
                                                aria-label="Hapus">
                                            <i class="bi bi-trash3-fill"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
