<?php
declare(strict_types=1);

/**
 * Handler Callback / Webhook Duitku Payment Gateway
 * Proyek: AkbarMart POS & Ritel (Jl. Gajah Mada No. 12, Ponorogo)
 * URL Callback Resmi: https://sterida.my.id/akbar/callback.php
 *
 * Menerima payload notifikasi HTTP POST dari server Duitku saat status pembayaran selesai.
 * Memverifikasi validitas signature, melakukan pengecekan idempotensi (anti-pemotongan ganda),
 * dan memotong stok produk secara atomik dalam transaksi MySQL (BEGIN ... COMMIT).
 */

require_once __DIR__ . '/config/koneksi.php';

// 1. Muat Konfigurasi Duitku
$duitkuConfigFile = __DIR__ . '/config/duitku.php';
if (!is_file($duitkuConfigFile)) {
    http_response_code(500);
    echo 'CONFIG_ERROR: config/duitku.php tidak ditemukan.';
    exit;
}
$duitkuConfig = require $duitkuConfigFile;

$apiKey = trim((string) ($duitkuConfig['api_key'] ?? ''));
$configuredMerchantCode = trim((string) ($duitkuConfig['merchant_code'] ?? 'DS38180'));

// 2. Baca Payload Masukan (Mendukung form-urlencoded maupun JSON)
$rawInput = (string) file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);

$merchantCode = (string) ($_POST['merchantCode'] ?? ($jsonData['merchantCode'] ?? ''));
$amount = (string) ($_POST['amount'] ?? ($jsonData['amount'] ?? ''));
$merchantOrderId = (string) ($_POST['merchantOrderId'] ?? ($jsonData['merchantOrderId'] ?? ''));
$signature = (string) ($_POST['signature'] ?? ($jsonData['signature'] ?? ''));
$resultCode = (string) ($_POST['resultCode'] ?? ($jsonData['resultCode'] ?? ''));
$reference = (string) ($_POST['reference'] ?? ($jsonData['reference'] ?? ''));
$paymentCode = (string) ($_POST['paymentCode'] ?? ($jsonData['paymentCode'] ?? ''));
$additionalParam = (string) ($_POST['additionalParam'] ?? ($jsonData['additionalParam'] ?? ''));

// Validasi keberadaan parameter wajib
if ($merchantCode === '' || $amount === '' || $merchantOrderId === '' || $signature === '') {
    http_response_code(400);
    echo 'BAD_REQUEST: Parameter callback tidak lengkap.';
    exit;
}

// 3. Verifikasi Signature Duitku
// Rumus Resmi Duitku Callback: MD5(merchantCode + amount + merchantOrderId + apiKey)
$expectedSignature1 = md5($merchantCode . $amount . $merchantOrderId . $apiKey);

// Variasi toleransi jika amount dikirim dalam format angka bulat
$amountClean = (string) (int) round((float) $amount);
$expectedSignature2 = md5($merchantCode . $amountClean . $merchantOrderId . $apiKey);

$signatureValid = hash_equals($expectedSignature1, $signature) || hash_equals($expectedSignature2, $signature);

if (!$signatureValid) {
    error_log(sprintf(
        '[Duitku Callback] Invalid Signature! Order: %s | Expected: %s | Received: %s',
        $merchantOrderId,
        $expectedSignature1,
        $signature
    ));
    http_response_code(400);
    echo 'BAD_SIGNATURE: Verifikasi signature gagal.';
    exit;
}

// 4. Identifikasi ID Transaksi Internal AkbarMart
$transactionId = null;
if ($additionalParam !== '' && ctype_digit($additionalParam)) {
    $transactionId = (int) $additionalParam;
} elseif (preg_match('/(?:AKM|TRX)-(\d+)/i', $merchantOrderId, $matches)) {
    $transactionId = (int) $matches[1];
}

// Fallback pencarian langsung berdasarkan merchant_order_id jika kolom tersedia
if ($transactionId === null || $transactionId < 1) {
    try {
        $findOrder = $pdo->prepare('SELECT id_transaksi FROM tbl_transaksi WHERE merchant_order_id = ? LIMIT 1');
        $findOrder->execute([$merchantOrderId]);
        $found = $findOrder->fetchColumn();
        if ($found) {
            $transactionId = (int) $found;
        }
    } catch (Throwable $e) {}
}

if ($transactionId === null || $transactionId < 1) {
    http_response_code(404);
    echo 'ORDER_NOT_FOUND: merchantOrderId tidak dikenali.';
    exit;
}

try {
    // 5. Memulai Transaksi Database MySQL Atomik
    $pdo->beginTransaction();

    // Kunci baris transaksi dengan FOR UPDATE untuk mencegah race condition
    $stmt = $pdo->prepare(
        "SELECT id_transaksi, total_belanja, status_pembayaran, metode_pembayaran
         FROM tbl_transaksi
         WHERE id_transaksi = ?
         FOR UPDATE"
    );
    $stmt->execute([$transactionId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        http_response_code(404);
        echo 'ORDER_NOT_FOUND: Transaksi ID ' . $transactionId . ' tidak ada di database.';
        exit;
    }

    // 6. Pengecekan Idempotensi (Mencegah Pengurangan Stok Ganda)
    // Jika transaksi sudah lunas, langsung return SUCCESS tanpa potong stok lagi
    if ($order['status_pembayaran'] === 'lunas') {
        $pdo->commit();
        http_response_code(200);
        echo 'SUCCESS';
        exit;
    }

    // 7. Pembayaran Berhasil (resultCode '00') -> Potong Stok & Tandai Lunas
    if ($resultCode === '00') {
        // Ambil rincian produk dari tbl_detail_transaksi
        $detailStmt = $pdo->prepare(
            'SELECT id_produk, jumlah FROM tbl_detail_transaksi WHERE id_transaksi = ?'
        );
        $detailStmt->execute([$transactionId]);
        $items = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$items) {
            $pdo->rollBack();
            error_log('[Duitku Callback] Transaksi #' . $transactionId . ' tidak memiliki rincian produk.');
            http_response_code(422);
            echo 'FAILED_NO_ITEMS';
            exit;
        }

        // Pengurangan Stok Aman pada tbl_produk
        // Formula: stok = GREATEST(0, CAST(stok AS SIGNED) - jumlah) agar stok tidak underflow pada tipe data unsigned
        $stockStmt = $pdo->prepare(
            'UPDATE tbl_produk
             SET stok = GREATEST(0, CAST(stok AS SIGNED) - ?)
             WHERE id_produk = ?'
        );

        foreach ($items as $item) {
            $pId = (int) $item['id_produk'];
            $qty = (int) $item['jumlah'];
            $stockStmt->execute([$qty, $pId]);
        }

        // Perbarui status transaksi menjadi 'lunas'
        $nominalDibayar = (float) $order['total_belanja'];
        $catatan = 'Duitku Ref: ' . ($reference ?: '-')
            . ' | Channel: ' . ($paymentCode ?: 'Online')
            . ' | Sukses: ' . date('d/m/Y H:i:s');

        // Periksa apakah kolom duitku_reference tersedia pada tabel
        $cols = $pdo->query("SHOW COLUMNS FROM tbl_transaksi")->fetchAll(PDO::FETCH_COLUMN, 0);
        $hasDuitkuRef = in_array('duitku_reference', $cols, true);

        if ($hasDuitkuRef) {
            $updateOrder = $pdo->prepare(
                "UPDATE tbl_transaksi
                 SET status_pembayaran = 'lunas',
                     uang_bayar = ?,
                     kembalian = 0,
                     tanggal_pembayaran = CURRENT_TIMESTAMP,
                     catatan_pembayaran = ?,
                     duitku_reference = COALESCE(?, duitku_reference)
                 WHERE id_transaksi = ? AND status_pembayaran != 'lunas'"
            );
            $updateOrder->execute([
                $nominalDibayar,
                $catatan,
                $reference ?: null,
                $transactionId,
            ]);
        } else {
            $updateOrder = $pdo->prepare(
                "UPDATE tbl_transaksi
                 SET status_pembayaran = 'lunas',
                     uang_bayar = ?,
                     kembalian = 0,
                     tanggal_pembayaran = CURRENT_TIMESTAMP,
                     catatan_pembayaran = ?
                 WHERE id_transaksi = ? AND status_pembayaran != 'lunas'"
            );
            $updateOrder->execute([
                $nominalDibayar,
                $catatan,
                $transactionId,
            ]);
        }

        // Simpan seluruh perubahan secara permanen
        $pdo->commit();

        error_log(sprintf(
            '[Duitku Callback] Sukses! Order #%d (Ref: %s) lunas dan stok produk berkurang.',
            $transactionId,
            $reference
        ));

        // Beri tahu Duitku bahwa notifikasi telah diproses dengan sukses
        http_response_code(200);
        echo 'SUCCESS';
        exit;
    }

    // 8. Pembayaran Dibatalkan atau Kedaluwarsa (resultCode selain '00')
    $catatanBatal = 'Duitku Batal/Gagal: Kode ' . $resultCode . ' | Ref: ' . ($reference ?: '-');
    $cancelStmt = $pdo->prepare(
        "UPDATE tbl_transaksi
         SET status_pembayaran = 'dibatalkan',
             catatan_pembayaran = ?
         WHERE id_transaksi = ? AND status_pembayaran = 'menunggu_pembayaran'"
    );
    $cancelStmt->execute([$catatanBatal, $transactionId]);

    $pdo->commit();

    http_response_code(200);
    echo 'SUCCESS';
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Duitku Callback Error] ' . $e->getMessage());
    http_response_code(500);
    echo 'SERVER_ERROR: ' . $e->getMessage();
    exit;
}
