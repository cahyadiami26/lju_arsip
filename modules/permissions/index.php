<?php
// FILE: modules/permissions/index.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $docId = (int)($_POST['document_id'] ?? 0);

    // PROTEKSI POST: Hanya Sekretariat atau Admin
    if ($action === 'add' && canUploadAndManage() && $docId) {
    $userId     = (int)($_POST['user_id'] ?? 0) ?: null;
    $division   = $_POST['division'] ?? null;
    $department = $_POST['department'] ?? null;  // <-- TAMBAHAN
    $canRead    = isset($_POST['can_read']) ? 1 : 0;
    $canDownload = isset($_POST['can_download']) ? 1 : 0;
    $canEdit    = isset($_POST['can_edit']) ? 1 : 0;
    $canDelete  = isset($_POST['can_delete']) ? 1 : 0;

    if ($userId || $division || $department) {
        $stmt = $db->prepare("INSERT INTO document_permissions (document_id, user_id, division, department, can_read, can_download, can_edit, can_delete, granted_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$docId, $userId, $division ?: null, $department ?: null, $canRead, $canDownload, $canEdit, $canDelete, $_SESSION['user_id']]);

            $doc = $db->prepare("SELECT title FROM documents_arsip WHERE id = ?");
            $doc->execute([$docId]);
            $docTitle = $doc->fetchColumn();
            logAudit('permission_change', 'documents', $docId, $docTitle, "Permission added for " . ($userId ? "user:{$userId}" : "division:{$division}"));

            // Notify user
            if ($userId) {
                createNotification($userId, $docId, 'permission_granted', 'Akses Dokumen Diberikan', "Anda diberikan akses ke dokumen: {$docTitle}");
            }

            setFlash('success', 'Hak akses berhasil ditambahkan!');
        }
    }

    header('Location: ' . BASE_URL . 'modules/documents/view.php?id=' . $docId);
    exit;
}

// If GET request, show permissions management page (admin only)
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';

if (!canUploadAndManage()) {
    setFlash('danger', 'Akses ditolak!');
    header('Location: ' . BASE_URL . 'modules/dashboard/');
    exit;
}

// Show all document permissions
$permissions = $db->query("
    SELECT dp.*, d.title as doc_title, d.archive_number, u.name as user_name, u2.name as granted_by_name
    FROM document_permissions dp 
    JOIN documents_arsip d ON dp.document_id = d.id 
    LEFT JOIN users u ON dp.user_id = u.id 
    LEFT JOIN users u2 ON dp.granted_by = u2.id 
    ORDER BY dp.created_at DESC 
    LIMIT 100
")->fetchAll();
?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-shield-alt"></i> Hak Akses Dokumen</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <table>
            <thead>
                <tr><th>Dokumen</th><th>User</th><th>Divisi</th><th>Bidang</th><th>Read</th><th>Download</th><th>Edit</th><th>Delete</th></tr>
            </thead>
            <tbody>
            <?php foreach ($permissions as $p): ?>
            <tr>
                <td><a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $p['document_id'] ?>"><?= htmlspecialchars(mb_substr($p['doc_title'], 0, 35)) ?></a></td>
                <td><?= $p['user_name'] ? htmlspecialchars($p['user_name']) : '<em>-</em>' ?></td>
                <td><?= htmlspecialchars($p['department'] ?? '-') ?></td>
                <td><?= htmlspecialchars($p['division'] ?? '-') ?></td>
                <td><?= $p['can_read'] ? '✅' : '❌' ?></td>
                <td><?= $p['can_download'] ? '✅' : '❌' ?></td>
                <td><?= $p['can_edit'] ? '✅' : '❌' ?></td>
                <td><?= $p['can_delete'] ? '✅' : '❌' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>