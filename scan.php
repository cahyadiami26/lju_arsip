<?php
// FILE: scan.php
require_once __DIR__ . '/config/database.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

// Query mengambil data dokumen beserta relasi lokasi fisiknya
$stmt = $db->prepare("
    SELECT d.*, dc.name as category_name, u.name as uploader_name,
           pl.room, pl.cabinet, pl.box, dl.notes as placement_notes
    FROM documents_arsip d 
    LEFT JOIN document_categories dc ON d.category_id = dc.id 
    LEFT JOIN users u ON d.uploaded_by = u.id 
    LEFT JOIN document_locations dl ON d.id = dl.document_id
    LEFT JOIN physical_locations pl ON dl.location_id = pl.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    die('<div style="text-align:center; padding:50px; font-family:sans-serif; color:#64748b;">
            <h1 style="font-size:48px; margin-bottom:10px;">📄</h1>
            <h3>Dokumen tidak ditemukan atau telah dihapus.</h3>
         </div>');
}

// Logika Format Lokasi
$locationText = '<span style="color:#ef4444; font-style:italic; font-weight:bold;">Lokasi belum ditentukan</span>';
if ($doc['room']) {
    $locationText = htmlspecialchars($doc['room']) . ' &nbsp;→&nbsp; Rak/Kabinet: <strong>' . htmlspecialchars($doc['cabinet']) . '</strong> &nbsp;→&nbsp; Box: <strong>' . htmlspecialchars($doc['box']) . '</strong>';
    if ($doc['placement_notes']) {
        $locationText .= '<br><small style="color:#64748b; margin-top:4px; display:block;">Catatan: ' . htmlspecialchars($doc['placement_notes']) . '</small>';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

    <title>Info Arsip: <?= htmlspecialchars($doc['archive_number']) ?></title>
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f1f5f9; margin: 0; padding: 15px; color: #334155; line-height: 1.5; }
        .card { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); max-width: 600px; margin: 0 auto; }
        .header { text-align: center; border-bottom: 2px dashed #e2e8f0; padding-bottom: 20px; margin-bottom: 20px; }
        .header h2 { margin: 0 0 5px 0; color: #1e3a5f; font-size: 22px; }
        .info-group { margin-bottom: 16px; background: #f8fafc; padding: 12px 16px; border-radius: 8px; border-left: 4px solid #3b82f6; }
        .info-group.location { border-left-color: #f59e0b; background: #fffbeb; }
        .info-label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 4px; letter-spacing: 0.5px; }
        .info-value { font-size: 14px; font-weight: 600; color: #0f172a; word-break: break-word; }
        .badge { background: #dbeafe; color: #1e40af; padding: 4px 10px; border-radius: 6px; font-size: 13px; font-weight: 700; display: inline-block; border: 1px solid #bfdbfe; }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
                <img src="/assets/img/logo.png" alt="Logo" style="max-height: 40px; border-radius: 8px; vertical-align: middle; align: center;">
            <h2>PT. Lampung Jasa Utama</h2>
            <p style="margin:0; font-size:13px; color:#64748b;">Sistem Manajemen Arsip Elektronik</p>
        </div>
        
        <div class="info-group">
            <div class="info-label">Nomor Arsip</div>
            <div class="info-value"><span class="badge"><?= htmlspecialchars($doc['archive_number']) ?></span></div>
        </div>
        
        <div class="info-group">
            <div class="info-label">Judul Dokumen</div>
            <div class="info-value" style="font-size:16px;"><?= htmlspecialchars($doc['title']) ?></div>
        </div>

        <div class="info-group location">
            <div class="info-label" style="color:#d97706;">📍 Lokasi Penyimpanan Fisik</div>
            <div class="info-value"><?= $locationText ?></div>
        </div>
        
        <div class="info-group">
            <div class="info-label">Kategori Dokumen</div>
            <div class="info-value"><?= htmlspecialchars($doc['category_name']) ?></div>
        </div>

        <div class="info-group">
            <div class="info-label">Divisi / Bidang</div>
            <div class="info-value"><?= htmlspecialchars($doc['department']) ?> / <?= htmlspecialchars($doc['division']) ?></div>
        </div>
        
        <div class="info-group">
            <div class="info-label">Tanggal Upload</div>
            <div class="info-value"><?= date('d F Y', strtotime($doc['created_at'])) ?></div>
        </div>
        
        <?php if (!empty($doc['description'])): ?>
        <div class="info-group" style="border-left-color:#cbd5e1; background:transparent; border:1px solid #e2e8f0;">
            <div class="info-label">Deskripsi</div>
            <div class="info-value" style="font-weight: 400; font-size:13px;">
                <?= nl2br(htmlspecialchars($doc['description'])) ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>