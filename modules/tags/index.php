<?php
// FILE: modules/tags/index.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();
$user = currentUser();

// ============================================
// PROSES POST (Tambah / Edit / Hapus Tag)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canUploadAndManage()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#007bff';
        if ($name) {
            try {
                $db->prepare("INSERT INTO tags (name, color) VALUES (?, ?)")->execute([$name, $color]);
                setFlash('success', 'Tag berhasil ditambahkan!');
            } catch (Exception $e) {
                setFlash('danger', 'Tag sudah ada atau gagal ditambahkan.');
            }
        }
    }

    if ($action === 'edit') {
        $id    = (int)$_POST['id'];
        $name  = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#007bff';
        if ($id && $name) {
            try {
                $db->prepare("UPDATE tags SET name=?, color=? WHERE id=?")->execute([$name, $color, $id]);
                setFlash('success', 'Tag berhasil diperbarui!');
            } catch (Exception $e) {
                setFlash('danger', 'Gagal memperbarui tag (nama mungkin sudah dipakai).');
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM document_tags WHERE tag_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM tags WHERE id = ?")->execute([$id]);
        setFlash('success', 'Tag berhasil dihapus!');
    }

    $redirect = BASE_URL . 'modules/tags/';
    if (!empty($_GET['view'])) {
        $redirect .= '?view=' . (int)$_GET['view'];
    }
    header('Location: ' . $redirect);
    exit;
}

// ============================================
// MODE VIEW: List dokumen yang memakai tag
// ============================================
$viewTagId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewTag   = null;
$tagDocs   = [];

if ($viewTagId > 0) {
    $stmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
    $stmt->execute([$viewTagId]);
    $viewTag = $stmt->fetch();

    if ($viewTag) {
        $where  = ['dt.tag_id = ?'];
        $params = [$viewTagId];

        // Batasi akses non-admin (sama seperti folder/documents index)
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
            FROM document_tags dt
            JOIN documents_arsip d ON dt.document_id = d.id
            LEFT JOIN document_categories dc ON d.category_id = dc.id
            LEFT JOIN users u ON d.uploaded_by = u.id
            WHERE {$whereClause}
            ORDER BY d.created_at DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $tagDocs = $stmt->fetchAll();

        // Ambil semua tags tiap dokumen (untuk tampilan chip)
        foreach ($tagDocs as &$td) {
            $t = $db->prepare("SELECT t.* FROM tags t JOIN document_tags dt2 ON t.id = dt2.tag_id WHERE dt2.document_id = ?");
            $t->execute([$td['id']]);
            $td['tags'] = $t->fetchAll();
        }
        unset($td);
    }
}

// ============================================
// Data daftar tags (mode list)
// ============================================
$tags = $db->query("
    SELECT t.*,
        (SELECT COUNT(*) FROM document_tags WHERE tag_id = t.id) as usage_count
    FROM tags t
    ORDER BY t.name
")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<?php if ($viewTag): ?>
<!-- =========================================================
     MODE: LIST DOKUMEN YANG MENGGUNAKAN TAG INI
     ========================================================= -->
<div class="card mb-3">
    <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:12px;">
            <span class="badge-tag" style="background:<?= htmlspecialchars($viewTag['color']) ?>20; color:<?= htmlspecialchars($viewTag['color']) ?>; font-size:16px; padding:8px 16px;">
                <i class="fas fa-tag"></i> <?= htmlspecialchars($viewTag['name']) ?>
            </span>
            <div style="font-size:13px; color:var(--secondary);">
                <?= count($tagDocs) ?> dokumen
            </div>
        </div>
        <a href="<?= BASE_URL ?>modules/tags/" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left"></i> Kembali ke Daftar Tags
        </a>
    </div>

    <div class="card-body" style="padding:0;">
        <?php if (empty($tagDocs)): ?>
        <div style="padding:48px; text-align:center; color:var(--secondary);">
            <i class="fas fa-tags" style="font-size:48px; display:block; margin-bottom:12px; opacity:.5;"></i>
            <h4>Tidak ada dokumen</h4>
            <p>Belum ada dokumen yang menggunakan tag ini, atau Anda tidak memiliki akses ke dokumen tersebut.</p>
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
                <?php foreach ($tagDocs as $doc): ?>
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
                            <span class="badge-tag" style="background:<?= $tag['color'] ?>20; color:<?= $tag['color'] ?>; font-size:10px; <?= $tag['id'] == $viewTag['id'] ? 'font-weight:700; outline:1px solid '.$tag['color'] : '' ?>;">
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
     MODE: DAFTAR TAGS
     ========================================================= -->
<div style="display:grid; grid-template-columns:2fr 1fr; gap:24px;">
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-tags"></i> Daftar Tags</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <table>
                <thead>
                    <tr>
                        <th>Tag</th>
                        <th>Warna</th>
                        <th>Digunakan</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($tags)): ?>
                <tr>
                    <td colspan="4" class="text-center text-muted" style="padding:40px;">
                        Belum ada tag.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($tags as $tag): ?>
                <tr>
                    <td>
                        <span class="badge-tag" style="background:<?= $tag['color'] ?>20; color:<?= $tag['color'] ?>; font-size:13px;">
                            <?= htmlspecialchars($tag['name']) ?>
                        </span>
                    </td>
                    <td>
                        <span style="display:inline-block; width:20px; height:20px; background:<?= $tag['color'] ?>; border-radius:4px; vertical-align:middle;"></span>
                        <?= htmlspecialchars($tag['color']) ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>modules/tags/?view=<?= $tag['id'] ?>" style="font-weight:600;">
                            <span class="badge badge-primary"><?= (int)$tag['usage_count'] ?></span> dokumen
                        </a>
                    </td>
                    <td>
                        <div class="btn-group">
                            <!-- TOMBOL VIEW → list dokumen bertag ini -->
                            <a href="<?= BASE_URL ?>modules/tags/?view=<?= $tag['id'] ?>" class="btn btn-info btn-sm" title="Lihat Dokumen dengan Tag Ini">
                                <i class="fas fa-eye"></i>
                            </a>

                            <?php if (canUploadAndManage()): ?>
                            <button type="button" class="btn btn-warning btn-sm" title="Edit"
                                onclick="openEditTag(<?= $tag['id'] ?>, '<?= htmlspecialchars($tag['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($tag['color'], ENT_QUOTES) ?>')">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus tag ini? Relasi ke dokumen juga akan dihapus.')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $tag['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Hapus"><i class="fas fa-trash"></i></button>
                            </form>
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

    <?php if (canUploadAndManage()): ?>
    <div class="card" style="height:fit-content;">
        <div class="card-header"><h3>Tambah Tag</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label class="required">Nama Tag</label>
                    <input type="text" name="name" class="form-control" required placeholder="Contoh: Penting">
                </div>
                <div class="form-group">
                    <label>Warna</label>
                    <input type="color" name="color" class="form-control" value="#007bff" style="height:40px; padding:2px;">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (canUploadAndManage()): ?>
<!-- Modal Edit Tag -->
<div class="modal-overlay" id="modal-edit-tag">
    <div class="modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Tag</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('modal-edit-tag').classList.remove('show')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_tag_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="required">Nama Tag</label>
                    <input type="text" name="name" id="edit_tag_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Warna</label>
                    <input type="color" name="color" id="edit_tag_color" class="form-control" style="height:40px; padding:2px;">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-edit-tag').classList.remove('show')">Batal</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>
<script>
function openEditTag(id, name, color) {
    document.getElementById('edit_tag_id').value = id;
    document.getElementById('edit_tag_name').value = name;
    document.getElementById('edit_tag_color').value = color;
    document.getElementById('modal-edit-tag').classList.add('show');
}
</script>
<?php endif; ?>

<?php endif; // end else list mode ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>