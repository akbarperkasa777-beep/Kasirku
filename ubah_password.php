<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_role('pelanggan');

$userId = (int) $_SESSION['id_user'];
$userName = (string) ($_SESSION['nama'] ?? 'Pelanggan');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $pwdStmt = $pdo->prepare('SELECT password FROM tbl_user WHERE id_user = ? AND role = \'pelanggan\' LIMIT 1');
    $pwdStmt->execute([$userId]);
    $currentHash = $pwdStmt->fetchColumn();

    if (!is_string($currentHash) || !password_verify($currentPassword, $currentHash)) {
        flash('danger', 'Password saat ini yang Anda masukkan salah. Silakan coba kembali.');
        redirect('ubah_password.php');
    }

    if (strlen($newPassword) < 12 || strlen($newPassword) > 72) {
        flash('danger', 'Password baru harus memiliki panjang antara 12 hingga 72 karakter.');
        redirect('ubah_password.php');
    }

    if (!hash_equals($newPassword, $confirmPassword)) {
        flash('danger', 'Konfirmasi password baru tidak cocok. Pastikan kedua isian password baru sama.');
        redirect('ubah_password.php');
    }

    if ($currentPassword === $newPassword) {
        flash('warning', 'Password baru tidak boleh sama persis dengan password lama.');
        redirect('ubah_password.php');
    }

    try {
        $updateStmt = $pdo->prepare('UPDATE tbl_user SET password = ? WHERE id_user = ?');
        $updateStmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

        flash('success', 'Password akun belanja Anda berhasil diperbarui dengan aman.');
        redirect('ubah_password.php');
    } catch (Throwable $e) {
        error_log('Gagal mengubah password pelanggan: ' . $e->getMessage());
        flash('danger', 'Terjadi kesalahan sistem saat memperbarui password. Silakan coba lagi.');
        redirect('ubah_password.php');
    }
}

$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Ubah Password';
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
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/navbar_pelanggan.php'; ?>

<main class="container py-4" style="max-width: 680px;">
    <?php if ($flashMessage): ?>
        <div class="alert alert-<?= e($flashMessage['type']) ?> alert-dismissible fade show d-flex align-items-center gap-2 mb-4 shadow-sm" role="alert">
            <i class="bi <?= $flashMessage['type'] === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> fs-5"></i>
            <div><?= e($flashMessage['message']) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
        <!-- Header Halaman -->
        <div class="d-flex align-items-center gap-3 pb-3 mb-4 border-bottom">
            <span class="p-3 rounded-circle bg-primary bg-opacity-10 text-primary">
                <i class="bi bi-shield-lock-fill fs-3"></i>
            </span>
            <div>
                <h5 class="fw-bold text-slate-900 mb-1">Ganti Password Akun</h5>
                <span class="small text-muted">Amankan akun belanja mandiri AkbarMart Anda dengan kata sandi yang kuat</span>
            </div>
        </div>

        <!-- Panduan Keamanan -->
        <div class="alert alert-info py-2 px-3 small mb-4 d-flex align-items-start gap-2">
            <i class="bi bi-info-circle-fill fs-5 text-primary mt-1"></i>
            <div>
                <strong>Ketentuan Keamanan Password:</strong>
                <ul class="mb-0 ps-3 mt-1">
                    <li>Panjang minimal <strong>12 karakter</strong> (maksimal 72 karakter).</li>
                    <li>Gunakan kombinasi huruf besar, huruf kecil, angka, atau simbol.</li>
                    <li>Jangan gunakan kata sandi yang mudah ditebak seperti tanggal lahir.</li>
                </ul>
            </div>
        </div>

        <form method="post" action="ubah_password.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <!-- Password Saat Ini -->
            <div class="mb-3">
                <label class="form-label small fw-bold text-slate-800" for="current_password">
                    Password Saat Ini <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text bg-white text-muted border-end-0"><i class="bi bi-lock"></i></span>
                    <input 
                        type="password" 
                        class="form-control border-start-0 border-end-0" 
                        id="current_password" 
                        name="current_password" 
                        required 
                        placeholder="Masukkan password Anda saat ini">
                    <button class="btn btn-outline-secondary border-start-0" type="button" onclick="togglePasswordVisibility('current_password', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Password Baru -->
            <div class="mb-3">
                <label class="form-label small fw-bold text-slate-800" for="new_password">
                    Password Baru <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text bg-white text-primary border-end-0"><i class="bi bi-key-fill"></i></span>
                    <input 
                        type="password" 
                        class="form-control border-start-0 border-end-0" 
                        id="new_password" 
                        name="new_password" 
                        required 
                        minlength="12" 
                        maxlength="72" 
                        placeholder="Minimal 12 karakter baru">
                    <button class="btn btn-outline-secondary border-start-0" type="button" onclick="togglePasswordVisibility('new_password', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Konfirmasi Password Baru -->
            <div class="mb-4">
                <label class="form-label small fw-bold text-slate-800" for="confirm_password">
                    Ulangi Password Baru <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text bg-white text-primary border-end-0"><i class="bi bi-check-circle"></i></span>
                    <input 
                        type="password" 
                        class="form-control border-start-0 border-end-0" 
                        id="confirm_password" 
                        name="confirm_password" 
                        required 
                        minlength="12" 
                        maxlength="72" 
                        placeholder="Ketik ulang password baru">
                    <button class="btn btn-outline-secondary border-start-0" type="button" onclick="togglePasswordVisibility('confirm_password', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Tombol Aksi -->
            <div class="d-flex align-items-center justify-content-between pt-3 border-top">
                <a href="profil.php" class="btn btn-light px-3 fw-semibold">
                    <i class="bi bi-arrow-left me-1"></i> Ke Profil Saya
                </a>
                <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                    <i class="bi bi-shield-check me-1"></i> Perbarui Password
                </button>
            </div>
        </form>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePasswordVisibility(inputId, btn) {
    var input = document.getElementById(inputId);
    if (!input) return;
    var icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        if (icon) { icon.classList.remove('bi-eye'); icon.classList.add('bi-eye-slash'); }
    } else {
        input.type = 'password';
        if (icon) { icon.classList.remove('bi-eye-slash'); icon.classList.add('bi-eye'); }
    }
}
</script>
</body>
</html>
