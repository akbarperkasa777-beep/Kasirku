<?php
declare(strict_types=1);

require_once __DIR__ . '/check_session.php';
require_role('pelanggan');

$customerId = (int) $_SESSION['id_pelanggan'];
$userId = (int) $_SESSION['id_user'];

// Ambil data profil pelanggan saat ini
$profileQuery = $pdo->prepare(
    'SELECT u.id_user, u.username, u.nama_lengkap, u.created_at,
            p.id_pelanggan, p.nama_pelanggan, p.no_telepon, p.kota, p.id_card_rfid, p.status_member
     FROM tbl_user u
     JOIN tbl_pelanggan p ON p.id_pelanggan = u.id_pelanggan
     WHERE u.id_user = ? AND u.role = \'pelanggan\'
     LIMIT 1'
);
$profileQuery->execute([$userId]);
$profile = $profileQuery->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    flash('danger', 'Data profil pelanggan tidak ditemukan.');
    redirect('belanja_pelanggan.php');
}

// Proses pembaruan profil (hanya data profil, tanpa password)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim((string) ($_POST['nama_lengkap'] ?? ''));
    $phone = trim((string) ($_POST['no_telepon'] ?? ''));
    $city = trim((string) ($_POST['kota'] ?? 'Ponorogo'));

    if (
        $name === ''
        || mb_strlen($name) < 3
        || mb_strlen($name) > 100
        || preg_match('/[\x00-\x1F\x7F]/', $name)
        || !preg_match('/\A[0-9+() \-]{8,20}\z/D', $phone)
        || strlen((string) preg_replace('/\D/', '', $phone)) < 8
        || mb_strlen($city) > 50
    ) {
        flash('danger', 'Periksa masukan nama lengkap (3–100 karakter), nomor telepon valid (minimal 8 digit angka), dan kota asal.');
        redirect('profil.php');
    }

    try {
        $pdo->beginTransaction();

        // 1. Update tbl_user
        $updateUser = $pdo->prepare('UPDATE tbl_user SET nama_lengkap = ? WHERE id_user = ?');
        $updateUser->execute([$name, $userId]);

        // 2. Update tbl_pelanggan
        $updateCustomer = $pdo->prepare('UPDATE tbl_pelanggan SET nama_pelanggan = ?, no_telepon = ?, kota = ? WHERE id_pelanggan = ?');
        $updateCustomer->execute([$name, $phone, $city, $customerId]);

        $pdo->commit();

        $_SESSION['nama'] = $name;
        flash('success', 'Profil berhasil disimpan dan diperbarui.');
        redirect('profil.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Gagal memperbarui profil: ' . $e->getMessage());
        flash('danger', 'Terjadi kesalahan sistem saat memperbarui profil. Silakan coba kembali.');
        redirect('profil.php');
    }
}

$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Profil Saya';
$isMemberActive = (($profile['status_member'] ?? '') === 'aktif');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | AkbarMart Ponorogo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; color: #1e293b; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .member-card-box {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: #ffffff;
            border-radius: 16px;
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25);
        }
        .member-card-box::after {
            content: "";
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.25) 0%, transparent 70%);
            border-radius: 50%;
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/navbar_pelanggan.php'; ?>

<main class="container py-4" style="max-width: 960px;">
    <?php if ($flashMessage): ?>
        <div class="alert alert-<?= e($flashMessage['type']) ?> alert-dismissible fade show d-flex align-items-center gap-2 mb-4 shadow-sm" role="alert">
            <i class="bi <?= $flashMessage['type'] === 'success' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-danger' ?> fs-5"></i>
            <div><?= e($flashMessage['message']) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Kolom Kiri: Kartu Identitas & Status Member RFID -->
        <div class="col-lg-5">
            <!-- Kartu Member Virtual -->
            <div class="member-card-box mb-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-shop fs-4 text-primary"></i>
                        <strong class="fs-6 letter-spacing">AKBARMART MEMBER</strong>
                    </div>
                    <?php if ($isMemberActive): ?>
                        <span class="badge bg-success text-white py-1 px-2" style="font-size: 11px;">
                            <i class="bi bi-patch-check-fill me-1"></i>Diskon 10% Aktif
                        </span>
                    <?php else: ?>
                        <span class="badge bg-secondary text-white py-1 px-2" style="font-size: 11px;">
                            Member Non-Aktif
                        </span>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <span class="small text-white-50 d-block" style="font-size: 11px;">NOMOR KARTU RFID:</span>
                    <code class="fs-5 fw-bold text-white font-monospace"><?= e($profile['id_card_rfid'] ?: 'BELUM TERDAFTAR') ?></code>
                </div>

                <div class="d-flex justify-content-between align-items-end pt-2 border-top border-secondary">
                    <div>
                        <span class="small text-white-50 d-block" style="font-size: 11px;">NAMA PELANGGAN:</span>
                        <strong class="text-white"><?= e($profile['nama_lengkap']) ?></strong>
                    </div>
                    <div class="text-end">
                        <span class="small text-white-50 d-block" style="font-size: 11px;">KOTA ASAL:</span>
                        <span class="text-white small"><?= e($profile['kota'] ?: 'Ponorogo') ?></span>
                    </div>
                </div>
            </div>

            <!-- Kartu Info Gerai Non-Tunai -->
            <div class="card border-0 shadow-sm rounded-4 p-3 mb-3 bg-white">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="p-2 rounded-3 bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-info-circle-fill fs-5"></i>
                    </span>
                    <h6 class="fw-bold mb-0">Ketentuan Gerai AkbarMart</h6>
                </div>
                <p class="small text-muted mb-2">
                    Gerai AkbarMart di Jl. Gajah Mada No. 12, Ponorogo beroperasi dengan <strong>aturan wajib non-tunai</strong>. Anda dapat membayar dengan mudah menggunakan Virtual Account, QRIS, atau E-Wallet via Payment Gateway Duitku.
                </p>
                <div class="small text-success fw-semibold">
                    <i class="bi bi-check-circle me-1"></i> Diskon otomatis 10% setiap transaksi saat member RFID aktif.
                </div>
            </div>

            <!-- Tombol Navigasi Cepat ke Ubah Password -->
            <div class="card border-0 shadow-sm rounded-4 p-3 bg-white text-center">
                <span class="small text-muted mb-2">Ingin mengganti kata sandi akun?</span>
                <a href="ubah_password.php" class="btn btn-outline-primary btn-sm rounded-pill fw-semibold py-2">
                    <i class="bi bi-shield-lock me-1"></i> Buka Halaman Ubah Password
                </a>
            </div>
        </div>

        <!-- Kolom Kanan: Form Edit Profil -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                <div class="d-flex align-items-center justify-content-between pb-3 mb-4 border-bottom">
                    <div>
                        <h5 class="fw-bold text-slate-900 mb-1">
                            <i class="bi bi-person-lines-fill text-primary me-2"></i>Edit Data Profil
                        </h5>
                        <span class="small text-muted">Perbarui data kontak dan identitas akun belanja Anda</span>
                    </div>
                    <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill small">
                        Username: <strong><?= e($profile['username']) ?></strong>
                    </span>
                </div>

                <form method="post" action="profil.php">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                    <!-- Username (Readonly) -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-slate-800" for="input-username">
                            Username Akun <span class="text-muted fw-normal">(Tidak dapat diubah)</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-light text-muted border-end-0"><i class="bi bi-person"></i></span>
                            <input type="text" id="input-username" class="form-control bg-light border-start-0" value="<?= e($profile['username']) ?>" readonly>
                        </div>
                    </div>

                    <!-- Nama Lengkap -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-slate-800" for="input-name">
                            Nama Lengkap <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-white text-primary border-end-0"><i class="bi bi-person-vcard"></i></span>
                            <input 
                                type="text" 
                                class="form-control border-start-0" 
                                id="input-name" 
                                name="nama_lengkap" 
                                value="<?= e($profile['nama_lengkap']) ?>" 
                                required 
                                minlength="3" 
                                maxlength="100" 
                                placeholder="Masukkan nama lengkap Anda">
                        </div>
                    </div>

                    <!-- Nomor Telepon / WhatsApp -->
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-slate-800" for="input-phone">
                            Nomor Telepon / WhatsApp <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-white text-primary border-end-0"><i class="bi bi-telephone"></i></span>
                            <input 
                                type="tel" 
                                class="form-control border-start-0" 
                                id="input-phone" 
                                name="no_telepon" 
                                value="<?= e($profile['no_telepon']) ?>" 
                                required 
                                pattern="[0-9+() \-]{8,20}" 
                                placeholder="Contoh: 081234567890">
                        </div>
                        <div class="form-text" style="font-size: 11.5px;">Nomor aktif digunakan untuk konfirmasi transaksi dan notifikasi struk.</div>
                    </div>

                    <!-- Kota Asal -->
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-slate-800" for="input-city">
                            Kota Domisili
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-white text-primary border-end-0"><i class="bi bi-geo-alt"></i></span>
                            <input 
                                type="text" 
                                class="form-control border-start-0" 
                                id="input-city" 
                                name="kota" 
                                value="<?= e($profile['kota'] ?: 'Ponorogo') ?>" 
                                maxlength="50" 
                                placeholder="Contoh: Ponorogo, Madiun, dll.">
                        </div>
                    </div>

                    <!-- Tombol Aksi Simpan -->
                    <div class="d-flex align-items-center justify-content-between pt-3 border-top">
                        <a href="belanja_pelanggan.php" class="btn btn-light px-3 fw-semibold">
                            <i class="bi bi-arrow-left me-1"></i> Kembali Belanja
                        </a>
                        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                            <i class="bi bi-check2-circle me-1"></i> Simpan Pembaruan Profil
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
