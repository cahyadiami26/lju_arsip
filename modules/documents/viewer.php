<?php
// FILE: modules/documents/viewer.php
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

if (!$version || !checkDocPermission($version['document_id'], 'can_read')) {
    http_response_code(403);
    die('Access denied');
}

$filePath = $version['file_path'];
if (!file_exists($filePath)) {
    http_response_code(404);
    die('File not found');
}

$mimeType = $version['mime_type'] ?: 'application/octet-stream';

header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . basename($version['file_name']) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
readfile($filePath);
exit;