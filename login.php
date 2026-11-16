<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
if (is_authenticated()) {
    redirect($_SESSION['role'] === 'admin' ? 'index.php' : 'pos.php');
}
require_once __DIR__ . '/config/koneksi.php';

$loginError = '';
$demoAccounts = [];
$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);

if ($isLocalRequest) {
    $defaultDemoAccounts = [
        ['username' => 'admin', 'password' => 'AkbarAdmin#2026', 'label' => 'Admin'],
        ['username' => 'kasir', 'password' => 'AkbarKasir#2026', 'label' => 'Kasir'],
    ];
    $demoUserQuery = $pdo->prepare('SELECT password FROM tbl_user WHERE username = ? LIMIT 1');

    foreach ($defaultDemoAccounts as $account) {
        $demoUserQuery->execute([$account['username']]);
        $demoUser = $demoUserQuery->fetch();
        if ($demoUser && password_verify($account['password'], $demoUser['password'])) {
            $demoAccounts[] = $account;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username !== '' && strlen($username) <= 50 && $password !== '') {
        $userQuery = $pdo->prepare(
            'SELECT id_user, nama_lengkap, username, password, role FROM tbl_user WHERE username = ? LIMIT 1'
        );
        $userQuery->execute([$username]);
        $user = $userQuery->fetch();
    } else {
        $user = false;
    }

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['id_user'] = (int) $user['id_user'];
        $_SESSION['nama'] = $user['nama_lengkap'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $rehash = $pdo->prepare('UPDATE tbl_user SET password = ? WHERE id_user = ?');
            $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id_user']]);
        }

        redirect($user['role'] === 'admin' ? 'index.php' : 'pos.php');
    }

    $loginError = 'Username atau password salah.';
}
$loggedOut = ($_GET['logout'] ?? '') === '1';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | AkbarMart</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/akbarmart-icon.svg?v=2">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-card">
            <div class="login-brand">
                <img src="assets/img/akbarmart-icon.svg?v=2" alt="" width="48" height="48">
                <div><strong>AkbarMart</strong><span>Point of Sale</span></div>
            </div>
            <h1>Selamat datang</h1>
            <p class="login-subtitle">Masuk untuk melanjutkan ke sistem toko.</p>

            <?php if ($loggedOut): ?>
                <div class="alert alert-success py-2" role="status">Anda berhasil logout.</div>
            <?php endif; ?>
            <?php if ($loginError !== ''): ?>
                <div class="alert alert-danger py-2" role="alert"><?= e($loginError) ?></div>
            <?php endif; ?>

            <form id="login-form" method="post" action="login.php" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div class="mb-3">
                    <label class="form-label" for="username">Username</label>
                    <input class="form-control" id="username" name="username" type="text" maxlength="50" autocomplete="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
                </div>
                <div class="mb-4">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right me-2"></i>Masuk</button>
            </form>
            <?php if ($demoAccounts !== []): ?>
                <div class="demo-login">
                    <div class="demo-login-title"><i class="bi bi-lightning-charge me-1"></i>Login cepat akun demo</div>
                    <div class="d-grid gap-2">
                        <?php foreach ($demoAccounts as $account): ?>
                            <button
                                class="btn btn-outline-primary demo-login-button"
                                type="button"
                                data-demo-username="<?= e($account['username']) ?>"
                                data-demo-password="<?= e($account['password']) ?>"
                            >
                                <i class="bi bi-person-check me-2"></i>Masuk sebagai <?= e($account['label']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="demo-login-hint">Tersedia hanya saat aplikasi dibuka dari komputer ini.</div>
                </div>
            <?php endif; ?>
            <div class="login-footer"><i class="bi bi-shield-lock me-1"></i>Akses sistem dilindungi autentikasi.</div>
        </section>
    </main>
    <?php if ($demoAccounts !== []): ?>
        <script>
            document.querySelectorAll('.demo-login-button').forEach((button) => {
                button.addEventListener('click', () => {
                    document.getElementById('username').value = button.dataset.demoUsername;
                    document.getElementById('password').value = button.dataset.demoPassword;
                    document.getElementById('login-form').requestSubmit();
                });
            });
        </script>
    <?php endif; ?>
</body>
</html>
