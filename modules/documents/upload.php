<?php
// FILE: modules/documents/upload.php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/label_generator.php';

requireLogin();

$db = getDB();
$user = currentUser();

// PROTEKSI: Hanya Admin atau Department Sekretariat
if (!canUploadAndManage()) {
    setFlash('danger', 'Akses ditolak! Hanya Departemen Sekretariat yang diperbolehkan mengunggah dokumen.');
    header('Location: ' . BASE_URL . 'modules/dashboard/');
    exit;
}


// ============================================
// PROSES POST HARUS SEBELUM OUTPUT HTML
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title            = trim($_POST['title'] ?? '');
    $description      = trim($_POST['description'] ?? '');
    $categoryId       = (int)($_POST['category_id'] ?? 0);
    $folderId         = (int)($_POST['folder_id'] ?? 0) ?: null;
    $expiryDate       = $_POST['expiry_date'] ?? null;
    $expiryAlertDays  = (int)($_POST['expiry_alert_days'] ?? 30);
    $division         = $_POST['division'] ?? $user['division'];
    $isConfidential   = isset($_POST['is_confidential']) ? 1 : 0;
    $selectedTags     = $_POST['tags'] ?? [];
    $metadata         = $_POST['meta'] ?? [];
    $changeNotes      = trim($_POST['change_notes'] ?? 'Initial upload');

    if (empty($title) || !$categoryId) {
        setFlash('danger', 'Judul dan Kategori wajib diisi!');
        header('Location: ' . BASE_URL . 'modules/documents/upload.php');
        exit;
    }

    $isAutoArchive = isset($_POST['auto_archive_source']) && $_POST['auto_archive_source'] == '1';
    $sourceFilePath = $_POST['source_file_path'] ?? '';
    
    $fileData = [];

    if ($isAutoArchive) {
        // Coba beberapa alternatif path untuk mendeteksi lokasi file fisik secara akurat
        $absoluteSourcePath = '';
        
        // Alternatif 1: Berdasarkan Document Root server + path dari database
        $pathOption1 = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($sourceFilePath, '/');
        
        // Alternatif 2: Jika sistem arsip berada di subfolder /arsip dan path relatif mengarah ke root utama
        $pathOption2 = realpath(__DIR__ . '/../../' . $sourceFilePath);
        
        if (!empty($sourceFilePath) && file_exists($pathOption1)) {
            $absoluteSourcePath = $pathOption1;
        } elseif ($pathOption2 && file_exists($pathOption2)) {
            $absoluteSourcePath = $pathOption2;
        } elseif (file_exists($sourceFilePath)) {
            $absoluteSourcePath = $sourceFilePath;
        }

        if (empty($absoluteSourcePath)) {
            setFlash('danger', 'File dokumen sumber disposisi tidak ditemukan di path: ' . htmlspecialchars($sourceFilePath));
            header('Location: ' . BASE_URL . 'modules/documents/upload.php');
            exit;
        }
        
        $fileData['name'] = basename($sourceFilePath);
        $fileData['size'] = filesize($absoluteSourcePath);
        $fileData['tmp_name'] = $absoluteSourcePath;
        $fileData['is_external'] = true;
    } else {
        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            setFlash('danger', 'File dokumen wajib diunggah!');
            header('Location: ' . BASE_URL . 'modules/documents/upload.php');
            exit;
        }
        $fileData = $_FILES['document'];
        $fileData['is_external'] = false;
    }

    $ext = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ALLOWED_TYPES)) {
        setFlash('danger', 'Tipe file tidak diizinkan! Allowed: ' . implode(', ', ALLOWED_TYPES));
        header('Location: ' . BASE_URL . 'modules/documents/upload.php');
        exit;
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        setFlash('danger', 'Ukuran file melebihi batas (50MB)!');
        header('Location: ' . BASE_URL . 'modules/documents/upload.php');
        exit;
    }

    try {
        $db->beginTransaction();

        // Generate nomor arsip otomatis dengan division + department
        $archiveNumber = generateArchiveNumber($categoryId, $division, $department);

        // Insert dokumen
        $stmt = $db->prepare("INSERT INTO documents_arsip (archive_number, title, description, category_id, folder_id, status, expiry_date, expiry_alert_days, uploaded_by, division, department, is_confidential, current_version) 
            VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            $archiveNumber, $title, $description, $categoryId, $folderId,
            $expiryDate ?: null, $expiryAlertDays, $user['id'], $division, $department, $isConfidential
        ]);
        $docId = $db->lastInsertId();

        // GENERATE LABEL FISIK
        generateDocumentLabel($docId, $archiveNumber);

        // Simpan file fisik
        $uploadDir = UPLOAD_DIR . $docId . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safeFileName = 'v1.0_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileData['name']);
        $filePath = $uploadDir . $safeFileName;

        if (!empty($fileData['is_external'])) {
            // Salin file dari direktori disposisi ke direktori arsip
            copy($fileData['tmp_name'], $filePath);
        } else {
            move_uploaded_file($fileData['tmp_name'], $filePath);
        }

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

        // Insert versi awal
        // Ambil nama file dengan aman, pastikan tidak kosong
        $originalFileName = '';
        if (!empty($fileData['name'])) {
            $originalFileName = $fileData['name'];
        } elseif (!empty($sourceFilePath)) {
            $originalFileName = basename($sourceFilePath);
        } else {
            $originalFileName = 'document_' . time() . '.pdf';
        }

        // Insert versi awal dengan memastikan file_name terisi
        $stmt = $db->prepare("INSERT INTO document_versions 
            (document_id, version_number, file_name, file_path, file_size, file_type, mime_type, change_notes, uploaded_by) 
            VALUES (?, '1.0', ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $docId, 
            $originalFileName, 
            $filePath, 
            $fileData['size'] ?? filesize($filePath), 
            $ext, 
            $mimeType, 
            $changeNotes, 
            $user['id']
        ]);

        // Simpan metadata dinamis
        foreach ($metadata as $fieldId => $value) {
            if ($value !== '') {
                $stmt = $db->prepare("INSERT INTO document_metadata (document_id, field_id, field_value) VALUES (?, ?, ?)");
                $stmt->execute([$docId, (int)$fieldId, $value]);
            }
        }

        // Simpan tags
        foreach ($selectedTags as $tagId) {
            if (is_numeric($tagId)) {
                $stmt = $db->prepare("INSERT INTO document_tags (document_id, tag_id) VALUES (?, ?)");
                $stmt->execute([$docId, (int)$tagId]);
            } else {
                $stmt = $db->prepare("INSERT IGNORE INTO tags (name) VALUES (?)");
                $stmt->execute([$tagId]);

                $stmt = $db->prepare("SELECT id FROM tags WHERE name = ?");
                $stmt->execute([$tagId]);
                $newTagId = $stmt->fetchColumn();

                if ($newTagId) {
                    $stmt = $db->prepare("INSERT INTO document_tags (document_id, tag_id) VALUES (?, ?)");
                    $stmt->execute([$docId, $newTagId]);
                }
            }
        }

        // Jika berasal dari auto-archive disposisi, update status disposisi utama menjadi completed
        if ($isAutoArchive && !empty($_POST['disposition_id'])) {
            $dispositionId = (int)$_POST['disposition_id'];
            // Asumsi tabel disposisi berada di database yang sama atau koneksi PDO global terhubung
            // Jika database terpisah, pastikan melakukan koneksi ke database disposisi
            $stmtDisp = $db->prepare("UPDATE dispositions SET status = 'completed', completed_at = NOW(), updated_at = NOW() WHERE id = ?");
            $stmtDisp->execute([$dispositionId]);

            // Catat history disposisi jika diperlukan
            $stmtHist = $db->prepare("INSERT INTO disposition_history (disposition_id, user_id, action, notes) VALUES (?, ?, 'completed', ?)");
            $stmtHist->execute([$dispositionId, $user['id'], 'Diarsipkan otomatis melalui sistem arsip']);
        }

        $db->commit();

        logAudit('upload', 'documents', $docId, $title, "Uploaded document: {$file['name']} ({$ext})");
        setFlash('success', "Dokumen berhasil diunggah! Nomor Arsip: {$archiveNumber}");
        header('Location: ' . BASE_URL . 'modules/documents/view.php?id=' . $docId);
        exit;

    } catch (Exception $e) {
        $db->rollBack();
        setFlash('danger', 'Error: ' . $e->getMessage());
        header('Location: ' . BASE_URL . 'modules/documents/upload.php');
        exit;
    }
}

// ============================================
// AMBIL DATA UNTUK FORM (GET)
// ============================================
$categories = $db->query("SELECT * FROM document_categories WHERE is_active = 1 ORDER BY name")->fetchAll();
$folders    = $db->query("SELECT * FROM folders ORDER BY division, name")->fetchAll();
$tags       = $db->query("SELECT * FROM tags ORDER BY name")->fetchAll();
$divisions  = ['Keuangan','Pengadaan','Keamanan','Infokom','SDM','Direksi','IT','Legal','Kesekretariatan','JaKon','Pengangkutan & Pergudangan','Perdagangan','Energi & Migas'];

// BARU SETELAH SEMUA LOGIKA SELESAI, INCLUDE TAMPILAN
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-cloud-upload-alt"></i> Upload Dokumen Baru</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label class="required">File Dokumen</label>
                <div class="file-upload-area">
                    <div class="upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                    <p>Klik atau drag & drop file di sini</p>
                    <p style="font-size:12px;">Format: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG | Maks: 50MB</p>
                    <div class="file-name"></div>
                    <input type="file" name="document" id="file-input" style="display:none;" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="required">Judul Dokumen</label>
                    <input type="text" name="title" class="form-control" placeholder="Masukkan judul dokumen" required>
                </div>
                <div class="form-group">
                    <label class="required">Kategori</label>
                    <select name="category_id" id="category_id" class="form-control" required>
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Deskripsi</label>
                <textarea name="description" class="form-control" placeholder="Deskripsi singkat dokumen..."></textarea>
            </div>

            <!-- Field metadata dinamis (diisi via JS saat kategori dipilih) -->
            <div id="metadata-fields" class="mb-3"></div>

            <div class="form-row">
                <div class="form-group">
                    <label>Folder</label>
                    <select name="folder_id" class="form-control">
                        <option value="">-- Tidak ada folder --</option>
                        <?php foreach ($folders as $folder): ?>
                        <option value="<?= $folder['id'] ?>">
                            [<?= htmlspecialchars($folder['division']) ?>] <?= htmlspecialchars($folder['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Divisi</label>
                    <select name="department" class="form-control">
                        <?php foreach (DEPARTMENTS as $dept): ?>
                        <option value="<?= $dept ?>" <?= $dept == $user['department'] ? 'selected' : '' ?>><?= $dept ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Bidang</label>
                    <select name="division" class="form-control">
                        <?php foreach ($divisions as $div): ?>
                        <option value="<?= $div ?>" <?= $div == $user['division'] ? 'selected' : '' ?>><?= $div ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Tanggal Expired</label>
                    <input type="date" name="expiry_date" class="form-control">
                    <div class="form-text">Kosongkan jika dokumen tidak memiliki masa berlaku</div>
                </div>
                <div class="form-group">
                    <label>Alert Sebelum Expired (hari)</label>
                    <input type="number" name="expiry_alert_days" class="form-control" value="30" min="1">
                </div>
            </div>

            <div class="form-group">
                <label>Tags</label>
                <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
                    <?php foreach ($tags as $tag): ?>
                    <label class="form-check" style="margin:0;">
                        <input type="checkbox" name="tags[]" value="<?= $tag['id'] ?>">
                        <span class="badge-tag" style="background: <?= $tag['color'] ?>20; color: <?= $tag['color'] ?>;">
                            <?= htmlspecialchars($tag['name']) ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label>Catatan Perubahan</label>
                <input type="text" name="change_notes" class="form-control" value="Initial upload">
            </div>

            <div class="form-check">
                <input type="checkbox" name="is_confidential" id="is_confidential" value="1">
                <label for="is_confidential"><i class="fas fa-lock"></i> Dokumen Rahasia (hanya admin & pemilik yang bisa akses)</label>
            </div>

            <div style="margin-top:20px;">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-upload"></i> Upload Dokumen
                </button>
                <a href="<?= BASE_URL ?>modules/documents/" class="btn btn-outline btn-lg">Batal</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>