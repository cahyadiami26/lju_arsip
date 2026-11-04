<?php
// FILE: modules/categories/index.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

if (isset($_GET['action']) && $_GET['action'] === 'get_fields') {
    header('Content-Type: application/json');
    $categoryId = (int)($_GET['category_id'] ?? 0);
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM category_metadata_fields WHERE category_id = ? ORDER BY sort_order");
    $stmt->execute([$categoryId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if (!canUploadAndManage()) {
    setFlash('danger', 'Akses ditolak! Hanya admin yang bisa mengelola kategori.');
    header('Location: ' . BASE_URL . 'modules/dashboard/');
    exit;
}

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // TAMBAH KATEGORI
    if ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $description = trim($_POST['description'] ?? '');
        $format = trim($_POST['numbering_format'] ?? '');

        if ($name && $code) {
            $stmt = $db->prepare("INSERT INTO document_categories (name, code, description, numbering_format) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $code, $description, $format]);
            setFlash('success', 'Kategori berhasil ditambahkan!');
        }
    }

    // EDIT KATEGORI (BARU)
    if ($action === 'edit_category') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $description = trim($_POST['description'] ?? '');
        $format = trim($_POST['numbering_format'] ?? '');

        if ($id && $name && $code) {
            $stmt = $db->prepare("UPDATE document_categories SET name=?, code=?, description=?, numbering_format=? WHERE id=?");
            $stmt->execute([$name, $code, $description, $format, $id]);
            setFlash('success', 'Kategori berhasil diperbarui!');
        }
    }

    // TAMBAH FIELD
    if ($action === 'add_field') {
        $categoryId = (int)$_POST['category_id'];
        $fieldName = trim($_POST['field_name'] ?? '');
        $fieldLabel = trim($_POST['field_label'] ?? '');
        $fieldType = $_POST['field_type'] ?? 'text';
        $isRequired = isset($_POST['is_required']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($categoryId && $fieldLabel) {
            if (!$fieldName) $fieldName = strtolower(str_replace(' ', '_', $fieldLabel));
            $stmt = $db->prepare("INSERT INTO category_metadata_fields (category_id, field_name, field_label, field_type, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$categoryId, $fieldName, $fieldLabel, $fieldType, $isRequired, $sortOrder]);
            setFlash('success', 'Field metadata berhasil ditambahkan!');
        }
    }

    // HAPUS KATEGORI & FIELD
    if ($action === 'delete_category') {
        $id = (int)$_POST['id'];
        $db->prepare("UPDATE document_categories SET is_active = 0 WHERE id = ?")->execute([$id]);
        setFlash('success', 'Kategori berhasil dinonaktifkan!');
    }
    if ($action === 'delete_field') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM category_metadata_fields WHERE id = ?")->execute([$id]);
        setFlash('success', 'Field metadata berhasil dihapus!');
    }

    header('Location: ' . BASE_URL . 'modules/categories/');
    exit;
}

$categories = $db->query("SELECT c.*, (SELECT COUNT(*) FROM documents_arsip WHERE category_id = c.id) as doc_count FROM document_categories c ORDER BY c.name")->fetchAll();
foreach ($categories as &$cat) {
    $stmt = $db->prepare("SELECT * FROM category_metadata_fields WHERE category_id = ? ORDER BY sort_order");
    $stmt->execute([$cat['id']]);
    $cat['fields'] = $stmt->fetchAll();
}
unset($cat);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:24px;">
    <!-- Daftar Kategori -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-layer-group"></i> Kategori Dokumen</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <table>
                <thead>
                    <tr><th>Nama</th><th>Kode</th><th>Dokumen</th><th>Status</th><th>Aksi</th></tr>
                </thead>
                <tbody>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($cat['name']) ?></strong></td>
                    <td><code><?= htmlspecialchars($cat['code']) ?></code></td>
                    <td><span class="badge badge-primary"><?= $cat['doc_count'] ?></span></td>
                    <td><span class="badge <?= $cat['is_active'] ? 'badge-success' : 'badge-secondary' ?>"><?= $cat['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
                    <td>
                        <div class="btn-group">
                            <!-- TOMBOL EDIT -->
                            <button type="button" class="btn btn-warning btn-sm" onclick="openEditCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cat['code'], ENT_QUOTES) ?>', '<?= htmlspecialchars($cat['description'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($cat['numbering_format'] ?? '', ENT_QUOTES) ?>')">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Nonaktifkan kategori ini?')">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php if (!empty($cat['fields'])): ?>
                <tr>
                    <td colspan="5" style="background:#f8fafc; padding:8px 16px;">
                        <div style="margin-top:4px;">
                        <?php foreach ($cat['fields'] as $f): ?>
                            <span class="badge badge-secondary" style="margin:2px;">
                                <?= htmlspecialchars($f['field_label']) ?> (<?= $f['field_type'] ?>) <?= $f['is_required'] ? '⚡' : '' ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus field metadata ini?')">
                                    <input type="hidden" name="action" value="delete_field">
                                    <input type="hidden" name="id" value="<?= $f['id'] ?>">
                                    <button type="submit" style="background:none; border:none; color:var(--danger); cursor:pointer;">×</button>
                                </form>
                            </span>
                        <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Forms Tambah -->
    <div>
        <div class="card mb-3">
            <div class="card-header"><h3>Tambah Kategori</h3></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_category">
                    <div class="form-group">
                        <label class="required">Nama Kategori</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="required">Kode</label>
                        <input type="text" name="code" class="form-control" required maxlength="10">
                    </div>
                    <div class="form-group">
                        <label>Deskripsi</label>
                        <textarea name="description" class="form-control"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Format Penomoran</label>
                        <input type="text" name="numbering_format" class="form-control" value="{COUNTER}/{CODE}/{DEPARTMENT}/{DIVISION}/{ROMAN_MONTH}/{YEAR}">
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah Kategori</button>
                </form>
            </div>
        </div>
        
        <!-- Form Tambah Metadata Field (Tetap Sama) -->
        <div class="card">
            <div class="card-header"><h3>Tambah Field Metadata</h3></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add_field">
                    <div class="form-group">
                        <label class="required">Kategori</label>
                        <select name="category_id" class="form-control" required>
                            <option value="">-- Pilih --</option>
                            <?php foreach ($categories as $cat): if ($cat['is_active']): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="required">Label Field</label>
                        <input type="text" name="field_label" class="form-control" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Tipe</label>
                            <select name="field_type" class="form-control">
                                <option value="text">Text</option><option value="date">Date</option><option value="number">Number</option><option value="textarea">Textarea</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_required" id="is_req" value="1"><label for="is_req">Wajib diisi</label>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-plus"></i> Tambah Field</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Edit Kategori -->
<div class="modal-overlay" id="modal-edit-category">
    <div class="modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Kategori</h3>
            <button class="modal-close" onclick="document.getElementById('modal-edit-category').classList.remove('show')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="id" id="edit_cat_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="required">Nama Kategori</label>
                    <input type="text" name="name" id="edit_cat_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="required">Kode</label>
                    <input type="text" name="code" id="edit_cat_code" class="form-control" required maxlength="10">
                </div>
                <div class="form-group">
                    <label>Deskripsi</label>
                    <textarea name="description" id="edit_cat_desc" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label>Format Penomoran</label>
                    <input type="text" name="numbering_format" id="edit_cat_format" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-edit-category').classList.remove('show')">Batal</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditCategory(id, name, code, desc, format) {
    document.getElementById('edit_cat_id').value = id;
    document.getElementById('edit_cat_name').value = name;
    document.getElementById('edit_cat_code').value = code;
    document.getElementById('edit_cat_desc').value = desc;
    document.getElementById('edit_cat_format').value = format;
    document.getElementById('modal-edit-category').classList.add('show');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>