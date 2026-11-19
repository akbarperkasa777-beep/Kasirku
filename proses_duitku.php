<?php
declare(strict_types=1);

/**
 * Inisiasi Transaksi Payment Gateway Duitku (AkbarMart)
 * Lokasi: C:\xamppp\htdocs\kasirku\proses_duitku.php
 *
 * Menangani checkout mandiri non-tunai pelanggan:
 * 1. Mengamankan stok & menghitung subtotal belanja.
 * 2. Menerapkan diskon otomatis 10% untuk member aktif (RFID).
 * 3. Membangun array itemDetails yang proporsional sehingga total itemDetails persis sama dengan paymentAmount.
 * 4. Membuat record tbl_transaksi & tbl_detail_transaksi dengan status 'menunggu_pembayaran' (tanpa memotong stok awal).
 * 5. Memanggil API Duitku v2 Inquiry via cURL dengan signature MD5 valid.
 * 6. Mengembalikan vaNumber (VA), reference (QRIS), paymentUrl, dan detail simulasi ke frontend.
 */

require_once __DIR__ . '/check_session.php';
require_role(['admin', 'kasir', 'pelanggan']);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metode HTTP tidak diizinkan. Gunakan POST.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

verify_csrf();

$duitkuConfigFile = __DIR__ . '/config/duitku.php';
if (!is_file($duitkuConfigFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Konfigurasi Duitku (config/duitku.php) belum ditemukan.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$duitkuConfig = require $duitkuConfigFile;
$merchantCode = trim((string) ($duitkuConfig['merchant_code'] ?? 'DS38180'));
$apiKey = trim((string) ($duitkuConfig['api_key'] ?? ''));
$mode = ($duitkuConfig['mode'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
$endpoint = $duitkuConfig['endpoints'][$mode] ?? 'https://sandbox.duitku.com/webapi/api/merchant/v2/inquiry';
$demoSimulatorUrl = (string) ($duitkuConfig['demo_simulator_url'] ?? 'https://sandbox.duitku.com/payment/demo/demosuccesstransaction.aspx');

if ($merchantCode === '' || $apiKey === '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Merchant Code atau API Key Duitku belum dikonfigurasi.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userRole = (string) ($_SESSION['role'] ?? '');
$userId = (int) ($_SESSION['id_user'] ?? 0);
$userName = (string) ($_SESSION['nama'] ?? 'AkbarMart');

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Sesi login tidak valid. Silakan login kembali.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$source = trim((string) ($_POST['source'] ?? ''));
if ($source === '') {
    $source = ($userRole === 'pelanggan') ? 'customer' : 'pos';
}

$customerId = null;
if ($userRole === 'pelanggan' || $source === 'customer') {
    $customerId = (int) ($_SESSION['id_pelanggan'] ?? 0);
    $cashierName = 'Belanja Mandiri';
} else {
    // Admin atau Kasir di POS
    $custInput = $_POST['id_pelanggan'] ?? '';
    $customerId = ($custInput !== '') ? valid_int($custInput, 1) : null;
    $cashierName = $userName;
}

// 1. Validasi Masukan Kuantitas Keranjang
$rawQuantities = $_POST['qty'] ?? [];
if (!is_array($rawQuantities)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Format keranjang belanja tidak valid.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$quantities = [];
foreach ($rawQuantities as $productId => $qty) {
    $pIdClean = valid_int($productId, 1);
    $qtyClean = valid_int($qty, 0);
    if ($pIdClean === null || $qtyClean === null) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Data kuantitas produk tidak valid.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($qtyClean > 0) {
        $quantities[$pIdClean] = ($quantities[$pIdClean] ?? 0) + $qtyClean;
    }
}

if ($quantities === []) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Keranjang belanja masih kosong. Pilih minimal satu produk.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Pastikan Skema Kolom Pendukung Duitku Tersedia
function ensure_duitku_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM tbl_transaksi WHERE Field = 'metode_pembayaran'");
        $col = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($col && !str_contains((string) $col['Type'], "'duitku'")) {
            $pdo->exec("ALTER TABLE tbl_transaksi MODIFY metode_pembayaran ENUM('tunai', 'qris', 'transfer', 'duitku') NOT NULL DEFAULT 'tunai'");
        }

        $statusStmt = $pdo->query("SHOW COLUMNS FROM tbl_transaksi WHERE Field = 'status_pembayaran'");
        $statusCol = $statusStmt ? $statusStmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($statusCol && !str_contains((string) $statusCol['Type'], "'menunggu_pembayaran'")) {
            $pdo->exec("ALTER TABLE tbl_transaksi MODIFY status_pembayaran ENUM('lunas', 'menunggu_pembayaran', 'menunggu_verifikasi', 'ditolak', 'dibatalkan') NOT NULL DEFAULT 'lunas'");
        }

        $cols = $pdo->query("SHOW COLUMNS FROM tbl_transaksi")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('merchant_order_id', $cols, true)) {
            $pdo->exec("ALTER TABLE tbl_transaksi ADD COLUMN merchant_order_id VARCHAR(100) NULL AFTER catatan_pembayaran");
        }
        if (!in_array('duitku_reference', $cols, true)) {
            $pdo->exec("ALTER TABLE tbl_transaksi ADD COLUMN duitku_reference VARCHAR(100) NULL AFTER merchant_order_id");
        }
        if (!in_array('duitku_va_number', $cols, true)) {
            $pdo->exec("ALTER TABLE tbl_transaksi ADD COLUMN duitku_va_number VARCHAR(50) NULL AFTER duitku_reference");
        }
        if (!in_array('duitku_payment_url', $cols, true)) {
            $pdo->exec("ALTER TABLE tbl_transaksi ADD COLUMN duitku_payment_url VARCHAR(500) NULL AFTER duitku_va_number");
        }
        $checked = true;
    } catch (Throwable $e) {
        error_log('ensure_duitku_schema error: ' . $e->getMessage());
    }
}
ensure_duitku_schema($pdo);

// 3. Ambil Data Pelanggan & Status Keanggotaan Member (RFID)
$isMemberActive = false;
$customerName = 'Pelanggan Umum (Walk-in)';
$customerPhone = '081234567890';
$customerEmail = 'pelanggan@akbarmart.local';

if ($userRole === 'pelanggan' || $source === 'customer') {
    // ATURAN 1: Sisi Pelanggan (belanja_pelanggan.php)
    // Tetap wajib non-tunai dan otomatis mendapat diskon member 10% jika status_member aktif
    if ($customerId !== null && $customerId > 0) {
        $custDetailStmt = $pdo->prepare('SELECT nama_pelanggan, no_telepon, id_card_rfid, status_member FROM tbl_pelanggan WHERE id_pelanggan = ? LIMIT 1');
        $custDetailStmt->execute([$customerId]);
        $customerData = $custDetailStmt->fetch(PDO::FETCH_ASSOC);
        if ($customerData) {
            $customerName = (string) $customerData['nama_pelanggan'];
            $customerPhone = (string) ($customerData['no_telepon'] ?: '081234567890');
            $customerEmail = 'member' . $customerId . '@akbarmart.local';
            $isMemberActive = (($customerData['status_member'] ?? '') === 'aktif');
        }
    }

    $customerStmt = $pdo->prepare('SELECT nama_lengkap FROM tbl_user WHERE id_user = ? LIMIT 1');
    $customerStmt->execute([$userId]);
    $uName = $customerStmt->fetchColumn();
    if ($uName) {
        $customerName = (string) $uName;
    }
} else {
    // ATURAN 2: Sisi POS / Kasir (pos.php) untuk Role Admin dan Kasir
    // Diskon member 10% HANYA aktif jika kartu RFID member benar-benar di-tap & terverifikasi (rfid_verified === true)
    // Memilih nama pelanggan di dropdown (misalnya Budi) TIDAK boleh otomatis menerapkan diskon 10%
    $rfidVerifiedRaw = $_POST['rfid_verified'] ?? '';
    $rfidVerified = ($rfidVerifiedRaw === '1' || $rfidVerifiedRaw === 'true' || $rfidVerifiedRaw === 1 || $rfidVerifiedRaw === true);
    $rfidInput = trim((string) ($_POST['id_card_rfid'] ?? ''));

    if ($rfidVerified && $rfidInput !== '' && mb_strlen($rfidInput) <= 50 && !preg_match('/[\x00-\x1F\x7F]/', $rfidInput)) {
        $rfidStmt = $pdo->prepare("SELECT id_pelanggan, nama_pelanggan, no_telepon, status_member FROM tbl_pelanggan WHERE id_card_rfid = ? AND status_member = 'aktif' LIMIT 1");
        $rfidStmt->execute([$rfidInput]);
        $rfidData = $rfidStmt->fetch(PDO::FETCH_ASSOC);
        if ($rfidData) {
            $customerId = (int) $rfidData['id_pelanggan'];
            $customerName = (string) $rfidData['nama_pelanggan'];
            $customerPhone = (string) ($rfidData['no_telepon'] ?: '081234567890');
            $customerEmail = 'member' . $customerId . '@akbarmart.local';
            $isMemberActive = true;
        } else {
            $isMemberActive = false;
        }
    } else {
        // Kartu RFID belum di-tap atau tidak terverifikasi -> diskon member WAJIB 0
        $isMemberActive = false;
    }

    // Ambil identitas nama & nomor kontak pelanggan jika kasir memilih di dropdown (tanpa mengaktifkan diskon)
    if ($customerId !== null && $customerId > 0) {
        $custDetailStmt = $pdo->prepare('SELECT nama_pelanggan, no_telepon FROM tbl_pelanggan WHERE id_pelanggan = ? LIMIT 1');
        $custDetailStmt->execute([$customerId]);
        $customerData = $custDetailStmt->fetch(PDO::FETCH_ASSOC);
        if ($customerData) {
            $customerName = (string) $customerData['nama_pelanggan'];
            $customerPhone = (string) ($customerData['no_telepon'] ?: '081234567890');
            $customerEmail = 'pelanggan' . $customerId . '@akbarmart.local';
        }
    }
}

// 4. Daftar Nama Kanal & Tipe Metode Pembayaran
$vaMethods = ['BR', 'BC', 'M2', 'I1', 'VA', 'BT', 'B1', 'BV', 'DM', 'AG', 'NC', 'S1', 'A1'];
$qrisMethods = ['SP', 'NQ'];
$ewalletMethods = ['OV', 'DA', 'SA', 'LA', 'AP'];
$retailMethods = ['FT', 'IR'];

$channelNames = [
    // Virtual Account
    'BR' => 'BRI Virtual Account',
    'BC' => 'BCA Virtual Account',
    'M2' => 'Mandiri Virtual Account',
    'I1' => 'BNI Virtual Account',
    'VA' => 'Maybank Virtual Account',
    'BT' => 'Permata Bank Virtual Account',
    'B1' => 'CIMB Niaga Virtual Account',
    'BV' => 'BSI (Bank Syariah Indonesia) VA',
    'DM' => 'Danamon Virtual Account',
    'AG' => 'Bank Artha Graha VA',
    'NC' => 'Bank Neo Commerce (BNC) VA',
    'S1' => 'Bank Sahabat Sampoerna VA',
    'A1' => 'ATM Bersama / Alto VA',
    // QRIS
    'SP' => 'QRIS Realtime (ShopeePay / All E-Wallet)',
    'NQ' => 'QRIS Nobu (All E-Wallet)',
    // E-Wallet
    'OV' => 'OVO',
    'DA' => 'DANA',
    'SA' => 'ShopeePay App Direct',
    'LA' => 'LinkAja',
    'AP' => 'AstraPay',
    // Retail
    'FT' => 'Indomaret',
    'IR' => 'Pos Indonesia / Pegadaian',
    // Checkout Portal
    ''   => 'Semua Metode Pembayaran (Portal Duitku)',
];

$selectedPaymentMethod = trim((string) ($_POST['payment_method'] ?? 'BR'));
if (in_array($selectedPaymentMethod, $vaMethods, true)) {
    $channelType = 'va';
} elseif (in_array($selectedPaymentMethod, $qrisMethods, true)) {
    $channelType = 'qris';
} elseif (in_array($selectedPaymentMethod, $ewalletMethods, true)) {
    $channelType = 'ewallet';
} elseif (in_array($selectedPaymentMethod, $retailMethods, true)) {
    $channelType = 'retail';
} else {
    $channelType = 'other';
}

try {
    $pdo->beginTransaction();

    // 5. Periksa Ketersediaan Stok Produk (FOR UPDATE)
    $productIds = array_keys($quantities);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $productQuery = $pdo->prepare(
        "SELECT id_produk, nama_produk, harga, stok
         FROM tbl_produk WHERE id_produk IN ({$placeholders})
         ORDER BY id_produk FOR UPDATE"
    );
    $productQuery->execute($productIds);
    $products = $productQuery->fetchAll();

    if (count($products) !== count($productIds)) {
        throw new DomainException('Satu atau lebih produk sudah tidak tersedia dalam katalog.');
    }

    $subtotal = 0.0;
    $itemDetails = [];

    foreach ($products as $product) {
        $pId = (int) $product['id_produk'];
        $qty = $quantities[$pId];
        $stokTersedia = (int) $product['stok'];

        if ($qty > $stokTersedia) {
            throw new DomainException('Stok produk "' . $product['nama_produk'] . '" tidak mencukupi (tersisa: ' . $stokTersedia . ').');
        }

        $harga = (float) $product['harga'];
        $lineSubtotal = (int) round($harga * $qty);
        $subtotal += $lineSubtotal;

        /**
         * PENTING (ATURAN API DUITKU):
         * Pada Duitku v2 Inquiry, Duitku memvalidasi kesamaan nilai:
         * paymentAmount == SUM(item.price) untuk seluruh item dalam array itemDetails
         * Duitku TIDAK mengalikan price dengan quantity saat menjumlahkan validasi!
         * Oleh karena itu, field 'price' harus diisi nilai total baris (line subtotal),
         * dan nama produk dilengkapi penanda kuantitas jika qty > 1.
         */
        $itemName = mb_substr((string) $product['nama_produk'], 0, 42);
        if ($qty > 1) {
            $itemName .= " ({$qty}x)";
        }

        $itemDetails[] = [
            'name' => $itemName,
            'price' => $lineSubtotal,
            'quantity' => $qty,
        ];
    }

    // 6. Hitung Diskon Member (10% jika status member aktif)
    $discount = 0;
    if ($isMemberActive && $subtotal > 0) {
        $discount = (int) round($subtotal * 0.10);
        // Tambahkan baris potongan diskon ke itemDetails agar matematis persis seimbang dengan paymentAmount
        $itemDetails[] = [
            'name' => 'Diskon Member RFID (10%)',
            'price' => -$discount,
            'quantity' => 1,
        ];
    }

    $totalAmount = (int) round($subtotal - $discount);
    if ($totalAmount <= 0) {
        throw new DomainException('Total pembayaran tidak valid. Nominal belanja harus lebih dari Rp 0.');
    }

    // Pastikan SUM(itemDetails.price) persis 100% sama dengan $totalAmount
    $sumItemDetails = 0;
    foreach ($itemDetails as $it) {
        $sumItemDetails += (int) $it['price'];
    }
    if ($sumItemDetails !== $totalAmount) {
        $diff = $totalAmount - $sumItemDetails;
        $lastIndex = count($itemDetails) - 1;
        $itemDetails[$lastIndex]['price'] += $diff;
    }

    // 7. Simpan Pesanan Awal di tbl_transaksi dengan Status 'menunggu_pembayaran' (Stok Belum Dipotong)
    $insertOrder = $pdo->prepare(
        "INSERT INTO tbl_transaksi
         (id_user, id_pelanggan, total_belanja, diskon, uang_bayar, kembalian, nama_kasir,
          metode_pembayaran, status_pembayaran, simulasi_pembayaran, tanggal_pembayaran, catatan_pembayaran)
         VALUES (?, ?, ?, ?, 0, 0, ?, 'duitku', 'menunggu_pembayaran', ?, NULL, ?)"
    );
    $isSimulasi = ($mode === 'sandbox') ? 1 : 0;
    $initialNote = 'Menunggu Pembayaran Duitku Sandbox' . ($isMemberActive ? ' (Diskon Member 10%)' : '');
    $insertOrder->execute([$userId, $customerId, $totalAmount, $discount, $cashierName, $isSimulasi, $initialNote]);
    $transactionId = (int) $pdo->lastInsertId();

    // 8. Masukkan Rincian Barang ke tbl_detail_transaksi
    $detailColumns = $pdo->query('SHOW COLUMNS FROM tbl_detail_transaksi')->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasLegacyColumns = in_array('nama_produk', $detailColumns, true) && in_array('harga', $detailColumns, true);

    if ($hasLegacyColumns) {
        $insertDetail = $pdo->prepare(
            'INSERT INTO tbl_detail_transaksi (id_transaksi, id_produk, nama_produk, jumlah, harga, harga_satuan)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
    } else {
        $insertDetail = $pdo->prepare(
            'INSERT INTO tbl_detail_transaksi (id_transaksi, id_produk, jumlah, harga_satuan)
             VALUES (?, ?, ?, ?)'
        );
    }

    foreach ($products as $product) {
        $pId = (int) $product['id_produk'];
        $qty = $quantities[$pId];
        $harga = (float) $product['harga'];
        if ($hasLegacyColumns) {
            $insertDetail->execute([$transactionId, $pId, $product['nama_produk'], $qty, $harga, $harga]);
        } else {
            $insertDetail->execute([$transactionId, $pId, $qty, $harga]);
        }
    }

    // 9. Buat Merchant Order ID Unik (AKM-{id_transaksi}-{timestamp})
    $merchantOrderId = 'AKM-' . $transactionId . '-' . time();
    $updateMOrderId = $pdo->prepare('UPDATE tbl_transaksi SET merchant_order_id = ? WHERE id_transaksi = ?');
    $updateMOrderId->execute([$merchantOrderId, $transactionId]);

    $pdo->commit();
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Gagal inisiasi transaksi Duitku: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem saat membuat pesanan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 10. Bentuk Signature Resmi Duitku Inquiry: MD5(merchantCode + merchantOrderId + paymentAmount + apiKey)
$signature = md5($merchantCode . $merchantOrderId . $totalAmount . $apiKey);

$returnUrl = $duitkuConfig['return_url'] . (str_contains($duitkuConfig['return_url'], '?') ? '&' : '?')
    . 'order=' . $transactionId . '&duitku=1';

$payload = [
    'merchantCode' => $merchantCode,
    'paymentAmount' => $totalAmount,
    'paymentMethod' => $selectedPaymentMethod,
    'merchantOrderId' => $merchantOrderId,
    'productDetails' => 'Belanja AkbarMart Ponorogo #' . $transactionId,
    'additionalParam' => (string) $transactionId,
    'merchantUserInfo' => $customerName,
    'customerVaName' => mb_substr($customerName, 0, 30),
    'email' => $customerEmail,
    'phoneNumber' => $customerPhone,
    'itemDetails' => $itemDetails,
    'callbackUrl' => 'https://sterida.my.id/akbar/callback.php',
    'returnUrl' => $returnUrl,
    'signature' => $signature,
    'expiryPeriod' => (int) ($duitkuConfig['expiry_period'] ?? 60),
];

// 11. Panggil API Sandbox Duitku secara NYATA via cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $endpoint,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);

$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$duitkuResponse = is_string($responseBody) ? json_decode($responseBody, true) : null;
$statusCode = is_array($duitkuResponse) ? (string) ($duitkuResponse['statusCode'] ?? '') : '';
$statusMessage = is_array($duitkuResponse) ? (string) ($duitkuResponse['statusMessage'] ?? '') : '';
$paymentUrl = is_array($duitkuResponse) ? (string) ($duitkuResponse['paymentUrl'] ?? '') : '';
$reference = is_array($duitkuResponse) ? (string) ($duitkuResponse['reference'] ?? '') : '';
$vaNumber = is_array($duitkuResponse) ? (string) ($duitkuResponse['vaNumber'] ?? '') : '';
$qrString = is_array($duitkuResponse) ? (string) ($duitkuResponse['qrString'] ?? '') : '';

// 12. Tangani Respons Sukses dari API Duitku (HTTP 200 & statusCode '00')
if ($httpStatus === 200 && $statusCode === '00') {
    $note = 'Menunggu Pembayaran Duitku | Ref: ' . $reference;
    if ($vaNumber !== '') {
        $note .= ' | VA: ' . $vaNumber;
    }
    $note .= ' | Kanal: ' . ($selectedPaymentMethod ?: 'ALL');

    try {
        $updateStmt = $pdo->prepare(
            'UPDATE tbl_transaksi
             SET catatan_pembayaran = ?,
                 duitku_reference = COALESCE(?, duitku_reference),
                 duitku_va_number = COALESCE(?, duitku_va_number),
                 duitku_payment_url = COALESCE(?, duitku_payment_url)
             WHERE id_transaksi = ?'
        );
        $updateStmt->execute([
            $note,
            $reference ?: null,
            $vaNumber ?: null,
            $paymentUrl ?: null,
            $transactionId,
        ]);
    } catch (Throwable $e) {
        error_log('Gagal update referensi Duitku: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'is_simulation' => false,
        'order_id' => $transactionId,
        'merchant_order_id' => $merchantOrderId,
        'reference' => $reference,
        'va_number' => $vaNumber,
        'payment_url' => $paymentUrl,
        'qr_string' => $qrString,
        'channel_type' => $channelType,
        'is_va' => ($channelType === 'va' || $vaNumber !== ''),
        'is_qris' => ($channelType === 'qris' || $qrString !== ''),
        'payment_method' => $selectedPaymentMethod ?: 'ALL',
        'payment_method_name' => $channelNames[$selectedPaymentMethod] ?? ($selectedPaymentMethod ?: 'Semua Kanal'),
        'total_belanja' => $totalAmount,
        'diskon' => $discount,
        'total_belanja_formatted' => rupiah($totalAmount),
        'expiry_seconds' => 600,
        'demo_simulator_url' => $demoSimulatorUrl,
        'callback_url' => 'https://sterida.my.id/akbar/callback.php',
        'message' => 'Tagihan resmi Duitku Sandbox berhasil dibuat!',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 13. Tangani Respons Error dari API Duitku
$duitkuRawMessage = '';
if (is_array($duitkuResponse)) {
    $duitkuRawMessage = trim((string) (
        $duitkuResponse['Message']
        ?? $duitkuResponse['statusMessage']
        ?? $duitkuResponse['message']
        ?? $duitkuResponse['errorMessage']
        ?? ''
    ));
}

$suggestQris = false;
if (str_contains(strtolower($duitkuRawMessage), 'minimum payment') || str_contains(strtolower($duitkuRawMessage), '10000')) {
    $errorDetail = "Duitku: " . $duitkuRawMessage . " (Metode Virtual Account dibatasi minimal Rp 10.000 oleh perbankan. Silakan gunakan metode QRIS untuk nominal kecil).";
    $suggestQris = true;
} elseif (str_contains(strtolower($duitkuRawMessage), 'equal to all item price')) {
    $errorDetail = "Duitku: " . $duitkuRawMessage . " (Kalkulasi rincian barang telah disesuaikan).";
} elseif ($duitkuRawMessage !== '') {
    $errorDetail = "Duitku: " . $duitkuRawMessage . ($statusCode !== '' ? " (Kode: {$statusCode})" : "");
} elseif ($curlError !== '') {
    $errorDetail = "Koneksi cURL Gagal: {$curlError}";
} else {
    $errorDetail = "Duitku mengembalikan HTTP {$httpStatus}: " . mb_substr((string) $responseBody, 0, 150);
}

// Batalkan pesanan lokal karena API Duitku menolak pembuatan tagihan
try {
    $cancelStmt = $pdo->prepare("UPDATE tbl_transaksi SET status_pembayaran = 'dibatalkan', catatan_pembayaran = ? WHERE id_transaksi = ?");
    $cancelStmt->execute(['Inisiasi Duitku Ditolak: ' . mb_substr($errorDetail, 0, 200), $transactionId]);
} catch (Throwable $e) {}

http_response_code(422);
echo json_encode([
    'success' => false,
    'message' => 'Gagal membuat tagihan Duitku: ' . $errorDetail,
    'error_detail' => $errorDetail,
    'suggest_qris' => $suggestQris,
    'order_id' => $transactionId,
    'merchant_order_id' => $merchantOrderId,
    'hint' => $suggestQris 
        ? 'Pilih metode QRIS (ShopeePay / Nobu) untuk memproses pembayaran di bawah Rp 10.000.'
        : 'Pastikan metode pembayaran dipilih sesuai jenis kanal yang aktif di Duitku.',
], JSON_UNESCAPED_UNICODE);
