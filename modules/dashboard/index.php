<?php
// FILE: modules/dashboard/index.php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';

$db = getDB();
$user = currentUser();
//echo var_dump($_SESSION);
// Check expiry alerts
checkExpiryAlerts();

// Statistics
$totalDocs = $db->query("SELECT COUNT(*) FROM documents_arsip")->fetchColumn();
$activeDocs = $db->query("SELECT COUNT(*) FROM documents_arsip WHERE status='active'")->fetchColumn();
$expiredDocs = $db->query("SELECT COUNT(*) FROM documents_arsip WHERE status='expired'")->fetchColumn();

// Expiring soon (30 days)
$expiringSoon = $db->query("SELECT COUNT(*) FROM documents_arsip WHERE status='active' AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

$totalCategories = $db->query("SELECT COUNT(*) FROM document_categories WHERE is_active=1")->fetchColumn();
$totalUsers = $db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();

// Recent documents
$recentDocs = $db->query("
    SELECT d.*, dc.name as category_name, u.name as uploader_name 
    FROM documents_arsip d 
    LEFT JOIN document_categories dc ON d.category_id = dc.id 
    LEFT JOIN users u ON d.uploaded_by = u.id 
    ORDER BY d.created_at DESC LIMIT 10
")->fetchAll();

// Documents per category chart data
$catStats = $db->query("
    SELECT dc.name, COUNT(d.id) as total 
    FROM document_categories dc 
    LEFT JOIN documents_arsip d ON dc.id = d.category_id 
    WHERE dc.is_active = 1 
    GROUP BY dc.id, dc.name 
    ORDER BY total DESC
")->fetchAll();

// Expiring documents
$expiringDocs = $db->query("
    SELECT d.*, dc.name as category_name, 
           DATEDIFF(d.expiry_date, CURDATE()) as days_left
    FROM documents_arsip d 
    LEFT JOIN document_categories dc ON d.category_id = dc.id 
    WHERE d.status = 'active' 
    AND d.expiry_date IS NOT NULL 
    AND d.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) 
    ORDER BY d.expiry_date ASC 
    LIMIT 10
")->fetchAll();

// Recent activities
$recentActivities = $db->query("
    SELECT * FROM audit_trail 
    ORDER BY created_at DESC LIMIT 15
")->fetchAll();
?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-file-alt"></i></div>
        <div class="stat-info">
            <h4><?= number_format($totalDocs) ?></h4>
            <p>Total Dokumen</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
        <div class="stat-info">
            <h4><?= number_format($activeDocs) ?></h4>
            <p>Dokumen Aktif</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
        <div class="stat-info">
            <h4><?= number_format($expiringSoon) ?></h4>
            <p>Segera Expired</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="stat-info">
            <h4><?= number_format($expiredDocs) ?></h4>
            <p>Sudah Expired</p>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 24px;">
    <!-- Recent Documents -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-clock"></i> Dokumen Terbaru</h3>
            <a href="<?= BASE_URL ?>modules/documents/" class="btn btn-outline btn-sm">Lihat Semua</a>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>No. Arsip</th>
                            <th>Judul</th>
                            <th>Kategori</th>
                            <th>Status</th>
                            <th>Tanggal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recentDocs)): ?>
                        <tr><td colspan="5" class="text-center text-muted" style="padding:30px;">Belum ada dokumen</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentDocs as $doc): ?>
                        <tr>
                            <td><code style="font-size:11px;"><?= htmlspecialchars($doc['archive_number']) ?></code></td>
                            <td>
                                <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $doc['id'] ?>">
                                    <?= htmlspecialchars(mb_substr($doc['title'], 0, 40)) ?><?= mb_strlen($doc['title']) > 40 ? '...' : '' ?>
                                </a>
                            </td>
                            <td><span class="badge badge-info"><?= htmlspecialchars($doc['category_name'] ?? '-') ?></span></td>
                            <td>
                                <?php
                                $statusBadges = [
                                    'active' => 'badge-success',
                                    'expired' => 'badge-danger',
                                    'inactive' => 'badge-secondary',
                                    'archived' => 'badge-warning'
                                ];
                                ?>
                                <span class="badge <?= $statusBadges[$doc['status']] ?? 'badge-secondary' ?>">
                                    <?= ucfirst($doc['status']) ?>
                                </span>
                            </td>
                            <td style="font-size:12px; color:var(--secondary);"><?= date('d/m/Y', strtotime($doc['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Expiring Soon -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-exclamation-triangle" style="color:var(--warning);"></i> Segera Expired</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($expiringDocs)): ?>
            <div style="padding:30px; text-align:center; color:var(--secondary);">
                <i class="fas fa-check-circle" style="font-size:32px; color:var(--success); margin-bottom:10px; display:block;"></i>
                Tidak ada dokumen yang akan segera expired
            </div>
            <?php else: ?>
            <?php foreach ($expiringDocs as $doc): ?>
            <div style="padding:12px 16px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $doc['id'] ?>" style="font-weight:500; font-size:13px;">
                        <?= htmlspecialchars(mb_substr($doc['title'], 0, 30)) ?>
                    </a>
                    <div style="font-size:11px; color:var(--secondary);"><?= htmlspecialchars($doc['category_name'] ?? '') ?></div>
                </div>
                <div>
                    <?php if ($doc['days_left'] <= 7): ?>
                    <span class="badge badge-danger"><?= $doc['days_left'] ?> hari</span>
                    <?php elseif ($doc['days_left'] <= 30): ?>
                    <span class="badge badge-warning"><?= $doc['days_left'] ?> hari</span>
                    <?php else: ?>
                    <span class="badge badge-info"><?= $doc['days_left'] ?> hari</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Category Stats & Activity Log -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
    <!-- Per Category -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-chart-pie"></i> Dokumen per Kategori</h3>
        </div>
        <div class="card-body">
            <?php if (empty($catStats)): ?>
            <p class="text-muted text-center">Belum ada data</p>
            <?php else: ?>
            <?php
            $maxTotal = max(array_column($catStats, 'total')) ?: 1;
            $colors = ['#2563eb','#059669','#d97706','#dc2626','#7c3aed','#0891b2'];
            ?>
            <?php foreach ($catStats as $i => $cat): ?>
            <div style="margin-bottom:12px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                    <span style="font-size:13px; font-weight:500;"><?= htmlspecialchars($cat['name']) ?></span>
                    <span style="font-size:13px; font-weight:600;"><?= $cat['total'] ?></span>
                </div>
                <div style="background:#f1f5f9; border-radius:4px; height:8px; overflow:hidden;">
                    <div style="background:<?= $colors[$i % count($colors)] ?>; height:100%; width:<?= ($cat['total']/$maxTotal)*100 ?>%; border-radius:4px; transition:width 1s;"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-history"></i> Aktivitas Terbaru</h3>
            <?php if (isAdmin()): ?>
            <a href="<?= BASE_URL ?>modules/audit/" class="btn btn-outline btn-sm">Detail</a>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding:0; max-height:400px; overflow-y:auto;">
            <?php foreach ($recentActivities as $act): ?>
            <div style="padding:10px 16px; border-bottom:1px solid var(--border); display:flex; gap:10px; align-items:flex-start;">
                <?php
                $actionIcons = [
                    'login' => ['fa-sign-in-alt', '#059669'],
                    'logout' => ['fa-sign-out-alt', '#64748b'],
                    'view' => ['fa-eye', '#2563eb'],
                    'download' => ['fa-download', '#0891b2'],
                    'upload' => ['fa-upload', '#7c3aed'],
                    'edit' => ['fa-edit', '#d97706'],
                    'delete' => ['fa-trash', '#dc2626'],
                    'search' => ['fa-search', '#64748b']
                ];
                $icon = $actionIcons[$act['action']] ?? ['fa-circle', '#64748b'];
                ?>
                <div style="width:28px; height:28px; border-radius:50%; background:<?= $icon[1] ?>20; color:<?= $icon[1] ?>; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:12px;">
                    <i class="fas <?= $icon[0] ?>"></i>
                </div>
                <div style="flex:1; min-width:0;">
                    <div style="font-size:12px;"><strong><?= htmlspecialchars($act['user_name']) ?></strong> — <?= $act['action'] ?></div>
                    <?php if ($act['document_title']): ?>
                    <div style="font-size:11px; color:var(--secondary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        <?= htmlspecialchars($act['document_title']) ?>
                    </div>
                    <?php endif; ?>
                    <div style="font-size:10px; color:#94a3b8;"><?= date('d/m/Y H:i', strtotime($act['created_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>