<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_login();
require_once __DIR__ . '/config/koneksi.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Metode request tidak diizinkan. Gunakan metode POST.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Opsional: verifikasi token CSRF jika dikirimkan oleh klien
if (isset($_POST['csrf_token'])) {
    verify_csrf();
}

// Ambil input id_card dari form POST atau payload JSON
$idCard = trim((string) ($_POST['id_card'] ?? ''));
if ($idCard === '') {
    $rawInput = file_get_contents('php://input');
    if ($rawInput !== false && $rawInput !== '') {
        $jsonInput = json_decode($rawInput, true);
        if (is_array($jsonInput) && isset($jsonInput['id_card'])) {
            $idCard = trim((string) $jsonInput['id_card']);
        }
    }
}

// Validasi keberadaan dan panjang string ID card
if ($idCard === '' || mb_strlen($idCard) > 50 || preg_match('/[\x00-\x1F\x7F]/', $idCard)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Format ID Card / RFID tidak valid atau masih kosong.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Periksa apakah kolom id_card_rfid sudah ada pada tbl_pelanggan
    $columns = $pdo->query('SHOW COLUMNS FROM tbl_pelanggan LIKE "id_card_rfid"')->fetchAll();
    if (empty($columns)) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Kolom RFID belum terpasang. Harap jalankan database/migrasi_member_rfid.sql terlebih dahulu.',
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
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Jika kartu tidak terdaftar di database
if (!$member) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'Kartu RFID "' . e($idCard) . '" tidak terdaftar sebagai member AkbarMart.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Periksa apakah status member aktif
$statusMember = (string) ($member['status_member'] ?? 'aktif');
if ($statusMember !== 'aktif') {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Member "' . e($member['nama_pelanggan']) . '" ditemukan, namun status kartu saat ini NONAKTIF.',
        'nama_member' => $member['nama_pelanggan'],
        'status_member' => $statusMember,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Member aktif valid: diskon 10%
$diskonPersen = 10;

echo json_encode([
    'status' => 'success',
    'message' => 'Member aktif terverifikasi! Diskon otomatis 10% berhasil diterapkan.',
    'id_pelanggan' => (int) $member['id_pelanggan'],
    'nama_member' => $member['nama_pelanggan'],
    'id_card_rfid' => $member['id_card_rfid'],
    'status_member' => $statusMember,
    'diskon_persen' => $diskonPersen,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
exit;
