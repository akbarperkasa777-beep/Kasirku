<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();
require_once __DIR__ . '/config/koneksi.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$userQuery = $pdo->prepare('SELECT id_user, nama_lengkap, username, role FROM tbl_user WHERE id_user = ? LIMIT 1');
$userQuery->execute([(int) $_SESSION['id_user']]);
$currentUser = $userQuery->fetch();

if (!$currentUser || !in_array($currentUser['role'], ['admin', 'kasir'], true)) {
    $_SESSION = [];
    session_destroy();
    redirect('login.php');
}

if ($_SESSION['role'] !== $currentUser['role']) {
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
}
$_SESSION['id_user'] = (int) $currentUser['id_user'];
$_SESSION['nama'] = $currentUser['nama_lengkap'];
$_SESSION['username'] = $currentUser['username'];
$_SESSION['role'] = $currentUser['role'];
