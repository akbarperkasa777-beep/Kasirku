<?php
require_once __DIR__ . '/check_session.php';
require_role(['admin', 'kasir']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $transactionId = valid_int($_POST['id_transaksi'] ?? null, 1);
    if (!in_array($action, ['approve', 'reject'], true) || $transactionId === null) {
        flash('danger', 'Tindakan atau transaksi pembayaran tidak valid.');
        redirect('admin_pembayaran.php');
    }

    $noteInput = $_POST['catatan_pembayaran'] ?? '';
    $note = is_string($noteInput) ? trim($noteInput) : '';
    if ($action === 'reject' && ($note === '' || mb_strlen($note) > 255)) {
        flash('danger', 'Alasan penolakan wajib diisi (maksimal 255 karakter).');
        redirect('admin_pembayaran.php');
    }

    try {
        $pdo->beginTransaction();
        $transactionQuery = $pdo->prepare(
            "SELECT id_transaksi, total_belanja, status_pembayaran, simulasi_pembayaran, bukti_pembayaran
             FROM tbl_transaksi
             WHERE id_transaksi = ? AND status_pembayaran = 'menunggu_verifikasi'
             FOR UPDATE"
        );
        $transactionQuery->execute([$transactionId]);
        $transaction = $transactionQuery->fetch();
        if (!$transaction) {
            throw new DomainException('Transaksi tidak ditemukan atau sudah ditinjau.');
        }

        if ($action === 'approve') {
            $proofFilename = (string) ($transaction['bukti_pembayaran'] ?? '');
            if (
                !preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp|gif|pdf)\z/D', $proofFilename)
                || !is_file(__DIR__ . '/storage/pembayaran/' . $proofFilename)
            ) {
                throw new DomainException('Bukti pembayaran tidak tersedia; pembayaran tidak dapat disetujui.');
            }
            $detailQuery = $pdo->prepare(
                'SELECT id_produk, jumlah FROM tbl_detail_transaksi
                 WHERE id_transaksi = ? ORDER BY id_produk'
            );
            $detailQuery->execute([$transactionId]);
            $details = $detailQuery->fetchAll();
            if (!$details) {
                throw new DomainException('Transaksi tidak memiliki rincian produk dan tidak dapat disetujui.');
            }

            $productIds = array_values(array_unique(array_map(static fn(array $detail): int => (int) $detail['id_produk'], $details)));
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $stockQuery = $pdo->prepare(
                "SELECT id_produk, stok FROM tbl_produk
                 WHERE id_produk IN ({$placeholders})
                 ORDER BY id_produk FOR UPDATE"
            );
            $stockQuery->execute($productIds);
            $stocks = [];
            foreach ($stockQuery->fetchAll() as $product) {
                $stocks[(int) $product['id_produk']] = (int) $product['stok'];
            }

            foreach ($details as $detail) {
                $productId = (int) $detail['id_produk'];
                $quantity = (int) $detail['jumlah'];
                if (!isset($stocks[$productId]) || $quantity < 1 || $quantity > $stocks[$productId]) {
                    throw new DomainException('Stok produk tidak lagi mencukupi. Tolak pesanan atau periksa stok sebelum menyetujui.');
                }
                $stocks[$productId] -= $quantity;
            }

            $updateStock = $pdo->prepare('UPDATE tbl_produk SET stok = stok - ? WHERE id_produk = ? AND stok >= ?');
            foreach ($details as $detail) {
                $quantity = (int) $detail['jumlah'];
                $updateStock->execute([$quantity, (int) $detail['id_produk'], $quantity]);
                if ($updateStock->rowCount() !== 1) {
                    throw new DomainException('Stok berubah saat verifikasi. Tidak ada perubahan yang disimpan; coba lagi.');
                }
            }

            $updateOrder = $pdo->prepare(
                "UPDATE tbl_transaksi
                 SET status_pembayaran = 'lunas', uang_bayar = total_belanja,
                     tanggal_pembayaran = CURRENT_TIMESTAMP, ditinjau_oleh = ?,
                     tanggal_ditinjau = CURRENT_TIMESTAMP, catatan_pembayaran = NULL
                 WHERE id_transaksi = ? AND status_pembayaran = 'menunggu_verifikasi'"
            );
            $updateOrder->execute([(int) $_SESSION['id_user'], $transactionId]);
            if ($updateOrder->rowCount() !== 1) {
                throw new DomainException('Status transaksi berubah saat verifikasi. Silakan muat ulang halaman.');
            }
            $pdo->commit();
            flash(
                'success',
                (int) $transaction['simulasi_pembayaran'] === 1
                    ? 'Simulasi pembayaran disetujui dan stok produk berhasil dipotong.'
                    : 'Pembayaran disetujui dan stok produk berhasil dipotong.'
            );
        } else {
            $updateOrder = $pdo->prepare(
                "UPDATE tbl_transaksi
                 SET status_pembayaran = 'dibatalkan', ditinjau_oleh = ?,
                     tanggal_ditinjau = CURRENT_TIMESTAMP, catatan_pembayaran = ?
                 WHERE id_transaksi = ? AND status_pembayaran = 'menunggu_verifikasi'"
            );
            $updateOrder->execute([(int) $_SESSION['id_user'], $note, $transactionId]);
            if ($updateOrder->rowCount() !== 1) {
                throw new DomainException('Status transaksi berubah saat penolakan. Silakan muat ulang halaman.');
            }
            $pdo->commit();
            flash(
                'success',
                (int) $transaction['simulasi_pembayaran'] === 1
                    ? 'Simulasi dibatalkan. Stok tidak berubah.'
                    : 'Pesanan dibatalkan. Stok tidak berubah.'
            );
        }
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('warning', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Verifikasi pembayaran gagal: ' . $exception->getMessage());
        flash('danger', 'Verifikasi tidak dapat diselesaikan. Tidak ada perubahan stok yang disimpan.');
    }
    redirect('admin_pembayaran.php');
}

$pendingOrders = $pdo->query(
    "SELECT t.id_transaksi, t.tanggal, t.total_belanja, t.diskon, t.metode_pembayaran,
            t.simulasi_pembayaran,
            t.bukti_pembayaran, COALESCE(p.nama_pelanggan, 'Pelanggan dihapus') AS nama_pelanggan,
            p.no_telepon
     FROM tbl_transaksi t
     LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
     WHERE t.status_pembayaran = 'menunggu_verifikasi'
     ORDER BY t.tanggal ASC, t.id_transaksi ASC"
)->fetchAll();

// Cek kolom pendukung Duitku untuk menampilkan transaksi Duitku yang masih pending
$duitkuCols = $pdo->query('SHOW COLUMNS FROM tbl_transaksi')->fetchAll(PDO::FETCH_COLUMN, 0);
$hasMOrderId = in_array('merchant_order_id', $duitkuCols, true);
$hasRef = in_array('duitku_reference', $duitkuCols, true);
$hasVa = in_array('duitku_va_number', $duitkuCols, true);
$hasUrl = in_array('duitku_payment_url', $duitkuCols, true);

$duitkuExtraSelect = ($hasMOrderId ? ', t.merchant_order_id' : '')
    . ($hasRef ? ', t.duitku_reference' : '')
    . ($hasVa ? ', t.duitku_va_number' : '')
    . ($hasUrl ? ', t.duitku_payment_url' : '');

$pendingDuitkuOrders = $pdo->query(
    "SELECT t.id_transaksi, t.tanggal, t.total_belanja, t.diskon, t.metode_pembayaran,
            t.simulasi_pembayaran, t.status_pembayaran, t.catatan_pembayaran,
            COALESCE(p.nama_pelanggan, t.nama_kasir, 'Pelanggan Walk-in') AS nama_pelanggan,
            p.no_telepon {$duitkuExtraSelect}
     FROM tbl_transaksi t
     LEFT JOIN tbl_pelanggan p ON p.id_pelanggan = t.id_pelanggan
     WHERE t.status_pembayaran = 'menunggu_pembayaran' AND t.metode_pembayaran = 'duitku'
     ORDER BY t.tanggal DESC, t.id_transaksi DESC"
)->fetchAll();

$pageTitle = 'Verifikasi Pembayaran';
require __DIR__ . '/includes/header.php';
?>

<div class="card-panel mb-4">
    <div class="panel-heading">
        <div>
            <h2 class="section-title"><i class="bi bi-credit-card-2-front-fill text-primary"></i> Pembayaran Menunggu Verifikasi</h2>
            <div class="section-subtitle">Periksa bukti sebelum menyetujui pembayaran. Persetujuan memotong stok secara atomik; item simulasi bukan pembayaran nyata.</div>
        </div>
        <span class="badge-soft badge-amber"><?= count($pendingOrders) ?> menunggu</span>
    </div>
</div>

<div class="card-panel">
    <?php if (!$pendingOrders): ?>
        <div class="empty-state py-5">
            <i class="bi bi-check2-circle"></i>
            <div class="empty-state-text">Tidak ada pembayaran yang menunggu verifikasi.</div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Transaksi</th>
                        <th>Pelanggan</th>
                        <th>Waktu</th>
                        <th>Metode</th>
                        <th>Total</th>
                        <th>Bukti</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingOrders as $order): ?>
                    <tr>
                        <td>TRX-<?= str_pad((string) $order['id_transaksi'], 6, '0', STR_PAD_LEFT) ?></td>
                        <td>
                            <strong><?= e($order['nama_pelanggan']) ?></strong>
                            <small class="d-block text-muted"><?= e($order['no_telepon'] ?: '-') ?></small>
                        </td>
                        <td><?= e(date('d/m/Y H:i', strtotime($order['tanggal']))) ?></td>
                        <td>
                            <span class="badge-soft badge-indigo"><?= e(strtoupper($order['metode_pembayaran'])) ?></span>
                            <?php if ((int) $order['simulasi_pembayaran'] === 1): ?>
                                <span class="badge text-bg-warning d-block mt-1">SIMULASI</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= rupiah($order['total_belanja']) ?></strong>
                            <small class="d-block text-success">Diskon member <?= rupiah($order['diskon']) ?></small>
                        </td>
                        <td>
                            <?php
                            $proofFilename = (string) ($order['bukti_pembayaran'] ?? '');
                            $proofExtension = strtolower(pathinfo($proofFilename, PATHINFO_EXTENSION));
                            $proofUrl = 'lihat_bukti_pembayaran.php?id=' . (int) $order['id_transaksi'];
                            ?>
                            <?php if (in_array($proofExtension, ['jpg', 'png', 'webp', 'gif'], true)): ?>
                                <button class="proof-preview-button" type="button"
                                        data-bs-toggle="modal" data-bs-target="#proof-preview-modal"
                                        data-proof-src="<?= e($proofUrl) ?>"
                                        data-proof-alt="Bukti pembayaran transaksi TRX-<?= str_pad((string) $order['id_transaksi'], 6, '0', STR_PAD_LEFT) ?>">
                                    <img class="proof-thumbnail" src="<?= e($proofUrl) ?>"
                                         alt="Pratinjau bukti pembayaran" loading="lazy">
                                    <span class="d-block small text-primary mt-1">Lihat bukti</span>
                                </button>
                            <?php elseif ($proofExtension === 'pdf'): ?>
                                <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
                                   href="<?= e($proofUrl) ?>">
                                    <i class="bi bi-file-earmark-pdf me-1"></i>Unduh PDF
                                </a>
                            <?php else: ?>
                                <span class="text-danger">Bukti tidak tersedia</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-flex flex-column flex-xl-row justify-content-end gap-2">
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="id_transaksi" value="<?= (int) $order['id_transaksi'] ?>">
                                    <button class="btn btn-sm btn-success" type="submit"
                                            data-confirm="<?= (int) $order['simulasi_pembayaran'] === 1
                                                ? 'Setujui simulasi ini untuk menguji pemotongan stok? Ini bukan pembayaran nyata.'
                                                : 'Pembayaran telah diverifikasi di rekening/QRIS? Persetujuan akan memotong stok.' ?>">
                                        <i class="bi bi-check-lg me-1"></i><?= (int) $order['simulasi_pembayaran'] === 1
                                            ? 'Setujui simulasi'
                                            : 'Setujui &amp; potong stok' ?>
                                    </button>
                                </form>
                                <form method="post" class="d-flex gap-1">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="id_transaksi" value="<?= (int) $order['id_transaksi'] ?>">
                                    <input class="form-control form-control-sm" name="catatan_pembayaran" maxlength="255"
                                           placeholder="Alasan penolakan" required aria-label="Alasan penolakan">
                                    <button class="btn btn-sm btn-outline-danger" type="submit"
                                            data-confirm="Batalkan pesanan ini? Stok tidak akan dipotong.">
                                        Tolak / Batalkan
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Panel Transaksi Duitku Payment Gateway (Menunggu Pembayaran) -->
<div class="card-panel mt-4">
    <div class="panel-heading">
        <div>
            <h2 class="section-title"><i class="bi bi-lightning-charge-fill text-primary"></i> Transaksi Duitku Payment Gateway (Menunggu Pembayaran)</h2>
            <div class="section-subtitle">Transaksi non-tunai yang dibuat melalui Duitku Sandbox. Klik <strong>Cek Status Duitku</strong> untuk mengecek pelunasan aktif dari Duitku &amp; memotong stok produk secara atomik.</div>
        </div>
        <span class="badge-soft badge-indigo"><?= count($pendingDuitkuOrders) ?> menunggu bayar</span>
    </div>

    <div class="panel-body p-0">
        <?php if (!$pendingDuitkuOrders): ?>
            <div class="empty-state py-5">
                <i class="bi bi-check2-all text-success"></i>
                <div class="empty-state-text">Tidak ada transaksi Duitku yang menggantung / menunggu pembayaran. Seluruh transaksi telah lunas atau selesai.</div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>No. Transaksi</th>
                            <th>Order ID &amp; Ref / VA</th>
                            <th>Pelanggan</th>
                            <th>Waktu Transaksi</th>
                            <th>Kanal Duitku</th>
                            <th>Total Nominal</th>
                            <th>Status</th>
                            <th class="text-end">Aksi Verifikasi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingDuitkuOrders as $order): ?>
                        <tr id="duitku-row-<?= (int) $order['id_transaksi'] ?>">
                            <td>
                                <span class="badge-soft badge-indigo font-monospace fw-bold">
                                    TRX-<?= str_pad((string) $order['id_transaksi'], 6, '0', STR_PAD_LEFT) ?>
                                </span>
                            </td>
                            <td>
                                <strong class="d-block small font-monospace text-slate-800"><?= e($order['merchant_order_id'] ?? '-') ?></strong>
                                <?php if (!empty($order['duitku_va_number'])): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace" style="font-size: 11px;">
                                        VA: <?= e($order['duitku_va_number']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($order['duitku_reference'])): ?>
                                    <span class="badge bg-warning-subtle text-dark border border-warning-subtle font-monospace" style="font-size: 11px;">
                                        Ref: <?= e($order['duitku_reference']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= e($order['nama_pelanggan']) ?></strong>
                                <small class="d-block text-muted"><?= e($order['no_telepon'] ?: '-') ?></small>
                            </td>
                            <td>
                                <small class="text-muted d-block"><?= e(date('d/m/Y', strtotime($order['tanggal']))) ?></small>
                                <span class="badge-soft badge-indigo py-0 px-2"><?= e(date('H:i', strtotime($order['tanggal']))) ?> WIB</span>
                            </td>
                            <td>
                                <span class="badge bg-primary text-white">DUITKU</span>
                                <small class="d-block text-muted mt-1" style="font-size: 11px;"><?= e(mb_substr((string) $order['catatan_pembayaran'], 0, 40)) ?></small>
                            </td>
                            <td>
                                <strong class="text-primary"><?= rupiah($order['total_belanja']) ?></strong>
                                <?php if ((float) $order['diskon'] > 0): ?>
                                    <small class="d-block text-success">Diskon: <?= rupiah($order['diskon']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-soft badge-amber">
                                    <i class="bi bi-clock-history me-1"></i>Menunggu Pembayaran
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="d-flex gap-1 justify-content-end">
                                    <button type="button" class="btn btn-sm btn-primary btn-check-duitku shadow-xs"
                                            data-order-id="<?= (int) $order['id_transaksi'] ?>"
                                            data-merchant-order-id="<?= e($order['merchant_order_id'] ?? '') ?>">
                                        <i class="bi bi-arrow-repeat me-1"></i> Cek Status Duitku
                                    </button>
                                    <a href="https://sandbox.duitku.com/payment/demo/demosuccesstransaction.aspx"
                                       target="_blank" rel="noopener"
                                       class="btn btn-sm btn-outline-success"
                                       title="Buka Simulator Demo Duitku">
                                        <i class="bi bi-box-arrow-up-right"></i> Demo
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="proof-preview-modal" tabindex="-1" aria-labelledby="proof-preview-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="proof-preview-title">Bukti Pembayaran</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body text-center bg-dark">
                <img id="proof-preview-image" class="img-fluid" src="" alt="">
            </div>
        </div>
    </div>
</div>

<style>
    .proof-preview-button { border: 0; padding: 0; background: transparent; text-align: center; }
    .proof-thumbnail { width: 76px; height: 76px; object-fit: cover; border: 1px solid #dee2e6; border-radius: .5rem; }
    #proof-preview-image { max-height: calc(100vh - 160px); object-fit: contain; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('proof-preview-modal');
    var image = document.getElementById('proof-preview-image');
    if (!modal || !image) return;

    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;
        image.src = trigger.getAttribute('data-proof-src') || '';
        image.alt = trigger.getAttribute('data-proof-alt') || 'Bukti pembayaran';
    });
    modal.addEventListener('hidden.bs.modal', function () {
        image.removeAttribute('src');
        image.alt = '';
    });

    // Handler Pengecekan Status Transaksi Duitku Real-Time oleh Admin / Kasir
    document.querySelectorAll('.btn-check-duitku').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var orderId = btn.getAttribute('data-order-id');
            var merchantOrderId = btn.getAttribute('data-merchant-order-id');
            var origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Cek API...';

            fetch('cek_status_transaksi.php?order_id=' + encodeURIComponent(orderId) + '&merchant_order_id=' + encodeURIComponent(merchantOrderId) + '&_t=' + Date.now())
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    if (!data.success) {
                        alert('Gagal mengecek status: ' + (data.message || 'Terjadi kesalahan sistem.'));
                        return;
                    }
                    if (data.is_paid || data.status === 'lunas') {
                        alert('✅ PEMBAYARAN LUNAS!\n\nTransaksi TRX-' + String(orderId).padStart(6, '0') + ' (' + (data.merchant_order_id || '') + ') telah sukses diverifikasi resmi oleh Duitku. Stok produk di tbl_produk telah otomatis dipotong secara aman.');
                        location.reload();
                    } else if (data.status === 'dibatalkan' || data.is_expired) {
                        alert('⚠️ Transaksi ini berstatus DIBATALKAN / KEDALUWARSA.');
                        location.reload();
                    } else {
                        alert('⏳ Status Transaksi: MENUNGGU PEMBAYARAN.\n\nPelanggan belum menyelesaikan pembayaran di Duitku. Anda dapat mencoba mensimulasikan pembayaran di demo Duitku terlebih dahulu.');
                    }
                })
                .catch(function (err) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    console.error(err);
                    alert('Gagal terhubung ke endpoint cek_status_transaksi.php.');
                });
        });
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
