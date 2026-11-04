<?php
// FILE: modules/folders/index.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();
$user = currentUser();

// ============================================
// PROSES POST (Tambah / Edit / Hapus Folder)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canUploadAndManage()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name       = trim($_POST['name'] ?? '');
        $parentId   = (int)($_POST['parent_id'] ?? 0) ?: null;
        $department = $_POST['department'] ?? null;
        $division   = $_POST['division'] ?? null;

        if ($name) {
            $db->prepare("INSERT INTO folders (name, parent_id, department, division, created_by) VALUES (?, ?, ?, ?, ?)")
               ->execute([$name, $parentId, $department ?: null, $division ?: null, $user['id']]);
            setFlash('success', 'Folder berhasil ditambahkan!');
        }
    }

    if ($action === 'edit') {
        $id         = (int)$_POST['id'];
        $name       = trim($_POST['name'] ?? '');
        $parentId   = (int)($_POST['parent_id'] ?? 0) ?: null;
        $department = $_POST['department'] ?? null;
        $division   = $_POST['division'] ?? null;

        if ($id && $name) {
            if ($id === $parentId) $parentId = null; // cegah parent = diri sendiri
            $db->prepare("UPDATE folders SET name=?, parent_id=?, department=?, division=? WHERE id=?")
               ->execute([$name, $parentId, $department ?: null, $division ?: null, $id]);
            setFlash('success', 'Folder berhasil diperbarui!');
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $docCount = $db->prepare("SELECT COUNT(*) FROM documents_arsip WHERE folder_id = ?");
        $docCount->execute([$id]);
        if ($docCount->fetchColumn() > 0) {
            setFlash('danger', 'Folder tidak bisa dihapus karena masih memiliki dokumen!');
        } else {
            // Cek apakah punya subfolder
            $childCount = $db->prepare("SELECT COUNT(*) FROM folders WHERE parent_id = ?");
            $childCount->execute([$id]);
            if ($childCount->fetchColumn() > 0) {
                setFlash('danger', 'Folder tidak bisa dihapus karena masih memiliki subfolder!');
            } else {
                $db->prepare("DELETE FROM folders WHERE id = ?")->execute([$id]);
                setFlash('success', 'Folder berhasil dihapus!');
            }
        }
    }

    header('Location: ' . BASE_URL . 'modules/folders/' . (isset($_GET['view']) ? '?view=' . (int)$_GET['view'] : ''));
    exit;
}

// ============================================
// MODE VIEW: Tampilkan dokumen dalam folder
// ============================================
$viewFolderId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewFolder   = null;
$folderDocs   = [];

if ($viewFolderId > 0) {
    $stmt = $db->prepare("SELECT * FROM folders WHERE id = ?");
    $stmt->execute([$viewFolderId]);
    $viewFolder = $stmt->fetch();

    if ($viewFolder) {
        // Ambil dokumen di folder ini + batasi akses non-admin
        $where  = ['d.folder_id = ?'];
        $params = [$viewFolderId];

        if (!canUploadAndManage()) {
            $where[] = "(
                d.uploaded_by = ?
                OR (d.division = ? AND d.division IS NOT NULL AND d.division != '')
                OR (d.department = ? AND d.department IS NOT NULL AND d.department != '')
                OR d.id IN (
                    SELECT dp.document_id FROM document_permissions dp
                    WHERE dp.user_id = ?
                       OR (dp.user_id IS NULL AND dp.division = ? AND (dp.department IS NULL OR dp.department = '' OR dp.department = ?))
                       OR (dp.user_id IS NULL AND dp.department = ? AND (dp.division IS NULL OR dp.division = '' OR dp.division = ?))
                )
            )";
            $params[] = $user['id'];
            $params[] = $user['division'];
            $params[] = $user['department'];
            $params[] = $user['id'];
            $params[] = $user['division'];
            $params[] = $user['department'];
            $params[] = $user['department'];
            $params[] = $user['division'];
        }

        $whereClause = implode(' AND ', $where);
        $sql = "
            SELECT d.*, dc.name as category_name, u.name as uploader_name,
                   DATEDIFF(d.expiry_date, CURDATE()) as days_left
            FROM documents_arsip d
            LEFT JOIN document_categories dc ON d.category_id = dc.id
            LEFT JOIN users u ON d.uploaded_by = u.id
            WHERE {$whereClause}
            ORDER BY d.created_at DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $folderDocs = $stmt->fetchAll();

        // Tags per dokumen
        foreach ($folderDocs as &$fd) {
            $t = $db->prepare("SELECT t.* FROM tags t JOIN document_tags dt ON t.id = dt.tag_id WHERE dt.document_id = ?");
            $t->execute([$fd['id']]);
            $fd['tags'] = $t->fetchAll();
        }
        unset($fd);
    }
}

// ============================================
// Data struktur folder (mode list)
// ============================================
$folders = $db->query("
    SELECT f.*,
        (SELECT COUNT(*) FROM documents_arsip WHERE folder_id = f.id) as doc_count,
        pf.name as parent_name
    FROM folders f
    LEFT JOIN folders pf ON f.parent_id = pf.id
    ORDER BY f.department, f.division, f.name
")->fetchAll();

function buildFolderTree($folders, $parentId = null) {
    $tree = [];
    foreach ($folders as $folder) {
        if ($folder['parent_id'] == $parentId) {
            $folder['children'] = buildFolderTree($folders, $folder['id']);
            $tree[] = $folder;
        }
    }
    return $tree;
}
$folderTree = buildFolderTree($folders);

function renderFolderTree($tree, $level = 0) {
    foreach ($tree as $folder) {
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
        $escName = htmlspecialchars($folder['name'], ENT_QUOTES);
        $escDept = htmlspecialchars($folder['department'] ?? '', ENT_QUOTES);
        $escDiv  = htmlspecialchars($folder['division'] ?? '', ENT_QUOTES);
        $pid     = $folder['parent_id'] ?? '';

        echo '<tr>';
        echo '<td>' . $indent . '<i class="fas fa-folder" style="color:#d97706;"></i> <strong>' . htmlspecialchars($folder['name']) . '</strong></td>';
        echo '<td>' . htmlspecialchars($folder['department'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($folder['division'] ?? '-') . '</td>';
        echo '<td><span class="badge badge-primary">' . (int)$folder['doc_count'] . '</span></td>';
        echo '<td><div class="btn-group">';

        // TOMBOL VIEW → langsung ke list dokumen folder (bukan search)
        echo '<a href="' . BASE_URL . 'modules/folders/?view=' . $folder['id'] . '" class="btn btn-info btn-sm" title="Lihat Dokumen di Folder"><i class="fas fa-eye"></i></a>';

        if (canUploadAndManage()) {
            echo '<button type="button" class="btn btn-warning btn-sm" title="Edit" onclick="openEditFolder(' . $folder['id'] . ', \'' . $escName . '\', \'' . $pid . '\', \'' . $escDept . '\', \'' . $escDiv . '\')"><i class="fas fa-edit"></i></button>';
            echo '<form method="POST" style="display:inline;" onsubmit="return confirm(\'Hapus folder ini?\')">';
            echo '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $folder['id'] . '">';
            echo '<button type="submit" class="btn btn-danger btn-sm" title="Hapus"><i class="fas fa-trash"></i></button></form>';
        }
        echo '</div></td>';
        echo '</tr>';

        if (!empty($folder['children'])) {
            renderFolderTree($folder['children'], $level + 1);
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<?php if ($viewFolder): ?>
<!-- =========================================================
     MODE: LIST DOKUMEN DI DALAM FOLDER
     ========================================================= -->
<div class="card mb-3">
    <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
        <div>
            <h3 style="margin:0;">
                <i class="fas fa-folder-open" style="color:#d97706;"></i>
                <?= htmlspecialchars($viewFolder['name']) ?>
            </h3>
            <div style="font-size:12px; color:var(--secondary); margin-top:4px;">
                <?php if ($viewFolder['department'] || $viewFolder['division']): ?>
                    <?= htmlspecialchars($viewFolder['department'] ?? '-') ?>
                    <?php if ($viewFolder['department'] && $viewFolder['division']): ?> / <?php endif; ?>
                    <?= htmlspecialchars($viewFolder['division'] ?? '') ?>
                <?php endif; ?>
                &nbsp;•&nbsp; <?= count($folderDocs) ?> dokumen
            </div>
        </div>
        <a href="<?= BASE_URL ?>modules/folders/" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left"></i> Kembali ke Daftar Folder
        </a>
    </div>

    <div class="card-body" style="padding:0;">
        <?php if (empty($folderDocs)): ?>
        <div style="padding:48px; text-align:center; color:var(--secondary);">
            <i class="fas fa-folder-open" style="font-size:48px; display:block; margin-bottom:12px; opacity:.5;"></i>
            <h4>Folder kosong</h4>
            <p>Belum ada dokumen di folder ini, atau Anda tidak memiliki akses ke dokumen yang ada.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No. Arsip</th>
                        <th>Judul</th>
                        <th>Kategori</th>
                        <th>Departemen</th>
                        <th>Divisi</th>
                        <th>Tags</th>
                        <th>Status</th>
                        <th>Tanggal</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($folderDocs as $doc): ?>
                <tr>
                    <td><code style="font-size:11px;"><?= htmlspecialchars($doc['archive_number']) ?></code></td>
                    <td>
                        <!-- Klik judul → document view -->
                        <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $doc['id'] ?>" style="font-weight:600;">
                            <?= htmlspecialchars($doc['title']) ?>
                        </a>
                        <?php if (!empty($doc['is_confidential'])): ?>
                        <i class="fas fa-lock" style="color:var(--danger); font-size:11px;" title="Rahasia"></i>
                        <?php endif; ?>
                        <div style="font-size:11px; color:var(--secondary);">
                            <?= htmlspecialchars($doc['uploader_name'] ?? '-') ?>
                        </div>
                    </td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($doc['category_name'] ?? '-') ?></span></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($doc['department'] ?? '-') ?></td>
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
                    <td>
                        <?php
                        $badges = [
                            'active'   => 'badge-success',
                            'expired'  => 'badge-danger',
                            'inactive' => 'badge-secondary',
                            'archived' => 'badge-warning'
                        ];
                        ?>
                        <span class="badge <?= $badges[$doc['status']] ?? 'badge-secondary' ?>">
                            <?= ucfirst($doc['status']) ?>
                        </span>
                    </td>
                    <td style="font-size:12px; color:var(--secondary); white-space:nowrap;">
                        <?= date('d/m/Y', strtotime($doc['created_at'])) ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $doc['id'] ?>" class="btn btn-info btn-sm" title="Lihat Detail">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- =========================================================
     MODE: STRUKTUR FOLDER
     ========================================================= -->
<div style="display:grid; grid-template-columns:2fr 1fr; gap:24px;">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-folder-tree"></i> Struktur Folder</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <table>
                <thead>
                    <tr>
                        <th>Nama Folder</th>
                        <th>Divisi</th>
                        <th>Bidang</th>
                        <th>Dokumen</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($folderTree)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted" style="padding:40px;">
                            Belum ada folder. <?php if (canUploadAndManage()): ?>Tambahkan folder di form sebelah kanan.<?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php renderFolderTree($folderTree); ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (canUploadAndManage()): ?>
    <div class="card" style="height:fit-content;">
        <div class="card-header"><h3>Tambah Folder</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label class="required">Nama Folder</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Parent Folder</label>
                    <select name="parent_id" class="form-control">
                        <option value="">-- Root (Tanpa Parent) --</option>
                        <?php foreach ($folders as $f): ?>
                        <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Divisi</label>
                        <select name="department" class="form-control">
                            <option value="">-- Pilih --</option>
                            <?php foreach (DEPARTMENTS as $dept): ?>
                            <option value="<?= $dept ?>"><?= $dept ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Bidang</label>
                        <select name="division" class="form-control">
                            <option value="">-- Pilih --</option>
                            <?php foreach (DIVISIONS as $div): ?>
                            <option value="<?= $div ?>"><?= $div ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah Folder</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (canUploadAndManage()): ?>
<!-- Modal Edit Folder -->
<div class="modal-overlay" id="modal-edit-folder">
    <div class="modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Folder</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('modal-edit-folder').classList.remove('show')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_folder_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="required">Nama Folder</label>
                    <input type="text" name="name" id="edit_folder_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Parent Folder</label>
                    <select name="parent_id" id="edit_folder_parent" class="form-control">
                        <option value="">-- Root (Tanpa Parent) --</option>
                        <?php foreach ($folders as $f): ?>
                        <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Departemen</label>
                        <select name="department" id="edit_folder_dept" class="form-control">
                            <option value="">-- Pilih --</option>
                            <?php foreach (DEPARTMENTS as $dept): ?>
                            <option value="<?= $dept ?>"><?= $dept ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Divisi</label>
                        <select name="division" id="edit_folder_div" class="form-control">
                            <option value="">-- Pilih --</option>
                            <?php foreach (DIVISIONS as $div): ?>
                            <option value="<?= $div ?>"><?= $div ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-edit-folder').classList.remove('show')">Batal</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>
<script>
function openEditFolder(id, name, parentId, dept, div) {
    document.getElementById('edit_folder_id').value = id;
    document.getElementById('edit_folder_name').value = name;
    document.getElementById('edit_folder_parent').value = parentId || '';
    document.getElementById('edit_folder_dept').value = dept || '';
    document.getElementById('edit_folder_div').value = div || '';

    var parentSelect = document.getElementById('edit_folder_parent');
    for (var i = 0; i < parentSelect.options.length; i++) {
        parentSelect.options[i].disabled = (parentSelect.options[i].value == id);
    }
    document.getElementById('modal-edit-folder').classList.add('show');
}
</script>
<?php endif; ?>

<?php endif; // end else structure mode ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>