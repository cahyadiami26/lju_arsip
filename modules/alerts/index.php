<?php
// FILE: modules/alerts/index.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();
$user = currentUser();

// AJAX: mark notification as read
if (isset($_GET['action']) && $_GET['action'] === 'mark_read') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $user['id']]);
    echo json_encode(['success' => true]);
    exit;
}

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user['id']]);
    setFlash('success', 'Semua notifikasi telah ditandai dibaca');
    header('Location: ' . BASE_URL . 'modules/alerts/');
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';

// Check for new expiry alerts
checkExpiryAlerts();

// Get all notifications
$stmt = $db->prepare("SELECT n.*, d.archive_number FROM notifications n LEFT JOIN documents_arsip d ON n.document_id = d.id WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT 100");
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

// Get expiring documents
$expiringDocs = $db->query("
    SELECT d.*, dc.name as category_name, 
           DATEDIFF(d.expiry_date, CURDATE()) as days_left
    FROM documents_arsip d 
    LEFT JOIN document_categories dc ON d.category_id = dc.id 
    WHERE d.expiry_date IS NOT NULL 
    AND d.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) 
    AND d.status = 'active'
    ORDER BY d.expiry_date ASC
")->fetchAll();

$expiredDocs = $db->query("
    SELECT d.*, dc.name as category_name, 
           DATEDIFF(CURDATE(), d.expiry_date) as days_expired
    FROM documents_arsip d 
    LEFT JOIN document_categories dc ON d.category_id = dc.id 
    WHERE d.status = 'expired' 
    ORDER BY d.expiry_date DESC 
    LIMIT 20
")->fetchAll();
?>

<div class="d-flex justify-between align-center mb-3">
    <h3></h3>
    <a href="?mark_all_read=1" class="btn btn-outline"><i class="fas fa-check-double"></i> Tandai Semua Dibaca</a>
</div>

<!-- Tabs -->
<div class="card">
    <div class="card-body" style="padding-bottom:0;">
        <div class="tabs">
            <span class="tab-link active" data-tab="tab-notif">🔔 Notifikasi (<?= count($notifications) ?>)</span>
            <span class="tab-link" data-tab="tab-expiring">⚠️ Akan Expired (<?= count($expiringDocs) ?>)</span>
            <span class="tab-link" data-tab="tab-expired">🔴 Sudah Expired (<?= count($expiredDocs) ?>)</span>
        </div>
    </div>

    <div class="tab-content active" id="tab-notif">
        <div class="card-body" style="padding:0;">
            <?php if (empty($notifications)): ?>
            <div style="padding:40px; text-align:center; color:var(--secondary);">
                <i class="fas fa-bell-slash" style="font-size:48px; margin-bottom:12px; display:block;"></i>
                Tidak ada notifikasi
            </div>
            <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
            <div class="notification-item <?= !$notif['is_read'] ? 'unread' : '' ?>" data-id="<?= $notif['id'] ?>">
                <div class="notif-icon" style="background:<?= strpos($notif['type'], 'expired') !== false ? '#fee2e2' : ($notif['type'] == 'expiry_warning' ? '#fef3c7' : '#dbeafe') ?>; color:<?= strpos($notif['type'], 'expired') !== false ? 'var(--danger)' : ($notif['type'] == 'expiry_warning' ? 'var(--warning)' : 'var(--primary)') ?>;">
                    <i class="fas <?= strpos($notif['type'], 'expir') !== false ? 'fa-clock' : ($notif['type'] == 'permission_granted' ? 'fa-key' : 'fa-bell') ?>"></i>
                </div>
                <div class="notif-content" style="flex:1;">
                    <h5><?= htmlspecialchars($notif['title']) ?></h5>
                    <p><?= htmlspecialchars($notif['message']) ?></p>
                    <span class="notif-time"><?= date('d M Y H:i', strtotime($notif['created_at'])) ?></span>
                    <?php if ($notif['document_id']): ?>
                    <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $notif['document_id'] ?>" class="btn btn-info btn-sm" style="margin-left:8px;">Lihat Dokumen</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="tab-content" id="tab-expiring">
        <div class="card-body" style="padding:0;">
            <table>
                <thead><tr><th>Dokumen</th><th>Kategori</th><th>Tanggal Expired</th><th>Sisa Hari</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($expiringDocs as $doc): ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $doc['id'] ?>"><?= htmlspecialchars($doc['title']) ?></a>
                        <div style="font-size:11px;"><code><?= htmlspecialchars($doc['archive_number']) ?></code></div>
                    </td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($doc['category_name'] ?? '-') ?></span></td>
                    <td><?= date('d M Y', strtotime($doc['expiry_date'])) ?></td>
                    <td>
                        <?php if ($doc['days_left'] <= 7): ?>
                        <span class="badge badge-danger"><?= $doc['days_left'] ?> hari</span>
                        <?php elseif ($doc['days_left'] <= 30): ?>
                        <span class="badge badge-warning"><?= $doc['days_left'] ?> hari</span>
                        <?php else: ?>
                        <span class="badge badge-info"><?= $doc['days_left'] ?> hari</span>
                        <?php endif; ?>
                    </td>
                    <td><a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $doc['id'] ?>" class="btn btn-info btn-sm"><i class="fas fa-eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="tab-content" id="tab-expired">
        <div class="card-body" style="padding:0;">
            <table>
                <thead><tr><th>Dokumen</th><th>Kategori</th><th>Expired Pada</th><th>Sudah</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($expiredDocs as $doc): ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $doc['id'] ?>"><?= htmlspecialchars($doc['title']) ?></a>
                    </td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($doc['category_name'] ?? '-') ?></span></td>
                    <td><?= date('d M Y', strtotime($doc['expiry_date'])) ?></td>
                    <td><span class="badge badge-danger"><?= $doc['days_expired'] ?> hari lalu</span></td>
                    <td><a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $doc['id'] ?>" class="btn btn-info btn-sm"><i class="fas fa-eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>