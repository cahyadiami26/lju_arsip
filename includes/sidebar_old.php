<?php
// FILE: includes/sidebar.php
//var_dump($_SESSION);
?>
<aside class="sidebar">
    <div class="sidebar-brand">
        <img src="/../../assets/img/logo.png" alt="Logo" style="max-height: 40px; border-radius: 8px; vertical-align: middle;">
        <h2>ARSIP - LJU</h2>
        <small>Document Archive System</small>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-label">Menu Utama</div>
        <a href="<?= BASE_URL ?>modules/dashboard/" class="<?= $currentPage == 'dashboard' ? 'active' : '' ?>">
            <span class="icon"><i class="fas fa-tachometer-alt"></i></span> Dashboard
        </a>
        <a href="<?= BASE_URL ?>modules/documents/" class="<?= $currentPage == 'documents' ? 'active' : '' ?>">
            <span class="icon"><i class="fas fa-file-alt"></i></span> Dokumen
        </a>
        <!-- Tampilkan menu Upload jika user berhak -->
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
                <?php if (isAdmin() || canUploadAndManage()): ?>
                    <a href="<?= BASE_URL ?>modules/audit/" class="<?= $currentPage == 'audit' ? 'active' : '' ?>">
                        <span class="icon"><i class="fas fa-clipboard-list"></i></span> Audit Trail
                    </a>
                <?php endif; ?>
                
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
            <button class="btn btn-outline menu-toggle" style="display:none;">
                <i class="fas fa-bars"></i>
            </button>
            <h4 style="font-size:15px; color: var(--secondary);">
                <?php
                $pageTitles = [
                    'dashboard' => '📊 Dashboard',
                    'documents' => '📄 Dokumen',
                    'categories' => '📁 Kategori',
                    'folders' => '📂 Folder',
                    'tags' => '🏷️ Tags',
                    'alerts' => '🔔 Notifikasi',
                    'users' => '👥 Pengguna',
                    'permissions' => '🔒 Hak Akses',
                    'audit' => '📋 Audit Trail'
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
             <!-- User Info (Mengarahkan ke halaman Ubah Profil) -->
            <a href="<?= BASE_URL ?>modules/users/profile.php" class="user-info" style="text-decoration: none; color: inherit;">
                <div class="user-avatar" style="width: 36px; height: 36px; border-radius: 50%; overflow: hidden; display: flex; align-items: center; justify-content: center; background: var(--primary); color: #fff;">
                    <?php 
                    //$avatarPath = __DIR__ . '/../../uploads/avatars/' . $currentUser['avatar'];
                    $avatarPath = AVATAR_DIR . $currentUser['avatar'];
                    $avatarFileName = $currentUser['avatar'] ?? '';
                    $avatarFilePath = AVATAR_DIR . $avatarFileName;
                    //echo $avatarPath;

                    if (!empty($avatarFileName) && file_exists($avatarFilePath)):
                    ?>
                        <img src="<?= AVATAR_URL . htmlspecialchars($avatarFileName) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        <?= strtoupper(substr($currentUser['name'], 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div class="user-details">
                    <div class="name"><?= htmlspecialchars($currentUser['name']) ?></div>
                    <!--<div><?php echo $currentUser['avatar']; ?></div>-->
                    <div class="role"><?= htmlspecialchars($currentUser['position'] ?: $currentUser['role']) ?> | <?= htmlspecialchars($currentUser['division']) ?></div>
                </div>
            </a>
            <a href="<?= BASE_URL ?>modules/auth/logout.php" class="btn btn-outline btn-sm" title="Logout">
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