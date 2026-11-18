<?php
require_once __DIR__ . '/check_session.php';
require_role(['admin', 'kasir']);
$isAdmin = $_SESSION['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'delete') {
        if (!$isAdmin) {
            http_response_code(403);
            exit('Kasir tidak memiliki izin untuk menghapus member.');
        }
        $id = valid_int($_POST['id_pelanggan'] ?? null, 1);
        if ($id === null) {
            flash('danger', 'Data pelanggan yang akan dihapus tidak valid.');
            redirect('admin_pelanggan.php');
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM tbl_pelanggan WHERE id_pelanggan = ?');
            $stmt->execute([$id]);
            flash(
                $stmt->rowCount() > 0 ? 'success' : 'warning',
                $stmt->rowCount() > 0 ? 'Data pelanggan berhasil dihapus.' : 'Data pelanggan tidak ditemukan.'
            );
        } catch (PDOException $exception) {
            error_log('Penghapusan pelanggan gagal: ' . $exception->getMessage());
            flash('danger', 'Data pelanggan tidak dapat dihapus. Periksa apakah data tersebut masih digunakan.');
        }
        redirect('admin_pelanggan.php');
    }

    $nameInput = $_POST['nama_pelanggan'] ?? '';
    $phoneInput = $_POST['no_telepon'] ?? '';
    $cardInput = $_POST['id_card_rfid'] ?? '';
    $name = is_string($nameInput) ? trim($nameInput) : '';
    $phone = is_string($phoneInput) ? trim($phoneInput) : '';
    $card = is_string($cardInput) ? trim($cardInput) : '';
    $id = $action === 'update' ? valid_int($_POST['id_pelanggan'] ?? null, 1) : null;
    $returnUrl = $id !== null ? 'admin_pelanggan.php?edit=' . $id : 'admin_pelanggan.php?tambah=1';
    $accountUsernameInput = $_POST['username_pelanggan'] ?? '';
    $accountPasswordInput = $_POST['password_pelanggan'] ?? '';
    $accountUsername = is_string($accountUsernameInput) ? trim($accountUsernameInput) : '';
    $accountPassword = is_string($accountPasswordInput) ? $accountPasswordInput : '';
    $existingAccount = null;
    if ($action === 'update' && $id !== null) {
        if (!$isAdmin) {
            http_response_code(403);
            exit('Kasir hanya dapat menambahkan member, bukan mengedit data yang sudah ada.');
        }
        $accountQuery = $pdo->prepare(
            "SELECT id_user, username FROM tbl_user WHERE id_pelanggan = ? AND role = 'pelanggan' LIMIT 1"
        );
        $accountQuery->execute([$id]);
        $existingAccount = $accountQuery->fetch() ?: null;
    }

    $validUsername = preg_match('/\A[A-Za-z0-9._-]{3,50}\z/D', $accountUsername) === 1;
    $validPassword = strlen($accountPassword) >= 12 && strlen($accountPassword) <= 72;
    $accountInputValid = true;
    if ($action === 'create') {
        $accountInputValid = $validUsername && $validPassword;
    } elseif ($action === 'update' && $existingAccount) {
        $accountInputValid = $accountPassword === '' || $validPassword;
        $accountUsername = $existingAccount['username'];
    } elseif ($action === 'update' && ($accountUsername !== '' || $accountPassword !== '')) {
        $accountInputValid = $validUsername && $validPassword;
    }

    if (
        !in_array($action, ['create', 'update'], true)
        || ($action === 'update' && $id === null)
        || $name === ''
        || mb_strlen($name) > 150
        || preg_match('/[\x00-\x1F\x7F]/', $name)
        || !preg_match('/\A[0-9+() \-]{8,20}\z/D', $phone)
        || strlen((string) preg_replace('/\D/', '', $phone)) < 8
        || $card === ''
        || mb_strlen($card) > 50
        || preg_match('/[\x00-\x1F\x7F]/', $card)
        || !$accountInputValid
    ) {
        flash('danger', 'Periksa nama, nomor telepon, RFID unik, username (3–50 karakter), serta password awal (12–72 karakter).');
        redirect($returnUrl);
    }

    if ($action === 'create') {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "INSERT INTO tbl_pelanggan (nama_pelanggan, no_telepon, id_card_rfid, status_member)
                 VALUES (?, ?, ?, 'aktif')"
            );
            $stmt->execute([$name, $phone, $card]);
            $customerId = (int) $pdo->lastInsertId();
            $accountInsert = $pdo->prepare(
                "INSERT INTO tbl_user (nama_lengkap, username, password, role, id_pelanggan)
                 VALUES (?, ?, ?, 'pelanggan', ?)"
            );
            $accountInsert->execute([$name, $accountUsername, password_hash($accountPassword, PASSWORD_DEFAULT), $customerId]);
            $pdo->commit();
            flash('success', 'Member dan akun belanja pelanggan berhasil dibuat. Serahkan username dan password awal kepada pelanggan.');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                flash('danger', 'Username atau ID Card RFID sudah digunakan.');
            } else {
                error_log('Penambahan pelanggan gagal: ' . $exception->getMessage());
                flash('danger', 'Member dan akun pelanggan tidak dapat disimpan. Periksa migrasi database.');
            }
        }
        redirect('admin_pelanggan.php');
    }

    $status = $_POST['status_member'] ?? null;
    if (!is_string($status) || !in_array($status, ['aktif', 'nonaktif'], true)) {
        flash('danger', 'Status member tidak valid.');
        redirect($returnUrl);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'UPDATE tbl_pelanggan
             SET nama_pelanggan = ?, no_telepon = ?, id_card_rfid = ?, status_member = ?
             WHERE id_pelanggan = ?'
        );
        $stmt->execute([$name, $phone, $card, $status, $id]);
        if ($existingAccount) {
            if ($accountPassword !== '') {
                $accountUpdate = $pdo->prepare('UPDATE tbl_user SET nama_lengkap = ?, password = ? WHERE id_user = ?');
                $accountUpdate->execute([$name, password_hash($accountPassword, PASSWORD_DEFAULT), (int) $existingAccount['id_user']]);
            } else {
                $accountUpdate = $pdo->prepare('UPDATE tbl_user SET nama_lengkap = ? WHERE id_user = ?');
                $accountUpdate->execute([$name, (int) $existingAccount['id_user']]);
            }
        } elseif ($accountUsername !== '' && $accountPassword !== '') {
            $accountInsert = $pdo->prepare(
                "INSERT INTO tbl_user (nama_lengkap, username, password, role, id_pelanggan)
                 VALUES (?, ?, ?, 'pelanggan', ?)"
            );
            $accountInsert->execute([$name, $accountUsername, password_hash($accountPassword, PASSWORD_DEFAULT), $id]);
        }
        $pdo->commit();
        flash('success', 'Data pelanggan dan akun belanja berhasil diperbarui.');
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
            flash('danger', 'Username atau ID Card RFID sudah digunakan.');
        } else {
            error_log('Pembaruan pelanggan gagal: ' . $exception->getMessage());
            flash('danger', 'Data pelanggan tidak dapat diperbarui. Silakan coba kembali.');
        }
    }
    redirect($returnUrl);
}

$editCustomer = null;
$editAccount = null;
if (array_key_exists('edit', $_GET)) {
    if (!$isAdmin) {
        flash('warning', 'Kasir hanya dapat menambahkan member baru.');
        redirect('admin_pelanggan.php');
    }
    $editId = valid_int($_GET['edit'], 1);
    if ($editId === null) {
        flash('warning', 'ID pelanggan yang dipilih tidak valid.');
        redirect('admin_pelanggan.php');
    }

    $editQuery = $pdo->prepare('SELECT * FROM tbl_pelanggan WHERE id_pelanggan = ?');
    $editQuery->execute([$editId]);
    $editCustomer = $editQuery->fetch();
    if (!$editCustomer) {
        flash('warning', 'Data pelanggan tidak ditemukan.');
        redirect('admin_pelanggan.php');
    }
    $accountQuery = $pdo->prepare(
        "SELECT id_user, username FROM tbl_user WHERE id_pelanggan = ? AND role = 'pelanggan' LIMIT 1"
    );
    $accountQuery->execute([$editId]);
    $editAccount = $accountQuery->fetch() ?: null;
}

$showForm = $editCustomer !== null || array_key_exists('tambah', $_GET);
try {
    $customers = $pdo->query(
        'SELECT id_pelanggan, nama_pelanggan, no_telepon, id_card_rfid, status_member
         FROM tbl_pelanggan
         ORDER BY nama_pelanggan, id_pelanggan'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log('Daftar pelanggan gagal dimuat: ' . $exception->getMessage());
    http_response_code(500);
    $pageTitle = 'Skema Database Belum Siap';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="card-panel">
        <div class="panel-body">
            <div class="alert alert-danger mb-0" role="alert">
                <h2 class="h5"><i class="bi bi-exclamation-triangle-fill me-2"></i>Data pelanggan belum dapat dimuat</h2>
                <p class="mb-2">Pastikan migrasi skema member, nomor telepon, dan akun pelanggan sudah dijalankan pada database <code>db_tokokita</code>:</p>
                <ol class="mb-0">
                    <li><code>database/migrasi_member_rfid.sql</code></li>
                    <li><code>database/migrasi_telepon_pelanggan.sql</code></li>
                    <li><code>database/migrasi_multi_role_cashless.sql</code></li>
                </ol>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$activeMembers = count(array_filter($customers, static fn(array $customer): bool => $customer['status_member'] === 'aktif'));
$pageTitle = 'Data Pelanggan (Member)';
require __DIR__ . '/includes/header.php';
?>

<div class="card-panel mb-4">
    <div class="panel-heading">
        <div>
            <h2 class="section-title">
                <i class="bi bi-people-fill text-primary"></i>
                Data Pelanggan &amp; Member
            </h2>
            <div class="section-subtitle">Kelola pelanggan, kartu RFID, dan status keanggotaan AkbarMart.</div>
        </div>
        <a class="btn btn-primary" href="admin_pelanggan.php?tambah=1">
            <i class="bi bi-person-plus-fill me-1"></i> Tambah Member Baru
        </a>
    </div>
</div>

<?php if ($showForm): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card-panel">
                <div class="panel-heading">
                    <div>
                        <h2 class="section-title">
                            <i class="bi <?= $editCustomer ? 'bi-pencil-square' : 'bi-person-plus-fill' ?> text-primary"></i>
                            <?= $editCustomer ? 'Edit Data Pelanggan' : 'Pendaftaran Member Baru' ?>
                        </h2>
                        <div class="section-subtitle">
                            <?= $editCustomer ? 'Perbarui informasi dan status member.' : 'Status member baru otomatis aktif setelah disimpan.' ?>
                        </div>
                    </div>
                </div>
                <div class="panel-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="<?= $editCustomer ? 'update' : 'create' ?>">
                        <?php if ($editCustomer): ?>
                            <input type="hidden" name="id_pelanggan" value="<?= (int) $editCustomer['id_pelanggan'] ?>">
                        <?php endif; ?>

                        <div class="col-12 col-md-6">
                            <label class="form-label" for="nama-pelanggan">Nama Lengkap</label>
                            <input class="form-control" id="nama-pelanggan" name="nama_pelanggan" type="text"
                                   maxlength="150" required autocomplete="name"
                                   value="<?= e($editCustomer['nama_pelanggan'] ?? '') ?>"
                                   placeholder="Nama pelanggan">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="no-telepon">Nomor Telepon / WhatsApp</label>
                            <input class="form-control" id="no-telepon" name="no_telepon" type="tel"
                                   inputmode="tel" minlength="8" maxlength="20"
                                   pattern="[0-9+() -]{8,20}" required autocomplete="tel"
                                   value="<?= e($editCustomer['no_telepon'] ?? '') ?>"
                                   placeholder="Contoh: 081234567890">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="id-card-rfid">ID Card RFID</label>
                            <input class="form-control" id="id-card-rfid" name="id_card_rfid" type="text"
                                   maxlength="50" required autocomplete="off" autocapitalize="off"
                                   spellcheck="false" <?= $editCustomer ? '' : 'autofocus' ?>
                                   value="<?= e($editCustomer['id_card_rfid'] ?? '') ?>"
                                   placeholder="Tap kartu atau masukkan ID RFID">
                            <div class="form-text">ID RFID harus unik dan maksimal 50 karakter.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="username-pelanggan">Username akun belanja</label>
                            <input class="form-control" id="username-pelanggan" name="username_pelanggan" type="text"
                                   minlength="3" maxlength="50" pattern="[A-Za-z0-9._-]{3,50}"
                                   <?= !$editCustomer || !$editAccount ? 'required' : 'readonly' ?>
                                   value="<?= e($editAccount['username'] ?? '') ?>"
                                   placeholder="Contoh: pelanggan01">
                            <?php if ($editCustomer && $editAccount): ?>
                                <div class="form-text">Username akun yang sudah terhubung tidak dapat diubah dari halaman ini.</div>
                            <?php else: ?>
                                <div class="form-text">Gunakan 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="password-pelanggan">
                                <?= $editCustomer && $editAccount ? 'Password baru (opsional)' : 'Password awal akun' ?>
                            </label>
                            <input class="form-control" id="password-pelanggan" name="password_pelanggan" type="password"
                                   minlength="12" maxlength="72" autocomplete="new-password"
                                   <?= !$editCustomer || !$editAccount ? 'required' : '' ?>
                                   placeholder="<?= $editCustomer && $editAccount ? 'Kosongkan jika tidak diubah' : 'Minimal 12 karakter' ?>">
                            <div class="form-text">
                                <?= $editCustomer && $editAccount
                                    ? 'Isi hanya jika admin ingin mereset password akun.'
                                    : 'Sampaikan kredensial awal ini secara aman kepada pelanggan.' ?>
                            </div>
                        </div>
                        <?php if ($editCustomer): ?>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="status-member">Status Member</label>
                                <select class="form-select" id="status-member" name="status_member" required>
                                    <option value="aktif" <?= $editCustomer['status_member'] === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                                    <option value="nonaktif" <?= $editCustomer['status_member'] === 'nonaktif' ? 'selected' : '' ?>>Nonaktif</option>
                                </select>
                            </div>
                        <?php else: ?>
                            <div class="col-12 col-md-6 d-flex align-items-end">
                                <div class="alert alert-success py-2 mb-0 w-100">
                                    <i class="bi bi-patch-check-fill me-1"></i> Status member baru: <strong>Aktif</strong>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-check-circle me-1"></i>
                                <?= $editCustomer ? 'Simpan Perubahan' : 'Daftarkan Member' ?>
                            </button>
                            <a class="btn btn-light" href="admin_pelanggan.php">Batal</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
        <div class="stat-card card-indigo">
            <div class="stat-content">
                <span class="stat-label">Total Pelanggan</span>
                <div class="stat-value text-primary"><?= count($customers) ?></div>
            </div>
            <div class="stat-icon icon-indigo"><i class="bi bi-people-fill"></i></div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="stat-card card-emerald">
            <div class="stat-content">
                <span class="stat-label">Member Aktif</span>
                <div class="stat-value text-success"><?= $activeMembers ?></div>
            </div>
            <div class="stat-icon icon-emerald"><i class="bi bi-person-check-fill"></i></div>
        </div>
    </div>
</div>

<div class="card-panel">
    <div class="panel-heading">
        <div>
            <h2 class="section-title"><i class="bi bi-card-list text-primary"></i> Daftar Pelanggan</h2>
            <div class="section-subtitle">Informasi kontak, kartu RFID, dan status member.</div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th scope="col">No</th>
                    <th scope="col">Nama Pelanggan</th>
                    <th scope="col">No. Telepon</th>
                    <th scope="col">ID Card RFID</th>
                    <th scope="col">Status Member</th>
                    <th scope="col" class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$customers): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <div class="empty-state-text">Belum ada data pelanggan. Tambahkan member pertama untuk memulai.</div>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($customers as $index => $customer): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><strong><?= e($customer['nama_pelanggan']) ?></strong></td>
                        <td><?= e($customer['no_telepon'] ?: '-') ?></td>
                        <td><code><?= e($customer['id_card_rfid'] ?: '-') ?></code></td>
                        <td>
                            <span class="badge-soft <?= $customer['status_member'] === 'aktif' ? 'badge-emerald' : 'badge-amber' ?>">
                                <?= $customer['status_member'] === 'aktif' ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <?php if ($isAdmin): ?>
                                    <a class="btn btn-sm btn-icon btn-light" href="admin_pelanggan.php?edit=<?= (int) $customer['id_pelanggan'] ?>"
                                       title="Edit pelanggan" aria-label="Edit <?= e($customer['nama_pelanggan']) ?>">
                                        <i class="bi bi-pencil-fill text-primary"></i>
                                    </a>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id_pelanggan" value="<?= (int) $customer['id_pelanggan'] ?>">
                                        <button class="btn btn-sm btn-icon btn-light text-danger" type="submit"
                                                data-confirm="Hapus data pelanggan <?= e($customer['nama_pelanggan']) ?>?"
                                                title="Hapus pelanggan" aria-label="Hapus <?= e($customer['nama_pelanggan']) ?>">
                                            <i class="bi bi-trash3-fill"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
