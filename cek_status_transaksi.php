<?php
declare(strict_types=1);

/**
 * Endpoint Pengecekan Status Transaksi Real-Time (AkbarMart)
 * Digunakan oleh frontend (Fetch API polling setiap 3 detik) untuk mendeteksi
 * saat pembayaran telah lunas setelah webhook callback Duitku diterima.
 */

require_once __DIR__ . '/check_session.php';
require_once __DIR__ . '/config/koneksi.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$orderId = valid_int($_REQUEST['order_id'] ?? null, 1);
$merchantOrderId = trim((string) ($_REQUEST['merchant_order_id'] ?? ''));
$action = trim((string) ($_REQUEST['action'] ?? 'check'));

if ($orderId === null && $merchantOrderId === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Parameter order_id atau merchant_order_id wajib disertakan.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($_SESSION['id_user'] ?? 0);
$userRole = (string) ($_SESSION['role'] ?? '');

try {
    if ($userRole === 'admin' || $userRole === 'kasir') {
        $stmt = $pdo->prepare(
            'SELECT * FROM tbl_transaksi 
             WHERE (id_transaksi = ? OR merchant_order_id = ?) 
             LIMIT 1'
        );
        $stmt->execute([$orderId ?? 0, $merchantOrderId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT * FROM tbl_transaksi
             WHERE (id_transaksi = ? OR merchant_order_id = ?) AND id_user = ?
             LIMIT 1'
        );
        $stmt->execute([$orderId ?? 0, $merchantOrderId, $userId]);
    }

    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Transaksi tidak ditemukan atau Anda tidak memiliki akses.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $transId = (int) $order['id_transaksi'];
    $currentStatus = (string) $order['status_pembayaran'];

    // Jika timer countdown di frontend habis (00:00), tandai sebagai dibatalkan/expired jika belum lunas
    if ($action === 'expire' && $currentStatus === 'menunggu_pembayaran') {
        $expireStmt = $pdo->prepare(
            "UPDATE tbl_transaksi
             SET status_pembayaran = 'dibatalkan',
                 catatan_pembayaran = 'Batas waktu pembayaran habis (Expired)'
             WHERE id_transaksi = ? AND status_pembayaran = 'menunggu_pembayaran'"
        );
        $expireStmt->execute([$transId]);
        $currentStatus = 'dibatalkan';
    }

    // JIKA STATUS MASIH MENUNGGU PEMBAYARAN:
    // Cek status ke API Duitku secara langsung sebagai fallback handal
    // (Sangat berguna untuk testing localhost XAMPP saat webhook dari internet belum/tidak dapat masuk)
    if ($currentStatus === 'menunggu_pembayaran' && $action !== 'expire') {
        $duitkuConfig = require __DIR__ . '/config/duitku.php';
        $merchantCode = (string) ($duitkuConfig['merchant_code'] ?? 'DS38180');
        $apiKey = (string) ($duitkuConfig['api_key'] ?? '');
        $mOrderId = (string) ($order['merchant_order_id'] ?? '');

        if ($mOrderId !== '' && $apiKey !== '') {
            $mode = ($duitkuConfig['mode'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
            $statusEndpoint = $duitkuConfig['status_endpoints'][$mode]
                ?? ($mode === 'production'
                    ? 'https://passport.duitku.com/webapi/api/merchant/transactionStatus'
                    : 'https://sandbox.duitku.com/webapi/api/merchant/transactionStatus');

            $statusSig = md5($merchantCode . $mOrderId . $apiKey);
            $statusPayload = [
                'merchantCode' => $merchantCode,
                'merchantOrderId' => $mOrderId,
                'signature' => $statusSig,
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $statusEndpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($statusPayload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $resStatus = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($resStatus)) {
                $statusData = json_decode($resStatus, true);
                $duitkuStatusCode = (string) ($statusData['statusCode'] ?? '');
                $duitkuRef = (string) ($statusData['reference'] ?? ($order['duitku_reference'] ?? ''));

                // Jika Duitku menyatakan transaksi SUKSES ('00'):
                if ($duitkuStatusCode === '00') {
                    $pdo->beginTransaction();
                    $lockStmt = $pdo->prepare('SELECT id_transaksi, total_belanja, status_pembayaran FROM tbl_transaksi WHERE id_transaksi = ? FOR UPDATE');
                    $lockStmt->execute([$transId]);
                    $freshOrder = $lockStmt->fetch(PDO::FETCH_ASSOC);

                    if ($freshOrder && $freshOrder['status_pembayaran'] !== 'lunas') {
                        // Ambil rincian produk untuk pemotongan stok atomik
                        $itemStmt = $pdo->prepare('SELECT id_produk, jumlah FROM tbl_detail_transaksi WHERE id_transaksi = ?');
                        $itemStmt->execute([$transId]);
                        $orderItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

                        $stockStmt = $pdo->prepare('UPDATE tbl_produk SET stok = GREATEST(0, CAST(stok AS SIGNED) - ?) WHERE id_produk = ?');
                        foreach ($orderItems as $item) {
                            $stockStmt->execute([(int) $item['jumlah'], (int) $item['id_produk']]);
                        }

                        $nominal = (float) $freshOrder['total_belanja'];
                        $note = 'Duitku Ref: ' . ($duitkuRef ?: '-') . ' | Verified via Duitku API: ' . date('d/m/Y H:i:s');

                        $updateOrder = $pdo->prepare(
                            "UPDATE tbl_transaksi
                             SET status_pembayaran = 'lunas',
                                 uang_bayar = ?,
                                 kembalian = 0,
                                 tanggal_pembayaran = CURRENT_TIMESTAMP,
                                 catatan_pembayaran = ?,
                                 duitku_reference = COALESCE(?, duitku_reference)
                             WHERE id_transaksi = ?"
                        );
                        $updateOrder->execute([$nominal, $note, $duitkuRef ?: null, $transId]);
                        $pdo->commit();

                        $currentStatus = 'lunas';
                        $order['tanggal_pembayaran'] = date('Y-m-d H:i:s');
                    } else {
                        if ($pdo->inTransaction()) $pdo->commit();
                        $currentStatus = 'lunas';
                    }
                }
            }
        }
    }

    $isPaid = ($currentStatus === 'lunas');
    $isExpired = ($currentStatus === 'dibatalkan');

    $paymentTime = !empty($order['tanggal_pembayaran'])
        ? date('d/m/Y H:i:s', strtotime((string) $order['tanggal_pembayaran'])) . ' WIB'
        : date('d/m/Y H:i:s', strtotime((string) $order['tanggal'])) . ' WIB';

    echo json_encode([
        'success' => true,
        'order_id' => $transId,
        'trx_code' => 'TRX-' . str_pad((string) $transId, 6, '0', STR_PAD_LEFT),
        'merchant_order_id' => (string) ($order['merchant_order_id'] ?? ''),
        'status' => $currentStatus,
        'is_paid' => $isPaid,
        'is_expired' => $isExpired,
        'total_belanja' => (float) $order['total_belanja'],
        'total_belanja_formatted' => rupiah((float) $order['total_belanja']),
        'metode_pembayaran' => strtoupper((string) $order['metode_pembayaran']),
        'tanggal_pembayaran' => $paymentTime,
        'receipt_url' => 'cetak_struk.php?id=' . $transId,
        'catatan' => (string) ($order['catatan_pembayaran'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('cek_status_transaksi error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat memeriksa status transaksi.',
    ], JSON_UNESCAPED_UNICODE);
}
