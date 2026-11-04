<?php
// FILE: includes/sidebar.php
?>
<!-- CSS Responsif Sidebar Mobile, Desktop, & App Switcher -->
<style>
/* Sembunyikan tombol toggle secara default di layar Desktop */
.header-left .menu-toggle {
    display: none;
}

@media (max-width: 768px) {
    /* Tampilkan tombol toggle HANYA di layar Mobile (<= 768px) */
    .header-left .menu-toggle {
        display: inline-block !important;
        margin-right: 10px;
    }
    
    /* Sembunyikan sidebar ke luar layar kiri secara default di mobile */
    .sidebar {
        position: fixed;
        top: 0;
        left: -260px;
        width: 260px;
        height: 100vh;
        z-index: 1000;
        transition: left 0.3s ease;
    }

    /* Tampilkan sidebar saat class 'active' atau 'show' di-toggle */
    .sidebar.active,
    .sidebar.show {
        left: 0;
    }

    /* Overlay latar belakang saat sidebar terbuka di mobile */
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0, 0, 0, 0.5);
        z-index: 999;
    }
    .sidebar-overlay.active {
        display: block;
    }
}

/* App Switcher Styles */
.app-switcher-container {
    position: relative;
    padding: 12px 15px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.app-switcher-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    color: #fff;
    cursor: pointer;
    transition: all 0.2s ease;
}
.app-switcher-btn:hover {
    background: rgba(255, 255, 255, 0.1);
}
.app-switcher-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    left: 15px;
    right: 15px;
    margin-top: 5px;
    background: #1e293b;
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 10px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.5);
    z-index: 100;
    overflow: hidden;
}
.app-switcher-dropdown.show {
    display: block;
}
.app-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    color: #cbd5e1;
    text-decoration: none;
    font-size: 12px;
    transition: background 0.2s;
}
.app-item:hover {
    background: rgba(255, 255, 255, 0.05);
    color: #fff;
}
.app-item.active {
    background: rgba(16, 185, 129, 0.15);
    border-left: 3px solid #10b981;
    color: #fff;
}
</style>

<!-- Overlay untuk menutup sidebar saat klik di luar (Mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <!-- App Switcher Element (dengan Logo tetap tampil) -->
    <div class="app-switcher-container">
        <button type="button" class="app-switcher-btn" id="appSwitcherBtn">
            <div style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                <!-- Logo Perusahaan -->
                <img src="/../../assets/img/logo.png" alt="Logo" style="max-height: 36px; border-radius: 6px; flex-shrink: 0;">
                
                <div style="text-align: left; line-height: 1.2;">
                    <div style="font-weight: 700; font-size: 13px; color: #fff;">Aplikasi Arsip</div>
                    <small style="color: #94a3b8; font-size: 10px;">Sistem E-Arsip</small>
                </div>
            </div>
            <i class="fas fa-chevron-down" style="font-size: 11px; color: #94a3b8;"></i>
        </button>

        <div class="app-switcher-dropdown" id="appSwitcherDropdown">
            <div style="padding: 6px 12px; font-size: 9px; font-weight: 700; color: #64748b; text-transform: uppercase;">Pilih Aplikasi</div>
            
            <!-- Target App (Disposisi) -->
            <a href="https://dms.lju.co.id" class="app-item">
                <div style="width: 28px; height: 28px; border-radius: 6px; background: rgba(59, 130, 246, 0.2); color: #60a5fa; display: flex; align-items: center; justify-content: center; font-size: 11px;">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 600;">Aplikasi Naskah Dinas</div>
                    <div style="font-size: 9px; color: #64748b;">Sistem Surat & Disposisi</div>
                </div>
                <i class="fas fa-external-link-alt" style="font-size: 9px; color: #64748b;"></i>
            </a>

            <!-- Current App (Arsip) -->
            <a href="https://dms.lju.co.id/arsip" class="app-item active">
                <div style="width: 28px; height: 28px; border-radius: 6px; background: rgba(16, 185, 129, 0.2); color: #34d399; display: flex; align-items: center; justify-content: center; font-size: 11px;">
                    <i class="fas fa-archive"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 600;">Aplikasi Arsip</div>
                    <div style="font-size: 9px; color: #94a3b8;">Sistem E-Arsip</div>
                </div>
                <i class="fas fa-check" style="font-size: 11px; color: #34d399;"></i>
            </a>
        </div>
    </div>

    <nav class="sidebar-menu">
        <div class="menu-label">Menu Utama</div>
        <a href="<?= BASE_URL ?>modules/dashboard/" class="<?= $currentPage == 'dashboard' ? 'active' : '' ?>">
            <span class="icon"><i class="fas fa-tachometer-alt"></i></span> Dashboard
        </a>
        <a href="<?= BASE_URL ?>modules/documents/" class="<?= $currentPage == 'documents' ? 'active' : '' ?>">
            <span class="icon"><i class="fas fa-file-alt"></i></span> Dokumen
        </a>
        <?php if (canUploadAndManage()): ?>
            <a href="<?= BASE_URL ?>modules/documents/upload.php" class="<?= basename($_SERVER['SCRIPT_NAME']) == 'upload.php' ? 'active' : '' ?>">
                <span class="icon"><i class="fas fa-cloud-upload-alt"></i></span> Upload Dokumen
            </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>modules/documents/search.php" class="<?= basename($_SERVER['SCRIPT_NAME']) == 'search.php' ? 'active' : '' ?>">
            <span class="icon"><i class="fas fa-search"></i></span> Pencarian
        </a>
        <?php if (isAdmin() || canUploadAndManage()): ?>
            <div class="menu-label">Organisasi</div>
            <a href="<?= BASE_URL ?>modules/categories/" class="<?= $currentPage == 'categories' ? 'active' : '' ?>">
                <span class="icon"><i class="fas fa-layer-group"></i></span> Kategori
            </a>
            <a href="<?= BASE_URL ?>modules/folders/" class="<?= $currentPage == 'folders' ? 'active' : '' ?>">
                <span class="icon"><i class="fas fa-folder-tree"></i></span> Folder
            </a>
            <a href="<?= BASE_URL ?>modules/tags/" class="<?= $currentPage == 'tags' ? 'active' : '' ?>">
                <span class="icon"><i class="fas fa-tags"></i></span> Tags
            </a>
            <a href="<?= BASE_URL ?>modules/locations/" class="<?= $currentPage == 'locations' ? 'active' : '' ?>">
                <span class="icon"><i class="fas fa-map-marker-alt"></i></span> Lokasi Fisik
            </a>
            <a href="<?= BASE_URL ?>modules/loans/" class="<?= $currentPage == 'loans' ? 'active' : '' ?>">
                <span class="icon"><i class="fas fa-handshake"></i></span> Peminjaman
            </a>
         <?php endif; ?>
        <div class="menu-label">Notifikasi</div>
        <a href="<?= BASE_URL ?>modules/alerts/" class="<?= $currentPage == 'alerts' ? 'active' : '' ?>">
            <span class="icon"><i class="fas fa-bell"></i></span> Pengingat
            <?php if ($notifCount > 0): ?>
            <span class="badge-menu"><?= $notifCount ?></span>
            <?php endif; ?>
        </a>

        <a href="<?= BASE_URL ?>modules/audit/" class="<?= $currentPage == 'audit' ? 'active' : '' ?>">
            <span class="icon"><i class="fas fa-clipboard-list"></i></span> System Activity
        </a>

        <?php if (isAdmin() || canUploadAndManage()): ?>
            <div class="menu-label">Administrasi</div>
            
            <?php if (isAdmin()): ?>
            <a href="<?= BASE_URL ?>modules/users/" class="<?= $currentPage == 'users' ? 'active' : '' ?>">
                <span class="icon"><i class="fas fa-users-cog"></i></span> Kelola Pengguna
            </a>
            <?php endif; ?>
            
            <a href="<?= BASE_URL ?>modules/permissions/" class="<?= $currentPage == 'permissions' ? 'active' : '' ?>">
                <span class="icon"><i class="fas fa-shield-alt"></i></span> Hak Akses
            </a>
        <?php endif; ?>
    </nav>
</aside>

<div class="main-content">
    <header class="header">
        <div class="header-left">
            <button class="btn btn-outline menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h4 style="font-size:15px; color: var(--secondary);">
                <?php
                $pageTitles = [
                    'dashboard'   => '📊 Dashboard',
                    'documents'   => '📄 Dokumen',
                    'categories'  => '📁 Kategori',
                    'folders'     => '📂 Folder',
                    'tags'        => '🏷️ Tags',
                    'alerts'      => '🔔 Notifikasi',
                    'users'       => '👥 Pengguna',
                    'permissions' => '🔒 Hak Akses',
                    'audit'       => '📋 System Activity',
                    'locations'   => '📍 Lokasi Fisik',
                    'loans'       => '🔄 Peminjaman'
                ];
                echo $pageTitles[$currentPage] ?? '📄 E-DAMS';
                ?>
            </h4>
        </div>
        <div class="header-right">
            <!-- Notification Bell -->
            <div class="notification-bell" style="position:relative;">
                <i class="fas fa-bell" style="font-size:18px; color: var(--secondary);"></i>
                <?php if ($notifCount > 0): ?>
                <span class="badge-count"><?= $notifCount ?></span>
                <?php endif; ?>
                
                <div class="notification-dropdown">
                    <div style="padding:12px 16px; border-bottom:1px solid var(--border); font-weight:600;">
                        Notifikasi (<?= $notifCount ?>)
                    </div>
                    <?php if (empty($notifications)): ?>
                    <div style="padding:20px; text-align:center; color:var(--secondary);">
                        Tidak ada notifikasi baru
                    </div>
                    <?php else: ?>
                    <?php foreach ($notifications as $notif): ?>
                    <div class="notification-item unread" data-id="<?= $notif['id'] ?>">
                        <div class="notif-icon" style="background:<?= $notif['type'] == 'expiry_expired' ? '#fee2e2' : '#fef3c7' ?>; color:<?= $notif['type'] == 'expiry_expired' ? 'var(--danger)' : 'var(--warning)' ?>;">
                            <i class="fas <?= $notif['type'] == 'expiry_expired' ? 'fa-exclamation-circle' : 'fa-clock' ?>"></i>
                        </div>
                        <div class="notif-content">
                            <h5><?= htmlspecialchars($notif['title']) ?></h5>
                            <p><?= htmlspecialchars(substr($notif['message'], 0, 80)) ?>...</p>
                            <span class="notif-time"><?= date('d/m/Y H:i', strtotime($notif['created_at'])) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <div style="padding:10px; text-align:center; border-top:1px solid var(--border);">
                        <a href="<?= BASE_URL ?>modules/alerts/" style="font-size:13px;">Lihat Semua</a>
                    </div>
                </div>
            </div>

            <!-- User Info -->
            <a href="<?= BASE_URL ?>modules/users/profile.php" class="user-info" style="text-decoration: none; color: inherit;">
                <div class="user-avatar" style="width: 36px; height: 36px; border-radius: 50%; overflow: hidden; display: flex; align-items: center; justify-content: center; background: var(--primary); color: #fff;">
                    <?php 
                    $avatarPath = AVATAR_DIR . $currentUser['avatar'];
                    $avatarFileName = $currentUser['avatar'] ?? '';
                    $avatarFilePath = AVATAR_DIR . $avatarFileName;

                    if (!empty($avatarFileName) && file_exists($avatarFilePath)):
                    ?>
                        <img src="<?= AVATAR_URL . htmlspecialchars($avatarFileName) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        <?= strtoupper(substr($currentUser['name'], 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div class="user-details">
                    <div class="name"><?= htmlspecialchars($currentUser['name']) ?></div>
                    <div class="role"><?= htmlspecialchars($currentUser['position'] ?: $currentUser['role']) ?> | <?= htmlspecialchars($currentUser['division']) ?></div>
                </div>
            </a>
            <a href="https://lju.co.id/dashboard" class="btn btn-outline btn-sm" title="Dashboard">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </header>

    <div class="content-area">
        <?php
        $flash = getFlash();
        if ($flash):
        ?>
        <div class="alert alert-<?= $flash['type'] ?>">
            <i class="fas <?= $flash['type'] == 'success' ? 'fa-check-circle' : ($flash['type'] == 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle') ?>"></i>
            <?= htmlspecialchars($flash['message']) ?>
        </div>
        <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Menu Toggle untuk Mobile
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function (e) {
            e.preventDefault();
            sidebar.classList.toggle('active');
            sidebar.classList.toggle('show');
            if (overlay) overlay.classList.toggle('active');
        });
    }

    if (overlay) {
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('active', 'show');
            overlay.classList.remove('active');
        });
    }

    // App Switcher Dropdown Toggle
    const btn = document.getElementById('appSwitcherBtn');
    const dropdown = document.getElementById('appSwitcherDropdown');

    if(btn && dropdown) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            dropdown.classList.toggle('show');
        });

        document.addEventListener('click', function() {
            dropdown.classList.remove('show');
        });
    }
});
</script>