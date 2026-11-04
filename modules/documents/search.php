<?php
// FILE: modules/documents/search.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();
$user = currentUser();

$categories = $db->query("SELECT * FROM document_categories WHERE is_active=1 ORDER BY name")->fetchAll();
$allTags    = $db->query("SELECT * FROM tags ORDER BY name")->fetchAll();

$results  = [];
$searched = false;

if (!empty($_GET) && (
    !empty($_GET['keyword']) || !empty($_GET['category_id']) || !empty($_GET['division']) ||
    !empty($_GET['department']) || !empty($_GET['status']) || !empty($_GET['date_from']) ||
    !empty($_GET['date_to']) || !empty($_GET['tag_ids']) || !empty($_GET['expiry_from']) ||
    !empty($_GET['expiry_to']) || !empty($_GET['meta_keyword'])
)) {
    $searched     = true;
    $keyword      = trim($_GET['keyword'] ?? '');
    $metaKeyword  = trim($_GET['meta_keyword'] ?? '');
    $categoryId   = $_GET['category_id'] ?? '';
    $division     = $_GET['division'] ?? '';
    $department   = $_GET['department'] ?? '';
    $status       = $_GET['status'] ?? '';
    $dateFrom     = $_GET['date_from'] ?? '';
    $dateTo       = $_GET['date_to'] ?? '';
    $tagIds       = $_GET['tag_ids'] ?? [];
    $expiryFrom   = $_GET['expiry_from'] ?? '';
    $expiryTo     = $_GET['expiry_to'] ?? '';

    $where  = ['1=1'];
    $params = [];

    // -------------------------------------------------------
    // Full-text / keyword search di dokumen + metadata
    // -------------------------------------------------------
    if ($keyword !== '') {
        // Cari di: title, description, archive_number, DAN nilai metadata
        $where[] = "(
            d.title LIKE ? 
            OR d.description LIKE ? 
            OR d.archive_number LIKE ?
            OR d.id IN (
                SELECT dm.document_id 
                FROM document_metadata dm 
                WHERE dm.field_value LIKE ?
            )
        )";
        $like = '%' . $keyword . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;

        logAudit('search', 'documents_arsip', null, null, "Search keyword: {$keyword}");
    }

    // Pencarian khusus di metadata saja (opsional, field terpisah)
    if ($metaKeyword !== '') {
        $where[] = "d.id IN (
            SELECT dm.document_id 
            FROM document_metadata dm 
            WHERE dm.field_value LIKE ?
        )";
        $params[] = '%' . $metaKeyword . '%';
    }

    if ($categoryId !== '') {
        $where[] = "d.category_id = ?";
        $params[] = $categoryId;
    }
    if ($division !== '') {
        $where[] = "d.division = ?";
        $params[] = $division;
    }
    if ($department !== '') {
        $where[] = "d.department = ?";
        $params[] = $department;
    }
    if ($status !== '') {
        $where[] = "d.status = ?";
        $params[] = $status;
    }
    if ($dateFrom !== '') {
        $where[] = "d.created_at >= ?";
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $where[] = "d.created_at <= ?";
        $params[] = $dateTo . ' 23:59:59';
    }
    if ($expiryFrom !== '') {
        $where[] = "d.expiry_date >= ?";
        $params[] = $expiryFrom;
    }
    if ($expiryTo !== '') {
        $where[] = "d.expiry_date <= ?";
        $params[] = $expiryTo;
    }
    if (!empty($tagIds) && is_array($tagIds)) {
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $where[] = "d.id IN (SELECT document_id FROM document_tags WHERE tag_id IN ({$placeholders}))";
        foreach ($tagIds as $tid) {
            $params[] = (int)$tid;
        }
    }

    // Batasan akses non-admin
    //if (!isAdmin()) {
    //    $where[] = "(
    //        d.division = ? 
    //        OR d.department = ?
    //        OR d.uploaded_by = ? 
    //        OR d.id IN (
    //            SELECT document_id FROM document_permissions 
    //            WHERE user_id = ? 
    //               OR (division = ? AND user_id IS NULL)
   //               OR (department = ? AND user_id IS NULL)
    //        )
    //    )";
    //    $params[] = $user['division'];
    //    $params[] = $user['department'];
    //    $params[] = $user['id'];
    //    $params[] = $user['id'];
    //    $params[] = $user['division'];
    //    $params[] = $user['department'];
    //}

    $whereClause = implode(' AND ', $where);

    $sql = "
        SELECT d.*, dc.name as category_name, u.name as uploader_name,
               DATEDIFF(d.expiry_date, CURDATE()) as days_left
        FROM documents_arsip d
        LEFT JOIN document_categories dc ON d.category_id = dc.id
        LEFT JOIN users u ON d.uploaded_by = u.id
        WHERE {$whereClause}
        ORDER BY d.created_at DESC
        LIMIT 200
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // Ambil tags + metadata ringkas untuk tiap hasil
    foreach ($results as &$r) {
        $t = $db->prepare("SELECT t.* FROM tags t JOIN document_tags dt ON t.id = dt.tag_id WHERE dt.document_id = ?");
        $t->execute([$r['id']]);
        $r['tags'] = $t->fetchAll();

        // Metadata yang match keyword (jika ada keyword)
        if ($keyword !== '' || $metaKeyword !== '') {
            $searchMeta = $keyword !== '' ? $keyword : $metaKeyword;
            $m = $db->prepare("
                SELECT cmf.field_label, dm.field_value 
                FROM document_metadata dm 
                JOIN category_metadata_fields cmf ON dm.field_id = cmf.id 
                WHERE dm.document_id = ? AND dm.field_value LIKE ?
            ");
            $m->execute([$r['id'], '%' . $searchMeta . '%']);
            $r['matched_meta'] = $m->fetchAll();
        } else {
            $r['matched_meta'] = [];
        }
    }
    unset($r);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="card mb-3">
    <div class="card-header">
        <h3><i class="fas fa-search"></i> Pencarian Lanjutan</h3>
    </div>
    <div class="card-body">
        <form method="GET">
            <!-- Kata Kunci Utama (Dokumen + Metadata) -->
            <div class="form-group">
                <label><i class="fas fa-keyboard"></i> Kata Kunci</label>
                <input type="text" name="keyword" class="form-control" 
                       placeholder="Cari di judul, deskripsi, nomor arsip, ATAU nilai metadata..." 
                       value="<?= htmlspecialchars($_GET['keyword'] ?? '') ?>" 
                       style="font-size:15px; padding:12px;">
                <div class="form-text">
                    Mencari di: judul, deskripsi, nomor arsip, <strong>dan seluruh field metadata</strong> 
                    (Nomor Kontrak, Nama Pihak Ketiga, Perihal, dll.)
                </div>
            </div>

            <!-- Kata Kunci Khusus Metadata (opsional) -->
            <div class="form-group">
                <label><i class="fas fa-database"></i> Kata Kunci Metadata Saja (Opsional)</label>
                <input type="text" name="meta_keyword" class="form-control" 
                       placeholder="Contoh: nomor kontrak, nama pihak ketiga, perihal..." 
                       value="<?= htmlspecialchars($_GET['meta_keyword'] ?? '') ?>">
                <div class="form-text">Hanya mencari di nilai metadata, mengabaikan judul/deskripsi.</div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Kategori</label>
                    <select name="category_id" class="form-control">
                        <option value="">Semua Kategori</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= ($_GET['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Divisi</label>
                    <select name="department" class="form-control">
                        <option value="">Semua Divisi</option>
                        <?php foreach (DEPARTMENTS as $dept): ?>
                        <option value="<?= $dept ?>" <?= ($_GET['department'] ?? '') == $dept ? 'selected' : '' ?>>
                            <?= $dept ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Bidang</label>
                    <select name="division" class="form-control">
                        <option value="">Semua Bidang</option>
                        <?php foreach (DIVISIONS as $div): ?>
                        <option value="<?= $div ?>" <?= ($_GET['division'] ?? '') == $div ? 'selected' : '' ?>>
                            <?= $div ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="">Semua Status</option>
                        <option value="active"   <?= ($_GET['status'] ?? '') == 'active'   ? 'selected' : '' ?>>Active</option>
                        <option value="expired"  <?= ($_GET['status'] ?? '') == 'expired'  ? 'selected' : '' ?>>Expired</option>
                        <option value="archived" <?= ($_GET['status'] ?? '') == 'archived' ? 'selected' : '' ?>>Archived</option>
                        <option value="inactive" <?= ($_GET['status'] ?? '') == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Tanggal Upload Dari</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Tanggal Upload Sampai</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Expiry Dari</label>
                    <input type="date" name="expiry_from" class="form-control" value="<?= htmlspecialchars($_GET['expiry_from'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Expiry Sampai</label>
                    <input type="date" name="expiry_to" class="form-control" value="<?= htmlspecialchars($_GET['expiry_to'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Tags</label>
                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                    <?php foreach ($allTags as $tag): ?>
                    <label class="form-check" style="margin:0;">
                        <input type="checkbox" name="tag_ids[]" value="<?= $tag['id'] ?>"
                            <?= in_array($tag['id'], $_GET['tag_ids'] ?? []) ? 'checked' : '' ?>>
                        <span class="badge-tag" style="background:<?= $tag['color'] ?>20; color:<?= $tag['color'] ?>;">
                            <?= htmlspecialchars($tag['name']) ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="btn-group">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-search"></i> Cari</button>
                <a href="<?= BASE_URL ?>modules/documents/search.php" class="btn btn-outline btn-lg">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if ($searched): ?>
<div class="card">
    <div class="card-header">
        <h3>Hasil Pencarian (<?= count($results) ?> dokumen)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($results)): ?>
        <div style="padding:40px; text-align:center; color:var(--secondary);">
            <i class="fas fa-search" style="font-size:48px; margin-bottom:12px; display:block;"></i>
            <h4>Tidak ada dokumen ditemukan</h4>
            <p>Coba ubah kata kunci atau filter pencarian</p>
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
                        <th>Expired</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($results as $doc): ?>
                <tr>
                    <td><code style="font-size:11px;"><?= htmlspecialchars($doc['archive_number']) ?></code></td>
                    <td>
                        <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $doc['id'] ?>" style="font-weight:500;">
                            <?= htmlspecialchars($doc['title']) ?>
                        </a>
                        <div style="font-size:11px; color:var(--secondary);">
                            <?= htmlspecialchars($doc['uploader_name'] ?? '-') ?>
                            • <?= date('d/m/Y', strtotime($doc['created_at'])) ?>
                        </div>
                        <?php if (!empty($doc['matched_meta'])): ?>
                        <div style="margin-top:4px;">
                            <?php foreach ($doc['matched_meta'] as $mm): ?>
                            <span class="badge badge-info" style="font-size:10px; margin:1px;">
                                <?= htmlspecialchars($mm['field_label']) ?>:
                                <?= htmlspecialchars(mb_substr($mm['field_value'], 0, 40)) ?>
                                <?= mb_strlen($mm['field_value']) > 40 ? '…' : '' ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($doc['category_name'] ?? '-') ?></span></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($doc['department'] ?? '-') ?></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($doc['division'] ?? '-') ?></td>
                    <td>
                        <?php foreach ($doc['tags'] as $tag): ?>
                        <span class="badge-tag" style="background:<?= $tag['color'] ?>20; color:<?= $tag['color'] ?>; font-size:10px;">
                            <?= htmlspecialchars($tag['name']) ?>
                        </span>
                        <?php endforeach; ?>
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
                    <td style="font-size:12px;">
                        <?php if ($doc['expiry_date']): ?>
                            <?= date('d/m/Y', strtotime($doc['expiry_date'])) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $doc['id'] ?>" class="btn btn-info btn-sm">
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
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>