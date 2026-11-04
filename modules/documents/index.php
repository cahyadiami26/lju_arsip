<?php
// FILE: modules/documents/index.php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';

$db = getDB();
$user = currentUser();

// Filters
$filterCategory  = $_GET['category'] ?? '';
$filterDivision  = $_GET['division'] ?? '';
$filterDepartment = $_GET['department'] ?? '';
$filterStatus    = $_GET['status'] ?? '';
$filterFolder    = $_GET['folder'] ?? '';
$search          = trim($_GET['q'] ?? '');
$page            = max(1, (int)($_GET['page'] ?? 1));
$perPage         = 20;
$offset          = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = "(d.title LIKE ? OR d.description LIKE ? OR d.archive_number LIKE ?
                OR d.id IN (SELECT document_id FROM document_metadata WHERE field_value LIKE ?))";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($filterCategory !== '') {
    $where[] = "d.category_id = ?";
    $params[] = $filterCategory;
}
if ($filterDivision !== '') {
    $where[] = "d.division = ?";
    $params[] = $filterDivision;
}
if ($filterDepartment !== '') {
    $where[] = "d.department = ?";
    $params[] = $filterDepartment;
}
if ($filterStatus !== '') {
    $where[] = "d.status = ?";
    $params[] = $filterStatus;
}
if ($filterFolder !== '') {
    $where[] = "d.folder_id = ?";
    $params[] = $filterFolder;
}



// ===== AKSES NON-ADMIN/MANAGEMENT (TERMASUK PERMISSION DEPARTMENT) =====
if (!canUploadAndManage() && !in_array($user['department'] ?? '', specialUsers) ) {
    $where[] = "(
        d.uploaded_by = ?
        OR (d.division = ? AND d.division IS NOT NULL AND d.division != '')
        OR (d.department = ? AND d.department IS NOT NULL AND d.department != '')
        OR d.id IN (
            SELECT dp.document_id
            FROM document_permissions dp
            WHERE
                dp.user_id = ?
                OR (
                    dp.user_id IS NULL
                    AND dp.division = ?
                    AND (dp.department IS NULL OR dp.department = '' OR dp.department = ?)
                )
                OR (
                    dp.user_id IS NULL
                    AND dp.department = ?
                    AND (dp.division IS NULL OR dp.division = '' OR dp.division = ?)
                )
        )
    )";
    // Parameter berurutan sesuai placeholder di atas
    $params[] = $user['id'];              // uploaded_by
    $params[] = $user['division'];        // d.division
    $params[] = $user['department'];      // d.department
    $params[] = $user['id'];              // dp.user_id
    $params[] = $user['division'];        // dp.division
    $params[] = $user['department'];      // dp.department (saat match division)
    $params[] = $user['department'];      // dp.department
    $params[] = $user['division'];        // dp.division (saat match department)
}

$whereClause = implode(' AND ', $where);

// Count
$countSql = "SELECT COUNT(*) FROM documents_arsip d WHERE {$whereClause}";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalRows = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Data
$sql = "
    SELECT d.*, dc.name as category_name, u.name as uploader_name, f.name as folder_name,
           DATEDIFF(d.expiry_date, CURDATE()) as days_left
    FROM documents_arsip d
    LEFT JOIN document_categories dc ON d.category_id = dc.id
    LEFT JOIN users u ON d.uploaded_by = u.id
    LEFT JOIN folders f ON d.folder_id = f.id
    WHERE {$whereClause}
    ORDER BY d.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

// Get document tags
foreach ($documents as &$doc) {
    $stmt = $db->prepare("SELECT t.* FROM tags t JOIN document_tags dt ON t.id = dt.tag_id WHERE dt.document_id = ?");
    $stmt->execute([$doc['id']]);
    $doc['tags'] = $stmt->fetchAll();
}
unset($doc);

// Get categories & folders for filter
$categories = $db->query("SELECT * FROM document_categories WHERE is_active=1 ORDER BY name")->fetchAll();
$folders = $db->query("SELECT * FROM folders ORDER BY division, name")->fetchAll();
$divisions = ['Bisnis','Keuangan','Sekretariat','Umum','Pengadaan','Keamanan','Infokom','SDM','Direksi','IT','Legal'];
?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
            <div style="flex:1; min-width:200px;">
                <label style="font-size:12px; font-weight:500;">Pencarian</label>
                <input type="text" name="q" class="form-control" placeholder="Cari judul, deskripsi, nomor arsip..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div>
                <label style="font-size:12px; font-weight:500;">Kategori</label>
                <select name="category" class="form-control">
                    <option value="">Semua</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px; font-weight:500;">Divisi</label>
                <select name="department" class="form-control">
                    <option value="">Semua</option>
                    <?php foreach (DEPARTMENTS as $dept): ?>
                    <option value="<?= $dept ?>" <?= $filterDepartment === $dept ? 'selected' : '' ?>><?= $dept ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px; font-weight:500;">Bidang</label>
                <select name="division" class="form-control">
                    <option value="">Semua</option>
                    <?php foreach ($divisions as $div): ?>
                    <option value="<?= $div ?>" <?= $filterDivision == $div ? 'selected' : '' ?>><?= $div ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px; font-weight:500;">Status</label>
                <select name="status" class="form-control">
                    <option value="">Semua</option>
                    <option value="active" <?= $filterStatus == 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="expired" <?= $filterStatus == 'expired' ? 'selected' : '' ?>>Expired</option>
                    <option value="archived" <?= $filterStatus == 'archived' ? 'selected' : '' ?>>Archived</option>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="<?= BASE_URL ?>modules/documents/" class="btn btn-outline">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Document Table -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-file-alt"></i> Daftar Dokumen (<?= number_format($totalRows) ?>)</h3>
        <?php if (canUploadAndManage()): ?>
        <a href="<?= BASE_URL ?>modules/documents/upload.php" class="btn btn-primary btn-sm">
            <i class="fas fa-plus"></i> Upload Baru
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width:40px;"><input type="checkbox" id="select-all"></th>
                        <th>No. Arsip</th>
                        <th>Judul</th>
                        <th>Kategori</th>
                        <th>Divisi</th>
                        <th>Tags</th>
                        <th>Versi</th>
                        <th>Status</th>
                        <th>Expired</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($documents)): ?>
                    <tr><td colspan="10" class="text-center text-muted" style="padding:40px;">
                        <i class="fas fa-folder-open" style="font-size:48px; display:block; margin-bottom:10px;"></i>
                        Tidak ada dokumen ditemukan
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($documents as $doc): ?>
                    <tr>
                        <td><input type="checkbox" class="row-check" value="<?= $doc['id'] ?>"></td>
                        <td><code style="font-size:11px;"><?= htmlspecialchars($doc['archive_number']) ?></code></td>
                        <td>
                            <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $doc['id'] ?>" style="font-weight:500;">
                                <?= htmlspecialchars(mb_substr($doc['title'], 0, 45)) ?><?= mb_strlen($doc['title']) > 45 ? '...' : '' ?>
                            </a>
                            <?php if ($doc['is_confidential']): ?>
                            <i class="fas fa-lock" style="color:var(--danger); font-size:11px;" title="Rahasia"></i>
                            <?php endif; ?>
                            <div style="font-size:11px; color:var(--secondary);"><?= htmlspecialchars($doc['uploader_name']) ?> • <?= date('d/m/Y', strtotime($doc['created_at'])) ?></div>
                        </td>
                        <td><span class="badge badge-info"><?= htmlspecialchars($doc['category_name'] ?? '-') ?></span></td>
                        <td style="font-size:12px;"><?= htmlspecialchars($doc['division'] ?? '-') ?></td>
                        <td>
                            <div class="tag-list">
                            <?php foreach ($doc['tags'] as $tag): ?>
                            <span class="badge-tag" style="background:<?= $tag['color'] ?>20; color:<?= $tag['color'] ?>; font-size:10px;">
                                <?= htmlspecialchars($tag['name']) ?>
                            </span>
                            <?php endforeach; ?>
                            </div>
                        </td>
                        <td><span class="badge badge-secondary">v<?= $doc['current_version'] ?>.0</span></td>
                        <td>
                            <?php
                            $badges = ['active'=>'badge-success','expired'=>'badge-danger','inactive'=>'badge-secondary','archived'=>'badge-warning'];
                            ?>
                            <span class="badge <?= $badges[$doc['status']] ?? 'badge-secondary' ?>"><?= ucfirst($doc['status']) ?></span>
                        </td>
                        <td>
                            <?php if ($doc['expiry_date']): ?>
                                <?php if ($doc['days_left'] !== null && $doc['days_left'] <= 0): ?>
                                <span class="badge badge-danger">Expired</span>
                                <?php elseif ($doc['days_left'] <= 30): ?>
                                <span class="badge badge-warning"><?= $doc['days_left'] ?>d</span>
                                <?php else: ?>
                                <span style="font-size:11px; color:var(--secondary);"><?= date('d/m/Y', strtotime($doc['expiry_date'])) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                            <span style="font-size:11px; color:#ccc;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group">
                                <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $doc['id'] ?>" class="btn btn-info btn-sm" title="Lihat"><i class="fas fa-eye"></i></a>
                                <!-- Tombol Download/View Label -->
                                <a href="<?= BASE_URL ?>uploads/images/labels/label_<?= $doc['id'] ?>.png" target="_blank" class="btn btn-secondary btn-sm" title="Lihat Label QR"><i class="fas fa-qrcode"></i></a>
                                <a href="<?= BASE_URL ?>modules/documents/edit.php?id=<?= $doc['id'] ?>" class="btn btn-warning btn-sm" title="Edit"><i class="fas fa-edit"></i></a>
                                <?php if (canUploadAndManage() || $doc['uploaded_by'] == $user['id']): ?>
                                <a href="<?= BASE_URL ?>modules/documents/delete.php?id=<?= $doc['id'] ?>" class="btn btn-danger btn-sm btn-delete" title="Hapus"><i class="fas fa-trash"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="card-footer">
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>">« Prev</a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="<?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>">Next »</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>