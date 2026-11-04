<?php
// FILE: modules/documents/delete.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

if (!$id || !checkDocPermission($id, 'can_delete')) {
    setFlash('danger', 'Akses ditolak!');
    header('Location: ' . BASE_URL . 'modules/documents/');
    exit;
}

$stmt = $db->prepare("SELECT * FROM documents_arsip WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if ($doc) {
    // Delete files
    $uploadDir = UPLOAD_DIR . $id . '/';
    if (is_dir($uploadDir)) {
        $files = glob($uploadDir . '*');
        foreach ($files as $f) if (is_file($f)) unlink($f);
        rmdir($uploadDir);
    }

    logAudit('delete', 'documents', $id, $doc['title'], 'Document deleted: ' . $doc['archive_number']);

    $db->prepare("DELETE FROM documents_arsip WHERE id = ?")->execute([$id]);
    setFlash('success', 'Dokumen berhasil dihapus!');
} else {
    setFlash('danger', 'Dokumen tidak ditemukan!');
}

header('Location: ' . BASE_URL . 'modules/documents/');
exit;