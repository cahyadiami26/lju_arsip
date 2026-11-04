<?php
// FILE: modules/documents/view.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    setFlash('danger', 'Dokumen tidak ditemukan!');
    header('Location: ' . BASE_URL . 'modules/documents/');
    exit;
}

// Check permission
if ((!checkDocPermission($id, 'can_read'))) {
    setFlash('danger', 'Anda tidak memiliki akses ke dokumen ini!');
    header('Location: ' . BASE_URL . 'modules/documents/');
    exit;
}

// Get document
$stmt = $db->prepare("SELECT d.*, dc.name as category_name, dc.code as category_code, u.name as uploader_name, u.division as uploader_division, f.name as folder_name FROM documents_arsip d LEFT JOIN document_categories dc ON d.category_id = dc.id LEFT JOIN users u ON d.uploaded_by = u.id LEFT JOIN folders f ON d.folder_id = f.id WHERE d.id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

// ============================================
// PROSES SET LOKASI FISIK DARI HALAMAN VIEW
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_location') {
    if (!canUploadAndManage() && !in_array($user['department'] ?? '', specialUsers)) {
        setFlash('danger', 'Akses ditolak!');
        header('Location: ' . BASE_URL . 'modules/documents/view.php?id=' . $id);
        exit;
    }

    $locId = (int)($_POST['location_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if (!$locId) {
        setFlash('danger', 'Pilih lokasi terlebih dahulu!');
        header('Location: ' . BASE_URL . 'modules/documents/view.php?id=' . $id);
        exit;
    }

    // Hapus lokasi lama (jika ada), lalu insert yang baru
    $db->prepare("DELETE FROM document_locations WHERE document_id = ?")->execute([$id]);
    
    $stmt = $db->prepare("INSERT INTO document_locations (document_id, location_id, notes, placed_by) VALUES (?, ?, ?, ?)");
    $stmt->execute([$id, $locId, $notes ?: null, $_SESSION['user_id']]);

    logAudit('location_change', 'documents', $id, $doc['title'], "Lokasi fisik ditetapkan (location_id: {$locId})");
    setFlash('success', 'Lokasi fisik dokumen berhasil ditetapkan!');
    header('Location: ' . BASE_URL . 'modules/documents/view.php?id=' . $id);
    exit;
}



if (!$doc) {
    setFlash('danger', 'Dokumen tidak ditemukan!');
    header('Location: ' . BASE_URL . 'modules/documents/');
    exit;
}

// Log view
logAudit('view', 'documents', $id, $doc['title'], 'Viewed document');

// Get versions
$versions = $db->prepare("SELECT dv.*, u.name as uploader_name FROM document_versions dv LEFT JOIN users u ON dv.uploaded_by = u.id WHERE dv.document_id = ? ORDER BY dv.id DESC");
$versions->execute([$id]);
$versions = $versions->fetchAll();

// Get current version (latest)
$currentVersion = $versions[0] ?? null;

// Get tags
$tags = $db->prepare("SELECT t.* FROM tags t JOIN document_tags dt ON t.id = dt.tag_id WHERE dt.document_id = ?");
$tags->execute([$id]);
$docTags = $tags->fetchAll();

// Get metadata
$metadata = $db->prepare("SELECT dm.*, cmf.field_label, cmf.field_type FROM document_metadata dm JOIN category_metadata_fields cmf ON dm.field_id = cmf.id WHERE dm.document_id = ? ORDER BY cmf.sort_order");
$metadata->execute([$id]);
$docMeta = $metadata->fetchAll();

// Get permissions
$permissions = $db->prepare("SELECT dp.*, u.name as user_name FROM document_permissions dp LEFT JOIN users u ON dp.user_id = u.id WHERE dp.document_id = ?");
$permissions->execute([$id]);
$docPerms = $permissions->fetchAll();

// Get audit trail for this doc
$auditTrail = $db->prepare("SELECT * FROM audit_trail WHERE document_id = ? ORDER BY created_at DESC LIMIT 20");
$auditTrail->execute([$id]);
$docAudit = $auditTrail->fetchAll();

$canDownload = checkDocPermission($id, 'can_download') || isAdmin() || in_array($user['department'] ?? '', specialUsers);
$canEdit = checkDocPermission($id, 'can_edit') || isAdmin();
$canDelete = checkDocPermission($id, 'can_delete') || isAdmin();
?>

<?php
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<!-- Document Header -->
<div class="card mb-3">
    <div class="card-body">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
            <div>
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                    <h2 style="font-size:20px; font-weight:700;"><?= htmlspecialchars($doc['title']) ?></h2>
                    <?php if ($doc['is_confidential']): ?>
                    <span class="badge badge-danger"><i class="fas fa-lock"></i> Rahasia</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex; gap:16px; flex-wrap:wrap; font-size:13px; color:var(--secondary);">
                    <span><i class="fas fa-hashtag"></i> <?= htmlspecialchars($doc['archive_number']) ?></span>
                    <span><i class="fas fa-layer-group"></i> <?= htmlspecialchars($doc['category_name'] ?? '-') ?></span>
                    <span><i class="fas fa-building"></i> <?= htmlspecialchars($doc['division'] ?? '-') ?></span>
                    <span><i class="fas fa-folder"></i> <?= htmlspecialchars($doc['folder_name'] ?? 'Tanpa Folder') ?></span>
                    <span><i class="fas fa-user"></i> <?= htmlspecialchars($doc['uploader_name']) ?></span>
                    <span><i class="fas fa-calendar"></i> <?= date('d M Y H:i', strtotime($doc['created_at'])) ?></span>
                </div>
                <div style="margin-top:8px;" class="tag-list">
                    <?php foreach ($docTags as $tag): ?>
                    <span class="badge-tag" style="background:<?= $tag['color'] ?>20; color:<?= $tag['color'] ?>;"><?= htmlspecialchars($tag['name']) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="btn-group">
                <?php
                $badges = ['active'=>'badge-success','expired'=>'badge-danger','inactive'=>'badge-secondary','archived'=>'badge-warning'];
                ?>
                <span class="badge <?= $badges[$doc['status']] ?? '' ?>" style="font-size:14px; padding:6px 14px;">
                    <?= ucfirst($doc['status']) ?>
                </span>
                <?php if ($canEdit): ?>
                <a href="<?= BASE_URL ?>modules/documents/edit.php?id=<?= $doc['id'] ?>" class="btn btn-warning"><i class="fas fa-edit"></i> Edit</a>
                <?php endif; ?>
                <?php if ($canDownload && $currentVersion): ?>
                <a href="<?= BASE_URL ?>modules/documents/download.php?id=<?= $currentVersion['id'] ?>" class="btn btn-success"><i class="fas fa-download"></i> Download</a>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                <a href="<?= BASE_URL ?>modules/documents/delete.php?id=<?= $doc['id'] ?>" class="btn btn-danger btn-delete"><i class="fas fa-trash"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
// Ambil lokasi & peminjaman
$loanHistory = $db->prepare("
    SELECT l.*, u1.name as borrower_name, u2.name as approved_by_name, u3.name as returned_to_name 
    FROM document_loans l 
    LEFT JOIN users u1 ON l.borrower_id = u1.id 
    LEFT JOIN users u2 ON l.approved_by = u2.id 
    LEFT JOIN users u3 ON l.returned_to = u3.id 
    WHERE l.document_id = ? 
    ORDER BY l.created_at DESC
");
$loanHistory->execute([$id]);
$loanHistory = $loanHistory->fetchAll();

$activeLoan = null;
foreach ($loanHistory as $lh) {
    if (in_array($lh['status'], ['borrowed','overdue'])) {
        $activeLoan = $lh;
        break;
    }
}
?>

<!-- Tabs -->
<div class="card">
    <div class="card-body" style="padding-bottom:0;">
        <div class="tabs">
            <span class="tab-link active" data-tab="tab-viewer">📄 Viewer</span>
            <span class="tab-link" data-tab="tab-detail">ℹ️ Deskripsi</span>
            <span class="tab-link" data-tab="tab-versions">🔄 Versi (<?= count($versions) ?>)</span>
            
            <?php if (canUploadAndManage()): ?>
            <span class="tab-link" data-tab="tab-permissions">🔒 Hak Akses</span>
            <span class="tab-link" data-tab="tab-audit">📋 Audit Log</span>
            <?php endif; ?>
            <span class="tab-link" data-tab="tab-location">📍 Lokasi Fisik</span>
            <span class="tab-link" data-tab="tab-loans">🤝 Peminjaman</span>
            <span class="tab-link" data-tab="tab-label">🏷️ Label QR</span> <!-- TAMBAHAN TAB BARU -->
        </div>
    </div>

    <!-- Tab: Viewer -->
    <div class="tab-content active" id="tab-viewer">
        <div class="card-body" style="padding:0;">
            <?php if ($currentVersion): ?>
            <?php
            $ext = strtolower($currentVersion['file_type']);
            $filePath = $currentVersion['file_path'];
            $viewerUrl = BASE_URL . 'modules/documents/viewer.php?id=' . $currentVersion['id'];
            ?>
            <div class="viewer-toolbar">
                <span><i class="fas fa-file"></i> <?= htmlspecialchars($currentVersion['file_name']) ?> (<?= formatFileSize($currentVersion['file_size']) ?>)</span>
                <div>
                    <span class="badge badge-secondary">v<?= $currentVersion['version_number'] ?></span>
                    <?php if ($canDownload): ?>
                    <a href="<?= BASE_URL ?>modules/documents/download.php?id=<?= $currentVersion['id'] ?>" class="btn btn-sm btn-success" style="color:#fff;"><i class="fas fa-download"></i> Download</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (in_array($ext, ['pdf'])): ?>
            <iframe src="<?= $viewerUrl ?>" class="doc-viewer" style="border-radius:0;"></iframe>
            <?php elseif (in_array($ext, ['jpg','jpeg','png','gif'])): ?>
            <div style="padding:20px; text-align:center; background:#f8f8f8;">
                <img src="<?= $viewerUrl ?>" style="max-width:100%; max-height:70vh; border-radius:8px; box-shadow:var(--shadow-md);">
            </div>
            <?php elseif (in_array($ext, ['doc','docx','xls','xlsx'])): ?>
            <div style="padding:40px; text-align:center;">
                <i class="fas fa-file-<?= in_array($ext, ['doc','docx']) ? 'word' : 'excel' ?>" style="font-size:64px; color:<?= in_array($ext, ['doc','docx']) ? '#2563eb' : '#059669' ?>; margin-bottom:16px; display:block;"></i>
                <h4>Preview tidak tersedia untuk file <?= strtoupper($ext) ?></h4>
                <p class="text-muted">Silakan download file untuk melihat isinya</p>
                <?php if ($canDownload): ?>
                <a href="<?= BASE_URL ?>modules/documents/download.php?id=<?= $currentVersion['id'] ?>" class="btn btn-primary btn-lg mt-3"><i class="fas fa-download"></i> Download File</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div style="padding:40px; text-align:center;">
                <p class="text-muted">Tidak ada file yang tersedia</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tab: Detail -->
    <div class="tab-content" id="tab-detail">
        <div class="card-body">
            <?php if ($doc['description']): ?>
            <div class="mb-3">
                <h4 style="font-size:14px; margin-bottom:6px;">Deskripsi</h4>
                <p><?= nl2br(htmlspecialchars($doc['description'])) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($doc['expiry_date']): ?>
            <div class="mb-3">
                <h4 style="font-size:14px; margin-bottom:6px;">Masa Berlaku</h4>
                <?php
                $daysLeft = ceil((strtotime($doc['expiry_date']) - time()) / 86400);
                ?>
                <p>
                    <i class="fas fa-calendar-times"></i> <?= date('d M Y', strtotime($doc['expiry_date'])) ?>
                    <?php if ($daysLeft <= 0): ?>
                    <span class="badge badge-danger">Expired <?= abs($daysLeft) ?> hari lalu</span>
                    <?php elseif ($daysLeft <= 30): ?>
                    <span class="badge badge-warning"><?= $daysLeft ?> hari lagi</span>
                    <?php else: ?>
                    <span class="badge badge-success"><?= $daysLeft ?> hari lagi</span>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <?php if (!empty($docMeta)): ?>
            <div class="mb-3">
                <h4 style="font-size:14px; margin-bottom:10px;">Metadata / Informasi Tambahan</h4>
                <table>
                    <?php foreach ($docMeta as $m): ?>
                    <tr>
                        <td style="width:200px; font-weight:500;"><?= htmlspecialchars($m['field_label']) ?></td>
                        <td>
                            <?php if ($m['field_type'] == 'date' && $m['field_value']): ?>
                            <?= date('d M Y', strtotime($m['field_value'])) ?>
                            <?php elseif ($m['field_type'] == 'number' && $m['field_value']): ?>
                            <?= number_format((float)$m['field_value'], 0, ',', '.') ?>
                            <?php else: ?>
                            <?= nl2br(htmlspecialchars($m['field_value'])) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tab: Versions -->
    <div class="tab-content" id="tab-versions">
        <div class="card-body">
            <?php if ($canEdit): ?>
            <div class="mb-3">
                <button class="btn btn-primary" data-modal="#modal-new-version"><i class="fas fa-plus"></i> Upload Versi Baru</button>
            </div>
            <?php endif; ?>

            <div class="version-timeline">
                <?php foreach ($versions as $i => $ver): ?>
                <div class="version-item <?= $i === 0 ? 'current' : '' ?>">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <span class="badge <?= $i === 0 ? 'badge-success' : 'badge-secondary' ?>">v<?= $ver['version_number'] ?></span>
                            <?php if ($i === 0): ?>
                            <span class="badge badge-primary">Current</span>
                            <?php endif; ?>
                            <span style="font-size:12px; color:var(--secondary); margin-left:8px;">
                                <?= htmlspecialchars($ver['file_name']) ?> (<?= formatFileSize($ver['file_size']) ?>)
                            </span>
                        </div>
                        <div class="btn-group">
                            <?php if (in_array($ver['file_type'], ['pdf','jpg','jpeg','png','gif'])): ?>
                            <a href="<?= BASE_URL ?>modules/documents/viewer.php?id=<?= $ver['id'] ?>" target="_blank" class="btn btn-info btn-sm"><i class="fas fa-eye"></i></a>
                            <?php endif; ?>
                            <?php if ($canDownload): ?>
                            <a href="<?= BASE_URL ?>modules/documents/download.php?id=<?= $ver['id'] ?>" class="btn btn-success btn-sm"><i class="fas fa-download"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="margin-top:8px; font-size:12px; color:var(--secondary);">
                        <i class="fas fa-user"></i> <?= htmlspecialchars($ver['uploader_name']) ?> •
                        <i class="fas fa-clock"></i> <?= date('d M Y H:i', strtotime($ver['created_at'])) ?>
                    </div>
                    <?php if ($ver['change_notes']): ?>
                    <div style="margin-top:6px; font-size:13px; padding:8px 12px; background:var(--light); border-radius:6px;">
                        📝 <?= htmlspecialchars($ver['change_notes']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if (canUploadAndManage()): ?>
    <!-- Tab: Permissions -->
    <div class="tab-content" id="tab-permissions">
        <div class="card-body">
            <div class="mb-3">
                <button class="btn btn-primary" data-modal="#modal-permission"><i class="fas fa-plus"></i> Tambah Hak Akses</button>
            </div>
            <table>
                <thead>
                    <tr><th>User/Dept/Divisi</th><th>Read</th><th>Download</th><th>Edit</th><th>Delete</th><th>Aksi</th></tr>
                </thead>
                <tbody>
                <?php if (empty($docPerms)): ?>
                <tr><td colspan="6" class="text-center text-muted">Belum ada hak akses spesifik</td></tr>
                <?php endif; ?>
                <?php foreach ($docPerms as $perm): ?>
                <tr>
                    <td>
                        <?php if ($perm['user_name']): ?>
                            <?= htmlspecialchars($perm['user_name']) ?>
                        <?php else: ?>
                            <em>
                                <?= $perm['department'] ? 'Dept: ' . $perm['department'] : '' ?>
                                <?= ($perm['department'] && $perm['division']) ? ' / ' : '' ?>
                                <?= $perm['division'] ? 'Div: ' . $perm['division'] : '' ?>
                            </em>
                        <?php endif; ?>
                    </td>
                    <td><?= $perm['can_read'] ? '✅' : '❌' ?></td>
                    <td><?= $perm['can_download'] ? '✅' : '❌' ?></td>
                    <td><?= $perm['can_edit'] ? '✅' : '❌' ?></td>
                    <td><?= $perm['can_delete'] ? '✅' : '❌' ?></td>
                    <td>
                        <a href="?id=<?= $doc['id'] ?>&del_perm=<?= $perm['id'] ?>" class="btn btn-danger btn-sm btn-delete"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tab: Audit -->
    <div class="tab-content" id="tab-audit">
        <div class="card-body" style="padding:0;">
            <table>
                <thead>
                    <tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Detail</th><th>IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($docAudit as $log): ?>
                <tr>
                    <td style="font-size:12px;"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                    <td><?= htmlspecialchars($log['user_name']) ?></td>
                    <td><span class="badge badge-secondary"><?= $log['action'] ?></span></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                    <td style="font-size:11px; color:var(--secondary);"><?= $log['ip_address'] ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
        <!-- Tab: Label Fisik QR Code -->
    <div class="tab-content" id="tab-label">
        <div class="card-body" style="text-align: center;">
            <h4 style="margin-bottom: 20px; color: var(--secondary);">Label Arsip Fisik</h4>
            <?php 
            $labelPath = 'uploads/images/labels/label_' . $doc['id'] . '.png';
            if (file_exists(__DIR__ . '/../../' . $labelPath)): 
            ?>
                <div style="background: #f8fafc; padding: 30px; border-radius: 12px; border: 2px dashed var(--border); display: inline-block;">
                    <img src="<?= BASE_URL . $labelPath ?>" alt="Label Dokumen" style="max-width: 100%; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
                    
                    <div style="margin-top: 20px; display:flex; gap:10px; justify-content:center;">
                        <a href="<?= BASE_URL . $labelPath ?>" target="_blank" class="btn btn-primary"><i class="fas fa-print"></i> Cetak Label</a>
                        <a href="<?= BASE_URL . $labelPath ?>" download="Label_<?= str_replace('/', '_', $doc['archive_number']) ?>.png" class="btn btn-success"><i class="fas fa-download"></i> Download</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning" style="display:inline-block;">
                    <i class="fas fa-exclamation-triangle"></i> Label belum digenerate (Dokumen lama). Silakan upload ulang atau edit dokumen.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // Ambil lokasi fisik dokumen (jalankan sebelum tab HTML)
    $location = $db->prepare("
        SELECT pl.*, dl.notes as placement_notes, dl.placed_at, u.name as placed_by_name 
        FROM document_locations dl 
        JOIN physical_locations pl ON dl.location_id = pl.id 
        LEFT JOIN users u ON dl.placed_by = u.id 
        WHERE dl.document_id = ?
    ");
    $location->execute([$id]);
    $location = $location->fetch();

    // Ambil semua lokasi aktif untuk form dropdown
    $allLocations = $db->query("
        SELECT pl.*, 
            (SELECT COUNT(*) FROM document_locations WHERE location_id = pl.id) as doc_count
        FROM physical_locations pl 
        WHERE pl.is_active = 1 
        ORDER BY pl.room, pl.cabinet, pl.box
    ")->fetchAll();
    ?>

    <!-- Tab: Lokasi Fisik -->
    <div class="tab-content" id="tab-location">
        <div class="card-body">

            <?php if ($location): ?>
            <!-- ===== SUDAH ADA LOKASI ===== -->
            <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:20px;">
                <div style="background:var(--light); padding:20px; border-radius:var(--radius); text-align:center; border:1px solid var(--border);">
                    <i class="fas fa-door-open" style="font-size:32px; color:var(--primary); margin-bottom:10px; display:block;"></i>
                    <div style="font-size:12px; color:var(--secondary); text-transform:uppercase;">Ruang</div>
                    <h4 style="margin-top:4px;"><?= htmlspecialchars($location['room']) ?></h4>
                </div>
                <div style="background:var(--light); padding:20px; border-radius:var(--radius); text-align:center; border:1px solid var(--border);">
                    <i class="fas fa-archive" style="font-size:32px; color:var(--warning); margin-bottom:10px; display:block;"></i>
                    <div style="font-size:12px; color:var(--secondary); text-transform:uppercase;">Rak / Kabinet</div>
                    <h4 style="margin-top:4px;"><?= htmlspecialchars($location['cabinet']) ?></h4>
                </div>
                <div style="background:var(--light); padding:20px; border-radius:var(--radius); text-align:center; border:1px solid var(--border);">
                    <i class="fas fa-box" style="font-size:32px; color:var(--success); margin-bottom:10px; display:block;"></i>
                    <div style="font-size:12px; color:var(--secondary); text-transform:uppercase;">Box / Folder</div>
                    <h4 style="margin-top:4px;"><?= htmlspecialchars($location['box']) ?></h4>
                </div>
            </div>

            <?php if (!empty($location['placement_notes'])): ?>
            <div class="alert alert-info">
                <i class="fas fa-sticky-note"></i>
                <strong>Catatan:</strong> <?= htmlspecialchars($location['placement_notes']) ?>
            </div>
            <?php endif; ?>

            <p style="font-size:12px; color:var(--secondary);">
                <i class="fas fa-user"></i> Ditempatkan oleh 
                <strong><?= htmlspecialchars($location['placed_by_name'] ?? '-') ?></strong>
                pada <?= date('d M Y H:i', strtotime($location['placed_at'])) ?>
            </p>

            <?php if (canUploadAndManage()): ?>
            <hr style="margin:20px 0; border:none; border-top:1px solid var(--border);">
            <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('form-relocate').style.display = document.getElementById('form-relocate').style.display === 'none' ? 'block' : 'none';">
                <i class="fas fa-exchange-alt"></i> Pindahkan Lokasi
            </button>

            <!-- Form pindah lokasi (tersembunyi by default) -->
            <div id="form-relocate" style="display:none; margin-top:16px; padding:20px; background:var(--light); border-radius:var(--radius); border:1px solid var(--border);">
                <h4 style="font-size:14px; margin-bottom:12px;"><i class="fas fa-map-pin"></i> Pindahkan ke Lokasi Baru</h4>
                <form method="POST">
                    <input type="hidden" name="action" value="set_location">
                    <div class="form-group">
                        <label class="required">Lokasi Tujuan</label>
                        <select name="location_id" class="form-control" required>
                            <option value="">-- Pilih Lokasi --</option>
                            <?php foreach ($allLocations as $loc): ?>
                            <option value="<?= $loc['id'] ?>" <?= $loc['id'] == $location['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($loc['room']) ?> / <?= htmlspecialchars($loc['cabinet']) ?> / <?= htmlspecialchars($loc['box']) ?>
                                (<?= $loc['doc_count'] ?>/<?= $loc['capacity'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Catatan</label>
                        <input type="text" name="notes" class="form-control" value="<?= htmlspecialchars($location['placement_notes'] ?? '') ?>" placeholder="Contoh: Halaman 1-50, Laci atas, dll.">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Lokasi Baru</button>
                </form>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- ===== BELUM ADA LOKASI → LANGSUNG TAMPILKAN FORM ===== -->
            <div style="text-align:center; margin-bottom:24px;">
                <i class="fas fa-map-marker-alt" style="font-size:48px; color:#cbd5e1; display:block; margin-bottom:12px;"></i>
                <h4 style="color:var(--secondary);">Lokasi Fisik Belum Ditetapkan</h4>
                <p style="color:var(--secondary); font-size:13px;">Dokumen digital ini belum direlasikan ke penyimpanan fisik.</p>
            </div>

            <?php if (canUploadAndManage()): ?>
            <div style="max-width:520px; margin:0 auto; padding:24px; background:var(--light); border-radius:var(--radius-lg); border:1px solid var(--border);">
                <h4 style="font-size:15px; margin-bottom:16px;"><i class="fas fa-map-pin"></i> Tetapkan Lokasi Fisik</h4>
                <form method="POST">
                    <input type="hidden" name="action" value="set_location">

                    <div class="form-group">
                        <label class="required">Pilih Lokasi (Ruang / Rak / Box)</label>
                        <select name="location_id" class="form-control" required>
                            <option value="">-- Pilih Lokasi --</option>
                            <?php if (empty($allLocations)): ?>
                            <option value="" disabled>Belum ada lokasi. Buat dulu di menu Lokasi Fisik.</option>
                            <?php else: ?>
                            <?php
                            $currentRoom = '';
                            foreach ($allLocations as $loc):
                                // Group by room (optgroup)
                                if ($loc['room'] !== $currentRoom):
                                    if ($currentRoom !== '') echo '</optgroup>';
                                    $currentRoom = $loc['room'];
                                    echo '<optgroup label="' . htmlspecialchars($currentRoom) . '">';
                                endif;
                            ?>
                            <option value="<?= $loc['id'] ?>">
                                <?= htmlspecialchars($loc['cabinet']) ?> / <?= htmlspecialchars($loc['box']) ?>
                                — isi: <?= $loc['doc_count'] ?>/<?= $loc['capacity'] ?>
                                <?= $loc['description'] ? ' (' . htmlspecialchars($loc['description']) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                            <?php if ($currentRoom !== '') echo '</optgroup>'; ?>
                            <?php endif; ?>
                        </select>
                        <div class="form-text">
                            Format: <strong>Rak/Kabinet / Box/Folder</strong>. 
                            <a href="<?= BASE_URL ?>modules/locations/" target="_blank">Kelola daftar lokasi →</a>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Catatan Penempatan</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Contoh: Halaman 1–50, Laci atas, Map warna biru, dll."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg" style="width:100%; justify-content:center;">
                        <i class="fas fa-map-pin"></i> Tetapkan Lokasi
                    </button>
                </form>
            </div>
            <?php else: ?>
            <div class="alert alert-warning" style="max-width:480px; margin:0 auto;">
                <i class="fas fa-lock"></i>
                Hanya Departemen Sekretariat yang dapat menetapkan lokasi fisik dokumen.
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>

    <!-- Tab: Peminjaman -->
    <div class="tab-content" id="tab-loans">
        <div class="card-body">
            <?php if ($activeLoan): ?>
            <div class="alert alert-warning">
                <i class="fas fa-hand-holding"></i>
                <strong>Sedang Dipinjam</strong> oleh <?= htmlspecialchars($activeLoan['borrower_name']) ?> 
                sejak <?= date('d/m/Y', strtotime($activeLoan['loan_date'])) ?>, 
                batas kembali: <?= date('d/m/Y', strtotime($activeLoan['expected_return_date'])) ?>
            </div>
            <?php endif; ?>

            <?php if (canUploadAndManage() && !$activeLoan): ?>
            <div class="mb-3">
                <a href="<?= BASE_URL ?>modules/loans/" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Catat Peminjaman Baru
                </a>
            </div>
            <?php endif; ?>

            <h4 style="margin-bottom:12px; font-size:14px;">Riwayat Peminjaman</h4>
            <?php if (empty($loanHistory)): ?>
            <p class="text-muted text-center" style="padding:20px;">Belum ada riwayat peminjaman.</p>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Peminjam</th>
                        <th>Keperluan</th>
                        <th>Tgl Pinjam</th>
                        <th>Batas Kembali</th>
                        <th>Dikembalikan</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($loanHistory as $lh): ?>
                <tr>
                    <td><?= htmlspecialchars($lh['borrower_name']) ?></td>
                    <td style="font-size:12px; max-width:250px;"><?= htmlspecialchars($lh['purpose']) ?></td>
                    <td style="font-size:12px;"><?= date('d/m/Y', strtotime($lh['loan_date'])) ?></td>
                    <td style="font-size:12px;"><?= date('d/m/Y', strtotime($lh['expected_return_date'])) ?></td>
                    <td style="font-size:12px;"><?= $lh['actual_return_date'] ? date('d/m/Y', strtotime($lh['actual_return_date'])) : '-' ?></td>
                    <td>
                        <?php
                        $badges = ['borrowed' => 'badge-warning', 'returned' => 'badge-success', 'overdue' => 'badge-danger', 'lost' => 'badge-danger'];
                        $labels = ['borrowed' => 'Dipinjam', 'returned' => 'Kembali', 'overdue' => 'Terlambat', 'lost' => 'Hilang'];
                        ?>
                        <span class="badge <?= $badges[$lh['status']] ?>"><?= $labels[$lh['status']] ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Modal: New Version -->
<div class="modal-overlay" id="modal-new-version">
    <div class="modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3>Upload Versi Baru</h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" action="<?= BASE_URL ?>modules/documents/versions.php" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="document_id" value="<?= $doc['id'] ?>">
                <div class="form-group">
                    <label class="required">File Dokumen</label>
                    <input type="file" name="document" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="required">Catatan Perubahan</label>
                    <textarea name="change_notes" class="form-control" required placeholder="Jelaskan perubahan pada versi ini..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Upload</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Permission -->
<?php if (canUploadAndManage()): ?>
<div class="modal-overlay" id="modal-permission">
    <div class="modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3>Tambah Hak Akses</h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" action="<?= BASE_URL ?>modules/permissions/index.php">
            <div class="modal-body">
                <input type="hidden" name="document_id" value="<?= $doc['id'] ?>">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label>User Spesifik (Opsional)</label>
                    <select name="user_id" class="form-control">
                        <option value="">-- Pilih User --</option>
                        <?php
                        $users = $db->query("SELECT id, name, division, department FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
                        foreach ($users as $u):
                        ?>
                        <option value="<?= $u['id'] ?>">
                            <?= htmlspecialchars($u['name']) ?> (<?= $u['department'] ?> / <?= $u['division'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Atau Berdasarkan Divisi</label>
                    <select name="department" class="form-control">
                        <option value="">-- Semua Divisi --</option>
                        <?php foreach (DEPARTMENTS as $dept): ?>
                        <option value="<?= $dept ?>"><?= $dept ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Atau Berdasarkan Bidang</label>
                    <select name="division" class="form-control">
                        <option value="">-- Semua Bidang --</option>
                        <?php foreach (DIVISIONS as $div): ?>
                        <option value="<?= $div ?>"><?= $div ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Hak Akses</label>
                    <div class="form-check"><input type="checkbox" name="can_read" value="1" checked> Read (Lihat)</div>
                    <div class="form-check"><input type="checkbox" name="can_download" value="1"> Download</div>
                    <div class="form-check"><input type="checkbox" name="can_edit" value="1"> Edit</div>
                    <div class="form-check"><input type="checkbox" name="can_delete" value="1"> Delete</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
// Handle permission deletion
if (canUploadAndManage() && isset($_GET['del_perm'])) {
    $permId = (int)$_GET['del_perm'];
    $db->prepare("DELETE FROM document_permissions WHERE id = ? AND document_id = ?")->execute([$permId, $id]);
    logAudit('permission_change', 'documents', $id, $doc['title'], "Removed permission ID: {$permId}");
    setFlash('success', 'Hak akses berhasil dihapus');
    header('Location: ' . BASE_URL . 'modules/documents/view.php?id=' . $id);
    exit;
}
?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>