<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();
require_once __DIR__ . '/config/koneksi.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$userQuery = $pdo->prepare(
    'SELECT u.id_user, u.nama_lengkap, u.username, u.role, u.id_pelanggan, p.status_member
     FROM tbl_user u
     LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = u.id_pelanggan
     WHERE u.id_user = ? LIMIT 1'
);
$userQuery->execute([(int) $_SESSION['id_user']]);
$currentUser = $userQuery->fetch();

if (
    !$currentUser
    || !in_array($currentUser['role'], ['admin', 'kasir', 'pelanggan'], true)
    || ($currentUser['role'] === 'pelanggan'
        && ($currentUser['id_pelanggan'] === null || $currentUser['status_member'] !== 'aktif'))
) {
    $_SESSION = [];
    session_destroy();
    redirect('login.php?account=unavailable');
}

if ($_SESSION['role'] !== $currentUser['role']) {
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
}
$_SESSION['id_user'] = (int) $currentUser['id_user'];
$_SESSION['nama'] = $currentUser['nama_lengkap'];
$_SESSION['username'] = $currentUser['username'];
$_SESSION['role'] = $currentUser['role'];
if ($currentUser['role'] === 'pelanggan') {
    $_SESSION['id_pelanggan'] = (int) $currentUser['id_pelanggan'];
} else {
    unset($_SESSION['id_pelanggan']);
}
