<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// FILE: modules/loans/index.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();
$user = currentUser();

// PROSES POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'borrow') {
        $docId = (int)$_POST['document_id'];
        $borrowerId = (int)$_POST['borrower_id'];
        $purpose = trim($_POST['purpose'] ?? '');
        $loanDate = $_POST['loan_date'] ?? date('Y-m-d');
        $returnDate = $_POST['expected_return_date'] ?? '';

        if (!$docId || !$borrowerId || !$purpose || !$returnDate) {
            setFlash('danger', 'Semua field wajib diisi!');
        } else {
            // Cek apakah dokumen sedang dipinjam
            $check = $db->prepare("SELECT COUNT(*) FROM document_loans WHERE document_id = ? AND status = 'borrowed'");
            $check->execute([$docId]);
            if ($check->fetchColumn() > 0) {
                setFlash('danger', 'Dokumen ini sedang dipinjam dan belum dikembalikan!');
            } else {
                $stmt = $db->prepare("INSERT INTO document_loans (document_id, borrower_id, purpose, loan_date, expected_return_date, status, approved_by) VALUES (?,?,?,?,?,'borrowed',?)");
                $stmt->execute([$docId, $borrowerId, $purpose, $loanDate, $returnDate, $_SESSION['user_id']]);
                
                $doc = $db->prepare("SELECT title, archive_number FROM documents_arsip WHERE id = ?");
                $doc->execute([$docId]);
                $docInfo = $doc->fetch();
                
                $borrower = $db->prepare("SELECT name FROM users WHERE id = ?");
                $borrower->execute([$borrowerId]);
                $borrowerName = $borrower->fetchColumn();
                
                logAudit('loan_out', 'loans', $docId, $docInfo['title'], "Dipinjam oleh: {$borrowerName} | Keperluan: {$purpose} | Hingga: {$returnDate}");
                
                // Notifikasi
                createNotification($borrowerId, $docId, 'permission_granted', 'Peminjaman Dokumen', "Anda telah meminjam dokumen: {$docInfo['archive_number']} - {$docInfo['title']}. Harap dikembalikan sebelum {$returnDate}.");
                
                setFlash('success', 'Peminjaman berhasil dicatat!');
            }
        }
    }

    if ($action === 'return') {
        $loanId = (int)$_POST['loan_id'];
        $returnNotes = trim($_POST['return_notes'] ?? '');
        $status = $_POST['return_status'] ?? 'returned';

        $loan = $db->prepare("
            SELECT l.*, d.title, d.archive_number, u.name as borrower_name 
            FROM document_loans l 
            JOIN documents_arsip d ON l.document_id = d.id 
            JOIN users u ON l.borrower_id = u.id 
            WHERE l.id = ?
        ");
        $loan->execute([$loanId]);
        $loanData = $loan->fetch();

        if ($loanData && $loanData['status'] == 'borrowed') {
            $stmt = $db->prepare("UPDATE document_loans SET actual_return_date = CURDATE(), status = ?, return_notes = ?, returned_to = ? WHERE id = ?");
            $stmt->execute([$status, $returnNotes, $_SESSION['user_id'], $loanId]);
            
            logAudit('loan_return', 'loans', $loanData['document_id'], $loanData['title'], "Dikembalikan oleh: {$loanData['borrower_name']} | Status: {$status} | Catatan: {$returnNotes}");
            setFlash('success', 'Pengembalian dokumen berhasil dicatat!');
        } else {
            setFlash('danger', 'Data peminjaman tidak valid atau sudah dikembalikan!');
        }
    }

    header('Location: ' . BASE_URL . 'modules/loans/');
    exit;
}

// Update status otomatis: overdue
$db->exec("UPDATE document_loans SET status = 'overdue' WHERE status = 'borrowed' AND expected_return_date < CURDATE()");

// Filter
$filterStatus = $_GET['status'] ?? '';
$where = ['1=1'];
$params = [];
if ($filterStatus) { $where[] = "l.status = ?"; $params[] = $filterStatus; }

// Non-admin non-sekretariat: hanya lihat peminjaman sendiri
if (!canUploadAndManage()) {
    $where[] = "l.borrower_id = ?";
    $params[] = $user['id'];
}

$whereClause = implode(' AND ', $where);
$loans = $db->prepare("
    SELECT l.*, d.title, d.archive_number, 
           u1.name as borrower_name, u1.division as borrower_division,
           u2.name as approved_by_name, u3.name as returned_to_name,
           DATEDIFF(l.expected_return_date, CURDATE()) as days_left
    FROM document_loans l 
    JOIN documents_arsip d ON l.document_id = d.id 
    LEFT JOIN users u1 ON l.borrower_id = u1.id 
    LEFT JOIN users u2 ON l.approved_by = u2.id 
    LEFT JOIN users u3 ON l.returned_to = u3.id 
    WHERE {$whereClause}
    ORDER BY l.created_at DESC LIMIT 200
");
$loans->execute($params);
$loans = $loans->fetchAll();

// Data untuk form
$availableDocs = $db->query("
    SELECT d.id, d.archive_number, d.title 
    FROM documents_arsip d 
    WHERE d.id NOT IN (SELECT document_id FROM document_loans WHERE status = 'borrowed')
    ORDER BY d.created_at DESC LIMIT 300
")->fetchAll();

$users = $db->query("SELECT id, name, division, department FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

// Statistics
$statBorrowed = $db->query("SELECT COUNT(*) FROM document_loans WHERE status = 'borrowed'")->fetchColumn();
$statOverdue = $db->query("SELECT COUNT(*) FROM document_loans WHERE status = 'overdue'")->fetchColumn();
$statReturned = $db->query("SELECT COUNT(*) FROM document_loans WHERE status = 'returned'")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<!-- Statistik -->
<div class="stats-grid" style="grid-template-columns: repeat(3, 1fr);">
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-handshake"></i></div>
        <div class="stat-info"><h4><?= $statBorrowed ?></h4><p>Sedang Dipinjam</p></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="stat-info"><h4><?= $statOverdue ?></h4><p>Terlambat Kembali</p></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
        <div class="stat-info"><h4><?= $statReturned ?></h4><p>Sudah Dikembalikan</p></div>
    </div>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" style="display:flex; gap:10px; align-items:center;">
            <label>Filter Status:</label>
            <select name="status" class="form-control" style="width:200px;" onchange="this.form.submit()">
                <option value="">Semua</option>
                <option value="borrowed" <?= $filterStatus == 'borrowed' ? 'selected' : '' ?>>Sedang Dipinjam</option>
                <option value="overdue" <?= $filterStatus == 'overdue' ? 'selected' : '' ?>>Terlambat</option>
                <option value="returned" <?= $filterStatus == 'returned' ? 'selected' : '' ?>>Dikembalikan</option>
                <option value="lost" <?= $filterStatus == 'lost' ? 'selected' : '' ?>>Hilang</option>
            </select>
            <?php if (canUploadAndManage()): ?>
            <button type="button" class="btn btn-primary" data-modal="#modal-borrow" style="margin-left:auto;">
                <i class="fas fa-plus"></i> Catat Peminjaman
            </button>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Table Peminjaman -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-history"></i> Riwayat Peminjaman</h3></div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No. Arsip</th>
                        <th>Judul Dokumen</th>
                        <th>Peminjam</th>
                        <th>Keperluan</th>
                        <th>Tgl Pinjam</th>
                        <th>Batas Kembali</th>
                        <th>Tgl Dikembalikan</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($loans)): ?>
                <tr><td colspan="9" class="text-center text-muted" style="padding:40px;">Belum ada data peminjaman</td></tr>
                <?php else: ?>
                <?php foreach ($loans as $l): ?>
                <tr>
                    <td><code style="font-size:11px;"><?= htmlspecialchars($l['archive_number']) ?></code></td>
                    <td>
                        <a href="<?= BASE_URL ?>modules/documents/view.php?id=<?= $l['document_id'] ?>">
                            <?= htmlspecialchars(mb_substr($l['title'], 0, 35)) ?>
                        </a>
                    </td>
                    <td>
                        <?= htmlspecialchars($l['borrower_name']) ?>
                        <div style="font-size:11px; color:var(--secondary);"><?= htmlspecialchars($l['borrower_division']) ?></div>
                    </td>
                    <td style="font-size:12px; max-width:200px;"><?= htmlspecialchars($l['purpose']) ?></td>
                    <td style="font-size:12px;"><?= date('d/m/Y', strtotime($l['loan_date'])) ?></td>
                    <td style="font-size:12px;">
                        <?= date('d/m/Y', strtotime($l['expected_return_date'])) ?>
                        <?php if ($l['status'] == 'borrowed'): ?>
                        <div style="font-size:11px;">
                            <?php if ($l['days_left'] < 0): ?>
                            <span class="badge badge-danger">Terlambat <?= abs($l['days_left']) ?> hari</span>
                            <?php elseif ($l['days_left'] <= 3): ?>
                            <span class="badge badge-warning">Sisa <?= $l['days_left'] ?> hari</span>
                            <?php else: ?>
                            <span class="badge badge-info"><?= $l['days_left'] ?> hari</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;"><?= $l['actual_return_date'] ? date('d/m/Y', strtotime($l['actual_return_date'])) : '-' ?></td>
                    <td>
                        <?php
                        $badges = [
                            'borrowed' => 'badge-warning', 
                            'returned' => 'badge-success', 
                            'overdue' => 'badge-danger', 
                            'lost' => 'badge-danger'
                        ];
                        $labels = [
                            'borrowed' => 'Dipinjam', 
                            'returned' => 'Kembali', 
                            'overdue' => 'Terlambat', 
                            'lost' => 'Hilang'
                        ];
                        ?>
                        <span class="badge <?= $badges[$l['status']] ?>"><?= $labels[$l['status']] ?></span>
                    </td>
                    <td>
                        <?php if (in_array($l['status'], ['borrowed','overdue']) && canUploadAndManage()): ?>
                        <button class="btn btn-success btn-sm" onclick="openReturnModal(<?= $l['id'] ?>, '<?= htmlspecialchars($l['title'], ENT_QUOTES) ?>', '<?= htmlspecialchars($l['borrower_name'], ENT_QUOTES) ?>')">
                            <i class="fas fa-undo"></i> Kembalikan
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Peminjaman -->
<?php if (canUploadAndManage()): ?>
<div class="modal-overlay" id="modal-borrow">
    <div class="modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3><i class="fas fa-hand-holding"></i> Catat Peminjaman Baru</h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="borrow">
            <div class="modal-body">
                <div class="form-group">
                    <label class="required">Dokumen</label>
                    <select name="document_id" class="form-control" required>
                        <option value="">-- Pilih Dokumen --</option>
                        <?php foreach ($availableDocs as $ad): ?>
                        <option value="<?= $ad['id'] ?>"><?= htmlspecialchars($ad['archive_number']) ?> - <?= htmlspecialchars(mb_substr($ad['title'], 0, 40)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Hanya menampilkan dokumen yang tidak sedang dipinjam.</div>
                </div>
                <div class="form-group">
                    <label class="required">Peminjam</label>
                    <select name="borrower_id" class="form-control" required>
                        <option value="">-- Pilih User Peminjam --</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= $u['department'] ?> / <?= $u['division'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="required">Keperluan / Tujuan Peminjaman</label>
                    <textarea name="purpose" class="form-control" required placeholder="Contoh: Untuk keperluan audit tahunan..."></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Tanggal Pinjam</label>
                        <input type="date" name="loan_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="required">Batas Kembali</label>
                        <input type="date" name="expected_return_date" class="form-control" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline modal-close">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Peminjaman</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Pengembalian -->
<div class="modal-overlay" id="modal-return">
    <div class="modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3><i class="fas fa-undo"></i> Konfirmasi Pengembalian</h3>
            <button class="modal-close" onclick="document.getElementById('modal-return').classList.remove('show')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="return">
            <input type="hidden" name="loan_id" id="return_loan_id" value="">
            <div class="modal-body">
                <div class="alert alert-info">
                    <strong>Dokumen:</strong> <span id="return_doc_title" style="font-weight:600;">-</span><br>
                    <strong>Peminjam:</strong> <span id="return_borrower" style="font-weight:600;">-</span>
                </div>
                <div class="form-group">
                    <label class="required">Status Pengembalian</label>
                    <select name="return_status" class="form-control" required>
                        <option value="returned">Dikembalikan (Kondisi Baik)</option>
                        <option value="lost">Hilang / Tidak Dikembalikan</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Catatan Pengembalian</label>
                    <textarea name="return_notes" class="form-control" rows="3" placeholder="Kondisi dokumen saat dikembalikan..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-return').classList.remove('show')">Batal</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Konfirmasi</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReturnModal(loanId, docTitle, borrower) {
    // Pengecekan null agar tidak error
    var loanInput = document.getElementById('return_loan_id');
    var docSpan = document.getElementById('return_doc_title');
    var borrowerSpan = document.getElementById('return_borrower');
    var modal = document.getElementById('modal-return');

    if (loanInput) loanInput.value = loanId || '';
    if (docSpan) docSpan.textContent = docTitle || '-';
    if (borrowerSpan) borrowerSpan.textContent = borrower || '-';
    if (modal) modal.classList.add('show');
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
