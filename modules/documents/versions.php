<?php
// FILE: modules/documents/versions.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $docId = (int)($_POST['document_id'] ?? 0);
    $changeNotes = trim($_POST['change_notes'] ?? '');

    if (!$docId || !checkDocPermission($docId, 'can_edit')) {
        setFlash('danger', 'Akses ditolak!');
        header('Location: ' . BASE_URL . 'modules/documents/');
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM documents_arsip WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();

    if (!$doc) {
        setFlash('danger', 'Dokumen tidak ditemukan!');
        header('Location: ' . BASE_URL . 'modules/documents/');
        exit;
    }

    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        setFlash('danger', 'File wajib diunggah!');
        header('Location: ' . BASE_URL . 'modules/documents/view.php?id=' . $docId);
        exit;
    }

    $file = $_FILES['document'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ALLOWED_TYPES)) {
        setFlash('danger', 'Tipe file tidak diizinkan!');
        header('Location: ' . BASE_URL . 'modules/documents/view.php?id=' . $docId);
        exit;
    }

    $newVersion = $doc['current_version'] + 1;
    $versionNumber = $newVersion . '.0';

    $uploadDir = UPLOAD_DIR . $docId . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $safeFileName = 'v' . $versionNumber . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $filePath = $uploadDir . $safeFileName;
    move_uploaded_file($file['tmp_name'], $filePath);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($filePath);

    $db->beginTransaction();
    try {
        // Insert version
        $stmt = $db->prepare("INSERT INTO document_versions (document_id, version_number, file_name, file_path, file_size, file_type, mime_type, change_notes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$docId, $versionNumber, $file['name'], $filePath, $file['size'], $ext, $mimeType, $changeNotes, $user['id']]);

        // Update document
        $db->prepare("UPDATE documents_arsip SET current_version = ?, updated_at = NOW() WHERE id = ?")->execute([$newVersion, $docId]);

        $db->commit();

        logAudit('version_upload', 'documents', $docId, $doc['title'], "Uploaded version v{$versionNumber}: {$changeNotes}");
        setFlash('success', "Versi v{$versionNumber} berhasil diunggah!");

    } catch (Exception $e) {
        $db->rollBack();
        setFlash('danger', 'Error: ' . $e->getMessage());
    }

    header('Location: ' . BASE_URL . 'modules/documents/view.php?id=' . $docId);
    exit;
}

header('Location: ' . BASE_URL . 'modules/documents/');
exit;