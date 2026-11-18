<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('AKBARMARTSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah(int|float|string $amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid atau telah kedaluwarsa. Silakan kembali dan coba lagi.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function valid_int(mixed $value, int $min = 0): ?int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);
    return $validated !== false && $validated >= $min ? $validated : null;
}

function safe_product_image_name(mixed $filename): ?string
{
    $filename = (string) $filename;
    return preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/D', $filename) === 1 ? $filename : null;
}

function delete_product_image(mixed $filename): bool
{
    if ($filename === null || $filename === '') {
        return true;
    }

    $safeFilename = safe_product_image_name($filename);
    if ($safeFilename === null) {
        return false;
    }

    $path = __DIR__ . '/../uploads/produk/' . $safeFilename;
    return !is_file($path) || unlink($path);
}

function active_page(string $page): string
{
    return basename($_SERVER['PHP_SELF'] ?? '') === $page ? 'active' : '';
}

function is_authenticated(): bool
{
    if (
        !isset($_SESSION['id_user'], $_SESSION['nama'], $_SESSION['username'], $_SESSION['role'])
        || !in_array($_SESSION['role'], ['admin', 'kasir', 'pelanggan'], true)
    ) {
        return false;
    }

    return $_SESSION['role'] !== 'pelanggan' || isset($_SESSION['id_pelanggan']);
}

function require_login(): void
{
    if (!is_authenticated()) {
        redirect('login.php');
    }
}

function require_role(string|array $roles): void
{
    require_login();
    $roles = is_array($roles) ? $roles : [$roles];
    if (in_array($_SESSION['role'], $roles, true)) {
        return;
    }

    if ($_SESSION['role'] === 'pelanggan') {
        redirect('belanja_pelanggan.php');
    }

    if ($_SESSION['role'] === 'kasir') {
        flash('warning', 'Akses halaman tersebut hanya tersedia untuk Admin.');
        redirect('pos.php');
    }

    http_response_code(403);
    exit('Akses ditolak.');
}
