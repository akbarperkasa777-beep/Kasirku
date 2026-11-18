<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/check_session.php';
require_role(['admin', 'kasir']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Metode request tidak diizinkan. Gunakan metode POST.',
        'nama_pelanggan' => null,
        'diskon_persen' => 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals(csrf_token(), $_POST['csrf_token'])) {
    http_response_code(419);
    echo json_encode([
        'status' => 'error',
        'message' => 'Sesi formulir tidak valid atau telah kedaluwarsa. Muat ulang halaman POS.',
        'nama_pelanggan' => null,
        'diskon_persen' => 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ambil input id_card dari form POST atau payload JSON
$postedIdCard = $_POST['id_card'] ?? null;
$idCard = is_string($postedIdCard) ? trim($postedIdCard) : '';
if ($idCard === '') {
    $rawInput = file_get_contents('php://input');
    if ($rawInput !== false && $rawInput !== '') {
        $jsonInput = json_decode($rawInput, true);
        if (is_array($jsonInput) && isset($jsonInput['id_card']) && is_string($jsonInput['id_card'])) {
            $idCard = trim($jsonInput['id_card']);
        }
    }
}

// Validasi keberadaan dan panjang string ID card
if ($idCard === '' || mb_strlen($idCard) > 50 || preg_match('/[\x00-\x1F\x7F]/', $idCard)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Format ID Card / RFID tidak valid atau masih kosong.',
        'nama_pelanggan' => null,
        'diskon_persen' => 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $columns = $pdo->query('SHOW COLUMNS FROM tbl_pelanggan')->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('id_card_rfid', $columns, true) || !in_array('status_member', $columns, true)) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Kolom RFID belum terpasang. Harap jalankan database/migrasi_member_rfid.sql terlebih dahulu.',
            'nama_pelanggan' => null,
            'diskon_persen' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT id_pelanggan, nama_pelanggan, kota, id_card_rfid, status_member 
         FROM tbl_pelanggan 
         WHERE id_card_rfid = ? 
         LIMIT 1'
    );
    $stmt->execute([$idCard]);
    $member = $stmt->fetch();
} catch (PDOException $exception) {
    error_log('Error pengecekan RFID member: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Terjadi kendala pada database saat memeriksa kartu member.',
        'nama_pelanggan' => null,
        'diskon_persen' => 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Jika kartu tidak terdaftar di database
if (!$member) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'Kartu RFID tidak terdaftar sebagai member AkbarMart.',
        'nama_pelanggan' => null,
        'diskon_persen' => 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Periksa apakah status member aktif
$statusMember = (string) ($member['status_member'] ?? '');
if ($statusMember !== 'aktif') {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Member ditemukan, namun status kartu saat ini nonaktif.',
        'nama_pelanggan' => $member['nama_pelanggan'],
        'nama_member' => $member['nama_pelanggan'],
        'status_member' => $statusMember,
        'diskon_persen' => 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Member aktif valid: diskon 10%
$diskonPersen = 10;

echo json_encode([
    'status' => 'success',
    'message' => 'Member aktif terverifikasi! Diskon otomatis 10% berhasil diterapkan.',
    'id_pelanggan' => (int) $member['id_pelanggan'],
    'nama_pelanggan' => $member['nama_pelanggan'],
    'nama_member' => $member['nama_pelanggan'],
    'id_card_rfid' => $member['id_card_rfid'],
    'status_member' => $statusMember,
    'diskon_persen' => $diskonPersen,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
exit;
