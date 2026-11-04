<?php
// FILE: modules/documents/edit.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();
$user = currentUser();
$id = (int)($_GET['id'] ?? 0);

// 1. Cek Parameter ID & Hak Akses
if (!$id || !checkDocPermission($id, 'can_edit')) {
    setFlash('danger', 'Akses ditolak!');
    header('Location: ' . BASE_URL . 'modules/documents/');
    exit;
}

// 2. Ambil Data Dokumen dari Tabel Terbaru: documents_arsip
$stmt = $db->prepare("SELECT * FROM documents_arsip WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    setFlash('danger', 'Dokumen tidak ditemukan!');
    header('Location: ' . BASE_URL . 'modules/documents/');
    exit;
}

// 3. PROSES SIMPAN PERUBAHAN (POST) - HARUS SEBELUM OUTPUT HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title           = trim($_POST['title'] ?? '');
    $description     = trim($_POST['description'] ?? '');
    $categoryId      = (int)($_POST['category_id'] ?? 0);
    $folderId        = (int)($_POST['folder_id'] ?? 0) ?: null;
    $expiryDate      = $_POST['expiry_date'] ?? null;
    $expiryAlertDays = (int)($_POST['expiry_alert_days'] ?? 30);
    $division        = $_POST['division'] ?? $doc['division'];
    $department      = $_POST['department'] ?? $doc['department'];
    $status          = $_POST['status'] ?? $doc['status'];
    $isConfidential  = isset($_POST['is_confidential']) ? 1 : 0;
    $selectedTags    = $_POST['tags'] ?? [];
    $metadata        = $_POST['meta'] ?? [];

    if (empty($title) || !$categoryId) {
        setFlash('danger', 'Judul dan Kategori wajib diisi!');
        header('Location: ' . BASE_URL . 'modules/documents/edit.php?id=' . $id);
        exit;
    }

    try {
        $db->beginTransaction();

        // Reset notifikasi jika tanggal expired diubah
        $resetExpiry = ($expiryDate != $doc['expiry_date']) ? ', expiry_notified = 0' : '';

        // Query UPDATE menggunakan tabel documents_arsip
        $stmt = $db->prepare("
            UPDATE documents_arsip 
            SET title = ?, description = ?, category_id = ?, folder_id = ?, 
                expiry_date = ?, expiry_alert_days = ?, division = ?, department = ?, 
                status = ?, is_confidential = ? {$resetExpiry} 
            WHERE id = ?
        ");
        $stmt->execute([
            $title, $description, $categoryId, $folderId, 
            $expiryDate ?: null, $expiryAlertDays, $division, $department, 
            $status, $isConfidential, $id
        ]);

        // Update Metadata Field Dinamis
        $db->prepare("DELETE FROM document_metadata WHERE document_id = ?")->execute([$id]);
        foreach ($metadata as $fieldId => $value) {
            if ($value !== '') {
                $stmt = $db->prepare("INSERT INTO document_metadata (document_id, field_id, field_value) VALUES (?, ?, ?)");
                $stmt->execute([$id, (int)$fieldId, $value]);
            }
        }

        // Update Tags Dokumen
        $db->prepare("DELETE FROM document_tags WHERE document_id = ?")->execute([$id]);
        foreach ($selectedTags as $tagId) {
            if (is_numeric($tagId)) {
                $stmt = $db->prepare("INSERT INTO document_tags (document_id, tag_id) VALUES (?, ?)");
                $stmt->execute([$id, (int)$tagId]);
            }
        }

        $db->commit();

        logAudit('edit', 'documents_arsip', $id, $title, 'Informasi dokumen diperbarui');
        setFlash('success', 'Dokumen berhasil diperbarui!');
        header('Location: ' . BASE_URL . 'modules/documents/view.php?id=' . $id);
        exit;

    } catch (Exception $e) {
        $db->rollBack();
        setFlash('danger', 'Error: ' . $e->getMessage());
        header('Location: ' . BASE_URL . 'modules/documents/edit.php?id=' . $id);
        exit;
    }
}

// 4. AMBIL DATA PENDUKUNG FORM
$categories = $db->query("SELECT * FROM document_categories WHERE is_active = 1 ORDER BY name")->fetchAll();
$folders    = $db->query("SELECT * FROM folders ORDER BY division, name")->fetchAll();
$allTags    = $db->query("SELECT * FROM tags ORDER BY name")->fetchAll();

// Tag terpilih saat ini
$stmt = $db->prepare("SELECT tag_id FROM document_tags WHERE document_id = ?");
$stmt->execute([$id]);
$currentTags = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Metadata terpilih saat ini
$stmt = $db->prepare("SELECT field_id, field_value FROM document_metadata WHERE document_id = ?");
$stmt->execute([$id]);
$currentMeta = [];
foreach ($stmt->fetchAll() as $m) {
    $currentMeta[$m['field_id']] = $m['field_value'];
}

// Field metadata sesuai kategori dokumen
$metaFields = [];
if ($doc['category_id']) {
    $stmt = $db->prepare("SELECT * FROM category_metadata_fields WHERE category_id = ? ORDER BY sort_order");
    $stmt->execute([$doc['category_id']]);
    $metaFields = $stmt->fetchAll();
}

// 5. BARU TAMPILKAN HEADER & SIDEBAR HTML
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-edit"></i> Edit Dokumen</h3>
        <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $id ?>" class="btn btn-outline btn-sm">← Kembali</a>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label class="required">Judul Dokumen</label>
                    <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($doc['title']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Nomor Arsip</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($doc['archive_number']) ?>" disabled>
                </div>
            </div>

            <div class="form-group">
                <label>Deskripsi</label>
                <textarea name="description" class="form-control"><?= htmlspecialchars($doc['description'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="required">Kategori</label>
                    <select name="category_id" id="category_id" class="form-control" required>
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $doc['category_id'] == $cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Folder</label>
                    <select name="folder_id" class="form-control">
                        <option value="">-- Tanpa Folder --</option>
                        <?php foreach ($folders as $f): ?>
                        <option value="<?= $f['id'] ?>" <?= $doc['folder_id'] == $f['id'] ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($f['division']) ?>] <?= htmlspecialchars($f['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Metadata Dinamis -->
            <div id="metadata-fields">
                <?php foreach ($metaFields as $f): ?>
                <div class="form-group">
                    <label><?= htmlspecialchars($f['field_label']) ?> <?= $f['is_required'] ? '<span class="text-danger">*</span>' : '' ?></label>
                    <?php if ($f['field_type'] == 'textarea'): ?>
                    <textarea name="meta[<?= $f['id'] ?>]" class="form-control" <?= $f['is_required'] ? 'required' : '' ?>><?= htmlspecialchars($currentMeta[$f['id']] ?? '') ?></textarea>
                    <?php else: ?>
                    <input type="<?= $f['field_type'] ?>" name="meta[<?= $f['id'] ?>]" class="form-control" value="<?= htmlspecialchars($currentMeta[$f['id']] ?? '') ?>" <?= $f['is_required'] ? 'required' : '' ?>>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Departemen</label>
                    <select name="department" class="form-control">
                        <?php foreach (DEPARTMENTS as $dept): ?>
                        <option value="<?= $dept ?>" <?= $doc['department'] == $dept ? 'selected' : '' ?>><?= $dept ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Divisi</label>
                    <select name="division" class="form-control">
                        <?php foreach (DIVISIONS as $div): ?>
                        <option value="<?= $div ?>" <?= $doc['division'] == $div ? 'selected' : '' ?>><?= $div ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Status Dokumen</label>
                    <select name="status" class="form-control">
                        <option value="active" <?= $doc['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $doc['status'] == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="archived" <?= $doc['status'] == 'archived' ? 'selected' : '' ?>>Archived</option>
                        <option value="expired" <?= $doc['status'] == 'expired' ? 'selected' : '' ?>>Expired</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tanggal Expired</label>
                    <input type="date" name="expiry_date" class="form-control" value="<?= $doc['expiry_date'] ?? '' ?>">
                </div>
                <div class="form-group">
                    <label>Alert Expired (hari)</label>
                    <input type="number" name="expiry_alert_days" class="form-control" value="<?= $doc['expiry_alert_days'] ?>" min="1">
                </div>
            </div>

            <div class="form-group">
                <label>Tags</label>
                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                    <?php foreach ($allTags as $tag): ?>
                    <label class="form-check" style="margin:0;">
                        <input type="checkbox" name="tags[]" value="<?= $tag['id'] ?>" <?= in_array($tag['id'], $currentTags) ? 'checked' : '' ?>>
                        <span class="badge-tag" style="background:<?= $tag['color'] ?>20; color:<?= $tag['color'] ?>;">
                            <?= htmlspecialchars($tag['name']) ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-check">
                <input type="checkbox" name="is_confidential" id="is_confidential" value="1" <?= $doc['is_confidential'] ? 'checked' : '' ?>>
                <label for="is_confidential"><i class="fas fa-lock"></i> Dokumen Rahasia</label>
            </div>

            <div style="margin-top:20px;">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Simpan Perubahan</button>
                <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $id ?>" class="btn btn-outline btn-lg">Batal</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>