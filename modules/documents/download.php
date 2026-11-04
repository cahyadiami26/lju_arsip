<?php
// FILE: modules/documents/download.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();
$versionId = (int)($_GET['id'] ?? 0);

if (!$versionId) {
    http_response_code(404);
    die('Not found');
}

$stmt = $db->prepare("SELECT dv.*, d.title FROM document_versions dv JOIN documents_arsip d ON dv.document_id = d.id WHERE dv.id = ?");
$stmt->execute([$versionId]);
$version = $stmt->fetch();

if (!$version || !checkDocPermission($version['document_id'], 'can_download')) {
    setFlash('danger', 'Anda tidak memiliki izin download!');
    header('Location: ' . BASE_URL . 'modules/documents/');
    exit;
}

$filePath = $version['file_path'];
if (!file_exists($filePath)) {
    setFlash('danger', 'File tidak ditemukan di server!');
    header('Location: ' . BASE_URL . 'modules/documents/view.php?id=' . $version['document_id']);
    exit;
}

logAudit('download', 'documents', $version['document_id'], $version['title'], "Downloaded v{$version['version_number']}: {$version['file_name']}");

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $version['file_name'] . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache');
readfile($filePath);
exit;