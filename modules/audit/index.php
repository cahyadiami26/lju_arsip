<?php
// FILE: modules/audit/index.php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';


if (!canUploadAndManage() && !checkSpecialuser()) {
    setFlash('danger', 'Akses ditolak!');
    header('Location: ' . BASE_URL . 'modules/dashboard/');
    exit;
}

$db = getDB();

// Filters
$filterUser = $_GET['user'] ?? '';
$filterAction = $_GET['action_type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($filterUser) { $where[] = "user_id = ?"; $params[] = $filterUser; }
if ($filterAction) { $where[] = "action = ?"; $params[] = $filterAction; }
if ($dateFrom) { $where[] = "created_at >= ?"; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo) { $where[] = "created_at <= ?"; $params[] = $dateTo . ' 23:59:59'; }

$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM audit_trail WHERE {$whereClause}");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

$sql = "SELECT * FROM audit_trail WHERE {$whereClause} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$users = $db->query("SELECT id, name FROM users ORDER BY name")->fetchAll();
?>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
            <div>
                <label style="font-size:12px;">User</label>
                <select name="user" class="form-control">
                    <option value="">Semua</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;">Aksi</label>
                <select name="action_type" class="form-control">
                    <option value="">Semua</option>
                    <?php foreach (['login','logout','view','download','upload','edit','delete','permission_change','version_upload','search'] as $a): ?>
                    <option value="<?= $a ?>" <?= $filterAction == $a ? 'selected' : '' ?>><?= ucfirst($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;">Dari</label>
                <input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?>">
            </div>
            <div>
                <label style="font-size:12px;">Sampai</label>
                <input type="date" name="date_to" class="form-control" value="<?= $dateTo ?>">
            </div>
            <div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="<?= BASE_URL ?>modules/audit/" class="btn btn-outline">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-clipboard-list"></i> Audit Trail (<?= number_format($totalRows) ?> log)</h3>
        <span class="badge badge-info">Catatan tidak dapat diubah (immutable)</span>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Waktu</th>
                        <th>User</th>
                        <th>Aksi</th>
                        <th>Modul</th>
                        <th>Dokumen</th>
                        <th>Detail</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="font-size:11px; color:var(--secondary);">#<?= $log['id'] ?></td>
                    <td style="font-size:12px; white-space:nowrap;"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                    <td><strong><?= htmlspecialchars($log['user_name']) ?></strong></td>
                    <td>
                        <?php
                        $actionColors = [
                            'login' => 'badge-success', 'logout' => 'badge-secondary', 'view' => 'badge-info',
                            'download' => 'badge-primary', 'upload' => 'badge-success', 'edit' => 'badge-warning',
                            'delete' => 'badge-danger', 'permission_change' => 'badge-warning',
                            'version_upload' => 'badge-info', 'search' => 'badge-secondary'
                        ];
                        ?>
                        <span class="badge <?= $actionColors[$log['action']] ?? 'badge-secondary' ?>"><?= $log['action'] ?></span>
                    </td>
                    <td style="font-size:12px;"><?= htmlspecialchars($log['module'] ?? '-') ?></td>
                    <td>
                        <?php if ($log['document_id']): ?>
                        <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $log['document_id'] ?>" style="font-size:12px;">
                            <?= htmlspecialchars(mb_substr($log['document_title'] ?? '', 0, 30)) ?>
                        </a>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td style="font-size:12px; max-width:200px; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                    <td style="font-size:11px; color:var(--secondary);"><?= $log['ip_address'] ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer">
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>">«</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page-3); $i <= min($totalPages, $page+3); $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="<?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>">»</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>