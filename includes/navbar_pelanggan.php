<?php
// Navbar Khusus Pelanggan AkbarMart (Jl. Gajah Mada No. 12, Ponorogo)
$activeFile = basename($_SERVER['PHP_SELF'] ?? '');
$customerSessionName = (string) ($_SESSION['nama'] ?? 'Pelanggan');
$customerSessionId = (int) ($_SESSION['id_pelanggan'] ?? 0);

// Ambil status member RFID jika tersedia
$navIsMemberActive = false;
$navRfidCard = '';
if (!empty($pdo) && $customerSessionId > 0) {
    try {
        $navMemberStmt = $pdo->prepare('SELECT id_card_rfid, status_member FROM tbl_pelanggan WHERE id_pelanggan = ? LIMIT 1');
        $navMemberStmt->execute([$customerSessionId]);
        $navMemberData = $navMemberStmt->fetch(PDO::FETCH_ASSOC);
        if ($navMemberData) {
            $navIsMemberActive = (($navMemberData['status_member'] ?? '') === 'aktif');
            $navRfidCard = (string) ($navMemberData['id_card_rfid'] ?? '');
        }
    } catch (Throwable $e) {}
}
?>
<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top py-2 shadow-sm">
    <div class="container">
        <!-- Brand & Alamat Gerai -->
        <a class="navbar-brand d-flex align-items-center gap-2 py-0" href="belanja_pelanggan.php">
            <span class="p-2 rounded-3 bg-primary text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 40px; height: 40px;">
                <i class="bi bi-shop-window fs-5"></i>
            </span>
            <div>
                <span class="fw-bold fs-5 text-slate-900 d-block lh-1">AkbarMart</span>
                <span class="small text-muted" style="font-size: 11px;">Jl. Gajah Mada No. 12, Ponorogo &bull; <strong class="text-primary">Wajib Non-Tunai</strong></span>
            </div>
        </a>

        <!-- Hamburger Toggle untuk Layar HP -->
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#customerNavbar" aria-controls="customerNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Menu Navigasi Pelanggan -->
        <div class="collapse navbar-collapse mt-2 mt-lg-0" id="customerNavbar">
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0 gap-lg-1">
                <li class="nav-item">
                    <a class="nav-link px-3 rounded-pill <?= $activeFile === 'belanja_pelanggan.php' ? 'active fw-bold text-primary bg-primary-subtle' : 'text-slate-700' ?>" href="belanja_pelanggan.php">
                        <i class="bi bi-cart3 me-1"></i> Belanja
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 rounded-pill <?= $activeFile === 'riwayat_transaksi.php' ? 'active fw-bold text-primary bg-primary-subtle' : 'text-slate-700' ?>" href="riwayat_transaksi.php">
                        <i class="bi bi-clock-history me-1"></i> Riwayat Transaksi
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 rounded-pill <?= $activeFile === 'profil.php' ? 'active fw-bold text-primary bg-primary-subtle' : 'text-slate-700' ?>" href="profil.php">
                        <i class="bi bi-person-circle me-1"></i> Profil Saya
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 rounded-pill <?= $activeFile === 'ubah_password.php' ? 'active fw-bold text-primary bg-primary-subtle' : 'text-slate-700' ?>" href="ubah_password.php">
                        <i class="bi bi-shield-lock me-1"></i> Ubah Password
                    </a>
                </li>
            </ul>

            <!-- Info Akun & Aksi -->
            <div class="d-flex align-items-center gap-2 pt-2 pt-lg-0 border-top border-lg-0">
                <?php if ($navIsMemberActive): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill d-none d-xl-inline-flex align-items-center gap-1" style="font-size: 11px;" title="Kartu Member RFID Aktif: <?= e($navRfidCard) ?>">
                        <i class="bi bi-patch-check-fill"></i> Member 10%
                    </span>
                <?php endif; ?>

                <div class="dropdown">
                    <button class="btn btn-light btn-sm dropdown-toggle rounded-pill px-3 py-1 d-flex align-items-center gap-2 border" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-fill text-primary"></i>
                        <span class="fw-semibold text-truncate" style="max-width: 140px;"><?= e($customerSessionName) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3 mt-1">
                        <li>
                            <h6 class="dropdown-header small text-muted">Masuk sebagai Pelanggan</h6>
                        </li>
                        <li>
                            <a class="dropdown-item py-2 small <?= $activeFile === 'profil.php' ? 'active' : '' ?>" href="profil.php">
                                <i class="bi bi-person-gear me-2 text-primary"></i> Profil Saya
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2 small <?= $activeFile === 'ubah_password.php' ? 'active' : '' ?>" href="ubah_password.php">
                                <i class="bi bi-key me-2 text-primary"></i> Ubah Password
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item py-2 small <?= $activeFile === 'riwayat_transaksi.php' ? 'active' : '' ?>" href="riwayat_transaksi.php">
                                <i class="bi bi-receipt me-2 text-primary"></i> Riwayat Transaksi
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="post" action="logout.php" class="m-0 p-0">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <button type="submit" class="dropdown-item py-2 small text-danger d-flex align-items-center w-100 text-start border-0 bg-transparent">
                                    <i class="bi bi-box-arrow-right me-2"></i> Keluar (Logout)
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>
