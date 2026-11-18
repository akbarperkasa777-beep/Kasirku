<?php
require_once __DIR__ . '/check_session.php';
require_role(['admin', 'kasir']);

$transactionId = valid_int($_GET['id'] ?? null, 1);
if ($transactionId === null) {
    http_response_code(400);
    exit('ID transaksi tidak valid.');
}

$proofQuery = $pdo->prepare(
    "SELECT bukti_pembayaran FROM tbl_transaksi
     WHERE id_transaksi = ? AND status_pembayaran = 'menunggu_verifikasi'
     LIMIT 1"
);
$proofQuery->execute([$transactionId]);
$filename = $proofQuery->fetchColumn();
if (!is_string($filename) || !preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp|gif|pdf)\z/D', $filename)) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

$proofPath = __DIR__ . '/storage/pembayaran/' . $filename;
if (!is_file($proofPath) || !class_exists('finfo')) {
    http_response_code(404);
    exit('File bukti pembayaran tidak tersedia.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($proofPath);
$allowedMimes = [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'pdf' => 'application/pdf',
];
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if (!isset($allowedMimes[$extension]) || $mime !== $allowedMimes[$extension]) {
    http_response_code(415);
    exit('Format bukti pembayaran tidak valid.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($proofPath));
header('Content-Disposition: ' . ($mime === 'application/pdf' ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
readfile($proofPath);
exit;
