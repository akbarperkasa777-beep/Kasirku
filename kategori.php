<?php
require_once __DIR__ . '/check_session.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $name = trim((string) ($_POST['nama_kategori'] ?? ''));

    if ($action === 'delete') {
        $id = valid_int($_POST['id_kategori'] ?? null, 1);
        try {
            $stmt = $pdo->prepare('DELETE FROM tbl_kategori WHERE id_kategori = ?');
            $stmt->execute([$id]);
            flash($stmt->rowCount() ? 'success' : 'warning', $stmt->rowCount() ? 'Kategori berhasil dihapus dari sistem.' : 'Kategori tidak ditemukan.');
        } catch (PDOException $exception) {
            flash('danger', 'Kategori tidak dapat dihapus karena masih digunakan oleh beberapa produk aktif.');
        }
        redirect('kategori.php');
    }

    if ($name === '' || mb_strlen($name) > 100) {
        flash('danger', 'Nama kategori wajib diisi (maksimal 100 karakter).');
        redirect('kategori.php');
    }

    $id = $action === 'update' ? valid_int($_POST['id_kategori'] ?? null, 1) : null;
    if ($action === 'update' && $id === null) {
        flash('danger', 'Kategori yang akan diperbarui tidak valid.');
        redirect('kategori.php');
    }

    $duplicateCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM tbl_kategori WHERE nama_kategori = ? AND (? IS NULL OR id_kategori <> ?)'
    );
    $duplicateCheck->execute([$name, $id, $id]);
    if ((int) $duplicateCheck->fetchColumn() > 0) {
        flash('danger', 'Nama kategori "' . $name . '" sudah digunakan. Gunakan nama yang berbeda.');
        redirect($id !== null ? 'kategori.php?edit=' . $id : 'kategori.php');
    }

    try {
        if ($action === 'update') {
            $stmt = $pdo->prepare('UPDATE tbl_kategori SET nama_kategori = ? WHERE id_kategori = ?');
            $stmt->execute([$name, $id]);
            flash('success', 'Kategori berhasil diperbarui menjadi "' . $name . '".');
        } else {
            $stmt = $pdo->prepare('INSERT INTO tbl_kategori (nama_kategori) VALUES (?)');
            $stmt->execute([$name]);
            flash('success', 'Kategori baru "' . $name . '" berhasil ditambahkan.');
        }
    } catch (PDOException $exception) {
        flash('danger', 'Kategori sudah ada atau perubahan tidak dapat disimpan.');
    }
    redirect('kategori.php');
}

$editCategory = null;
if (isset($_GET['edit'])) {
    $id = valid_int($_GET['edit'], 1);
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT * FROM tbl_kategori WHERE id_kategori = ?');
        $stmt->execute([$id]);
        $editCategory = $stmt->fetch() ?: null;
    }
}

$categories = $pdo->query(
    'SELECT k.id_kategori, k.nama_kategori, COUNT(p.id_produk) AS jumlah_produk
     FROM tbl_kategori k 
     LEFT JOIN tbl_produk p ON p.id_kategori = k.id_kategori
     GROUP BY k.id_kategori 
     ORDER BY k.nama_kategori'
)->fetchAll();

$pageTitle = 'Kategori';
require __DIR__ . '/includes/header.php';
?>

<div class="row g-4">
    <!-- Left Column: Add / Edit Form -->
    <div class="col-lg-4">
        <div class="card-panel">
            <div class="panel-heading">
                <div>
                    <h2 class="section-title">
                        <i class="bi <?= $editCategory ? 'bi-pencil-square text-primary' : 'bi-folder-plus text-primary' ?>"></i>
                        <?= $editCategory ? 'Edit Kategori' : 'Tambah Kategori' ?>
                    </h2>
                    <div class="section-subtitle">Kelompokkan produk agar mudah ditemukan di kasir</div>
                </div>
                <?php if ($editCategory): ?>
                    <span class="badge-soft badge-amber">Mode Edit</span>
                <?php endif; ?>
            </div>

            <div class="panel-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $editCategory ? 'update' : 'create' ?>">
                    <?php if ($editCategory): ?>
                        <input type="hidden" name="id_kategori" value="<?= (int) $editCategory['id_kategori'] ?>">
                    <?php endif; ?>

                    <div class="mb-4">
                        <label class="form-label">
                            <i class="bi bi-tag text-muted me-1"></i> Nama Kategori
                        </label>
                        <input class="form-control" 
                               name="nama_kategori" 
                               maxlength="100" 
                               required 
                               value="<?= e($editCategory['nama_kategori'] ?? '') ?>" 
                               placeholder="Contoh: Makanan, Minuman, Snack">
                        <small class="text-muted d-block mt-1">Gunakan nama yang jelas dan singkat.</small>
                    </div>

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary flex-grow-1" type="submit">
                            <i class="bi bi-check-circle me-1"></i>
                            <?= $editCategory ? 'Simpan Perubahan' : 'Tambah Kategori' ?>
                        </button>
                        <?php if ($editCategory): ?>
                            <a href="kategori.php" class="btn btn-light">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Category Table -->
    <div class="col-lg-8">
        <div class="card-panel">
            <div class="panel-heading">
                <div>
                    <h2 class="section-title">
                        <i class="bi bi-tags-fill text-primary"></i>
                        Daftar Kategori
                    </h2>
                    <div class="section-subtitle"><?= count($categories) ?> kategori aktif terdaftar</div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Nama Kategori</th>
                            <th>Total Produk Terkait</th>
                            <th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$categories): ?>
                        <tr>
                            <td colspan="3">
                                <div class="empty-state">
                                    <i class="bi bi-tags"></i>
                                    <div class="empty-state-text">
                                        Belum ada kategori yang ditambahkan. Buat kategori pertama Anda melalui formulir di sebelah kiri.
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php else: foreach ($categories as $category): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar" style="width: 32px; height: 32px; font-size: 13px; background: #eef2ff; color: #4f46e5; border-radius: 8px;">
                                        <i class="bi bi-tag-fill"></i>
                                    </div>
                                    <strong class="product-name"><?= e($category['nama_kategori']) ?></strong>
                                </div>
                            </td>
                            <td>
                                <span class="badge-soft badge-indigo">
                                    <i class="bi bi-box-seam me-1"></i> <?= (int) $category['jumlah_produk'] ?> Produk
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <a class="btn btn-sm btn-icon btn-light" 
                                       href="kategori.php?edit=<?= (int) $category['id_kategori'] ?>" 
                                       title="Edit Kategori" 
                                       aria-label="Edit">
                                        <i class="bi bi-pencil-fill text-primary"></i>
                                    </a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id_kategori" value="<?= (int) $category['id_kategori'] ?>">
                                        <button class="btn btn-sm btn-icon btn-light text-danger" 
                                                data-confirm="Hapus kategori '<?= e($category['nama_kategori']) ?>'? Perhatian: Kategori yang memiliki produk tidak dapat dihapus." 
                                                title="Hapus Kategori" 
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
