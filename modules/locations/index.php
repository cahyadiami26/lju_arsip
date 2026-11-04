<?php
// FILE: modules/locations/index.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canUploadAndManage()) {
    $action = $_POST['action'] ?? '';

    // TAMBAH LOKASI
    if ($action === 'add') {
        $room = trim($_POST['room'] ?? '');
        $cabinet = trim($_POST['cabinet'] ?? '');
        $box = trim($_POST['box'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $cap = (int)($_POST['capacity'] ?? 100);
        
        if ($room && $cabinet && $box) {
            try {
                $stmt = $db->prepare("INSERT INTO physical_locations (room, cabinet, box, description, capacity, created_by) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$room, $cabinet, $box, $desc, $cap, $_SESSION['user_id']]);
                setFlash('success', 'Lokasi baru berhasil ditambahkan!');
            } catch (Exception $e) {
                setFlash('danger', 'Lokasi sudah ada atau error: ' . $e->getMessage());
            }
        }
    }

    // EDIT LOKASI (BARU)
    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        $room = trim($_POST['room'] ?? '');
        $cabinet = trim($_POST['cabinet'] ?? '');
        $box = trim($_POST['box'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $cap = (int)($_POST['capacity'] ?? 100);
        
        if ($id && $room && $cabinet && $box) {
            try {
                $stmt = $db->prepare("UPDATE physical_locations SET room=?, cabinet=?, box=?, description=?, capacity=? WHERE id=?");
                $stmt->execute([$room, $cabinet, $box, $desc, $cap, $id]);
                setFlash('success', 'Data lokasi fisik berhasil diperbarui!');
            } catch (Exception $e) {
                setFlash('danger', 'Kombinasi Ruang/Rak/Box sudah dipakai oleh lokasi lain!');
            }
        }
    }

    // HAPUS LOKASI
    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $used = $db->prepare("SELECT COUNT(*) FROM document_locations WHERE location_id = ?");
        $used->execute([$id]);
        if ($used->fetchColumn() > 0) {
            setFlash('danger', 'Lokasi tidak dapat dihapus karena masih berisi dokumen!');
        } else {
            $db->prepare("DELETE FROM physical_locations WHERE id = ?")->execute([$id]);
            setFlash('success', 'Lokasi berhasil dihapus!');
        }
    }

    // ASSIGN LOKASI
    if ($action === 'assign') {
        $docId = (int)$_POST['document_id'];
        $locId = (int)$_POST['location_id'];
        $notes = trim($_POST['notes'] ?? '');
        
        $db->prepare("DELETE FROM document_locations WHERE document_id = ?")->execute([$docId]);
        $db->prepare("INSERT INTO document_locations (document_id, location_id, notes, placed_by) VALUES (?,?,?,?)")->execute([$docId, $locId, $notes, $_SESSION['user_id']]);
        
        setFlash('success', 'Lokasi dokumen berhasil diperbarui!');
    }

    header('Location: ' . BASE_URL . 'modules/locations/');
    exit;
}

$locations = $db->query("SELECT pl.*, (SELECT COUNT(*) FROM document_locations WHERE location_id = pl.id) as doc_count FROM physical_locations pl ORDER BY pl.room, pl.cabinet, pl.box")->fetchAll();
$unassignedDocs = $db->query("SELECT d.id, d.archive_number, d.title FROM documents_arsip d LEFT JOIN document_locations dl ON d.id = dl.document_id WHERE dl.id IS NULL ORDER BY d.created_at DESC LIMIT 100")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div style="display:grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    <!-- Daftar Lokasi -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-map-marker-alt"></i> Daftar Lokasi Fisik</h3>
        </div>
        <div class="card-body" style="padding:0;">
            <table>
                <thead>
                    <tr><th>Ruang</th><th>Rak/Kabinet</th><th>Box/Folder</th><th>Keterangan</th><th>Isi</th><?php if(canUploadAndManage()): ?><th>Aksi</th><?php endif; ?></tr>
                </thead>
                <tbody>
                <?php foreach ($locations as $loc): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($loc['room']) ?></strong></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($loc['cabinet']) ?></span></td>
                    <td><span class="badge badge-secondary"><?= htmlspecialchars($loc['box']) ?></span></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($loc['description'] ?? '-') ?></td>
                    <td><a href="?view_docs=<?= $loc['id'] ?>" style="font-weight:600;"><?= $loc['doc_count'] ?> / <?= $loc['capacity'] ?></a></td>
                    <?php if (canUploadAndManage()): ?>
                    <td>
                        <div class="btn-group">
                            <button type="button" class="btn btn-warning btn-sm" onclick="openEditLocation(<?= $loc['id'] ?>, '<?= htmlspecialchars($loc['room'], ENT_QUOTES) ?>', '<?= htmlspecialchars($loc['cabinet'], ENT_QUOTES) ?>', '<?= htmlspecialchars($loc['box'], ENT_QUOTES) ?>', '<?= htmlspecialchars($loc['description'] ?? '', ENT_QUOTES) ?>', <?= $loc['capacity'] ?>)"><i class="fas fa-edit"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus lokasi ini?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $loc['id'] ?>">
                                <button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (canUploadAndManage()): ?>
    <div>
        <!-- Form Tambah Lokasi -->
        <div class="card mb-3">
            <div class="card-header"><h3>Tambah Lokasi</h3></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group"><label class="required">Ruang</label><input type="text" name="room" class="form-control" required></div>
                    <div class="form-row">
                        <div class="form-group"><label class="required">Rak/Kabinet</label><input type="text" name="cabinet" class="form-control" required></div>
                        <div class="form-group"><label class="required">Box/Folder</label><input type="text" name="box" class="form-control" required></div>
                    </div>
                    <div class="form-group"><label>Keterangan</label><textarea name="description" class="form-control"></textarea></div>
                    <div class="form-group"><label>Kapasitas (Max Dokumen)</label><input type="number" name="capacity" class="form-control" value="100" min="1"></div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Tambah</button>
                </form>
            </div>
        </div>

        <!-- Form Penempatan Dokumen -->
        <div class="card">
            <div class="card-header"><h3>Tempatkan Dokumen</h3></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="assign">
                    <div class="form-group">
                        <label class="required">Dokumen (Belum dialokasikan)</label>
                        <select name="document_id" class="form-control" required>
                            <option value="">-- Pilih Dokumen --</option>
                            <?php foreach ($unassignedDocs as $ud): ?><option value="<?= $ud['id'] ?>"><?= htmlspecialchars($ud['archive_number']) ?> - <?= htmlspecialchars(mb_substr($ud['title'], 0, 30)) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="required">Lokasi</label>
                        <select name="location_id" class="form-control" required>
                            <option value="">-- Pilih Lokasi --</option>
                            <?php foreach ($locations as $loc): ?><option value="<?= $loc['id'] ?>"><?= htmlspecialchars($loc['room']) ?> / <?= htmlspecialchars($loc['cabinet']) ?> / <?= htmlspecialchars($loc['box']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Catatan Penempatan</label><input type="text" name="notes" class="form-control"></div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-map-pin"></i> Tempatkan</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Edit Lokasi -->
<?php if (canUploadAndManage()): ?>
<div class="modal-overlay" id="modal-edit-location">
    <div class="modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Lokasi Fisik</h3>
            <button class="modal-close" onclick="document.getElementById('modal-edit-location').classList.remove('show')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_loc_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="required">Ruang</label>
                    <input type="text" name="room" id="edit_loc_room" class="form-control" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Rak/Kabinet</label>
                        <input type="text" name="cabinet" id="edit_loc_cabinet" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="required">Box/Folder</label>
                        <input type="text" name="box" id="edit_loc_box" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Keterangan</label>
                    <textarea name="description" id="edit_loc_desc" class="form-control"></textarea>
                </div>
                <div class="form-group">
                    <label>Kapasitas</label>
                    <input type="number" name="capacity" id="edit_loc_cap" class="form-control" min="1" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-edit-location').classList.remove('show')">Batal</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>
<script>
function openEditLocation(id, room, cabinet, box, desc, capacity) {
    document.getElementById('edit_loc_id').value = id;
    document.getElementById('edit_loc_room').value = room;
    document.getElementById('edit_loc_cabinet').value = cabinet;
    document.getElementById('edit_loc_box').value = box;
    document.getElementById('edit_loc_desc').value = desc;
    document.getElementById('edit_loc_cap').value = capacity;
    document.getElementById('modal-edit-location').classList.add('show');
}
</script>
<?php endif; ?>

<!-- Script view docs di bawah sama seperti sebelumnya... -->
<?php
if (isset($_GET['view_docs'])) {
    $locId = (int)$_GET['view_docs'];
    $docsInLoc = $db->prepare("SELECT d.id, d.archive_number, d.title, dl.notes, dl.placed_at FROM document_locations dl JOIN documents_arsip d ON dl.document_id = d.id WHERE dl.location_id = ? ORDER BY dl.placed_at DESC");
    $docsInLoc->execute([$locId]);
    $docsInLoc = $docsInLoc->fetchAll();
?>
<div class="modal-overlay show">
    <div class="modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3>Dokumen di Lokasi Ini (<?= count($docsInLoc) ?>)</h3>
            <a href="<?= BASE_URL ?>modules/locations/" class="modal-close" style="text-decoration:none;">&times;</a>
        </div>
        <div class="modal-body">
            <?php if (empty($docsInLoc)): ?>
            <p class="text-muted text-center">Lokasi ini kosong.</p>
            <?php else: ?>
            <table>
                <thead><tr><th>No. Arsip</th><th>Judul</th><th>Catatan</th></tr></thead>
                <tbody>
                <?php foreach ($docsInLoc as $d): ?>
                <tr>
                    <td><code><?= htmlspecialchars($d['archive_number']) ?></code></td>
                    <td><a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $d['id'] ?>"><?= htmlspecialchars($d['title']) ?></a></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($d['notes'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php } ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>