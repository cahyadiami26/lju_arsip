<?php
// FILE: modules/users/index.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

if (!isAdmin()) {
    setFlash('danger', 'Akses ditolak! Hanya Administrator yang dapat mengelola pengguna.');
    header('Location: ' . BASE_URL . 'modules/dashboard/');
    exit;
}

$db = getDB();

// ============================================
// PROSES POST — SEBELUM OUTPUT HTML
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ----- TAMBAH USER -----
    if ($action === 'add') {
        $nik         = trim($_POST['nik'] ?? '');
        $name        = trim($_POST['name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $position    = $_POST['position'] ?? '';
        $division    = $_POST['division'] ?? '';
        $department  = $_POST['department'] ?? '';
        $username    = trim($_POST['username'] ?? '');
        $passwordRaw = $_POST['password'] ?? 'password123';
        $password    = password_hash($passwordRaw !== '' ? $passwordRaw : 'password123', PASSWORD_DEFAULT);
        $role        = $_POST['role'] ?? 'user';

        if ($name && $username) {
            try {
                $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $chk->execute([$username]);
                if ($chk->fetchColumn() > 0) {
                    throw new Exception("Username '{$username}' sudah digunakan!");
                }

                if ($email !== '') {
                    $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                    $chk->execute([$email]);
                    if ($chk->fetchColumn() > 0) {
                        throw new Exception("Email '{$email}' sudah digunakan!");
                    }
                }

                if ($nik !== '') {
                    $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE nik = ?");
                    $chk->execute([$nik]);
                    if ($chk->fetchColumn() > 0) {
                        throw new Exception("NIK '{$nik}' sudah digunakan!");
                    }
                }

                $stmt = $db->prepare("
                    INSERT INTO users (nik, name, email, position, division, department, username, password, role, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $nik !== '' ? $nik : null,
                    $name,
                    $email !== '' ? $email : null,
                    $position !== '' ? $position : null,
                    $division !== '' ? $division : null,
                    $department !== '' ? $department : null,
                    $username,
                    $password,
                    $role
                ]);

                logAudit('edit', 'users', (int)$db->lastInsertId(), $name, "Membuat user baru: {$username}");
                setFlash('success', 'User baru berhasil ditambahkan!');
            } catch (Exception $e) {
                setFlash('danger', 'Gagal menambah user: ' . $e->getMessage());
            }
        } else {
            setFlash('danger', 'Nama dan Username wajib diisi!');
        }
    }

    // ----- EDIT USER (termasuk username + password opsional) -----
    if ($action === 'edit') {
        $id          = (int)($_POST['id'] ?? 0);
        $nik         = trim($_POST['nik'] ?? '');
        $name        = trim($_POST['name'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $position    = $_POST['position'] ?? '';
        $division    = $_POST['division'] ?? '';
        $department  = $_POST['department'] ?? '';
        $username    = trim($_POST['username'] ?? '');
        $passwordRaw = $_POST['password'] ?? '';
        $role        = $_POST['role'] ?? 'user';

        if (!$id || $name === '' || $username === '') {
            setFlash('danger', 'ID, Nama, dan Username wajib diisi!');
            header('Location: ' . BASE_URL . 'modules/users/');
            exit;
        }

        try {
            // Username unik (kecuali milik user ini)
            $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
            $chk->execute([$username, $id]);
            if ($chk->fetchColumn() > 0) {
                throw new Exception("Username '{$username}' sudah digunakan user lain!");
            }

            // Email unik (jika diisi)
            if ($email !== '') {
                $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
                $chk->execute([$email, $id]);
                if ($chk->fetchColumn() > 0) {
                    throw new Exception("Email '{$email}' sudah digunakan user lain!");
                }
            }

            // NIK unik (jika diisi)
            if ($nik !== '') {
                $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE nik = ? AND id != ?");
                $chk->execute([$nik, $id]);
                if ($chk->fetchColumn() > 0) {
                    throw new Exception("NIK '{$nik}' sudah digunakan user lain!");
                }
            }

            // Update data pokok + username
            $stmt = $db->prepare("
                UPDATE users
                SET nik = ?, name = ?, email = ?, position = ?, division = ?, department = ?, username = ?, role = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $nik !== '' ? $nik : null,
                $name,
                $email !== '' ? $email : null,
                $position !== '' ? $position : null,
                $division !== '' ? $division : null,
                $department !== '' ? $department : null,
                $username,
                $role,
                $id
            ]);

            // Update password hanya jika diisi
            if ($passwordRaw !== '') {
                if (strlen($passwordRaw) < 6) {
                    throw new Exception('Password baru minimal 6 karakter!');
                }
                $hash = password_hash($passwordRaw, PASSWORD_DEFAULT);
                $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $id]);
            }

            // Jika yang diedit adalah user yang sedang login, sinkronkan session
            if ($id === (int)$_SESSION['user_id']) {
                $_SESSION['username']   = $username;
                $_SESSION['user_name']  = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['position']   = $position;
                $_SESSION['division']   = $division;
                $_SESSION['department'] = $department;
                $_SESSION['role']       = $role;
            }

            logAudit('edit', 'users', $id, $name, "Memperbarui user: {$username}" . ($passwordRaw !== '' ? ' (+ganti password)' : ''));
            setFlash('success', 'Data user berhasil diperbarui!' . ($passwordRaw !== '' ? ' Password juga diganti.' : ''));
        } catch (Exception $e) {
            setFlash('danger', 'Gagal memperbarui: ' . $e->getMessage());
        }
    }

    // ----- TOGGLE STATUS -----
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['user_id']) {
            setFlash('danger', 'Anda tidak dapat menonaktifkan akun sendiri!');
        } elseif ($id > 0) {
            $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            logAudit('edit', 'users', $id, null, "Toggle status aktif user ID: {$id}");
            setFlash('success', 'Status user berhasil diubah!');
        }
    }

    // ----- RESET PASSWORD -----
    if ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $newPass = password_hash('password123', PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newPass, $id]);
            logAudit('edit', 'users', $id, null, "Reset password user ID: {$id}");
            setFlash('success', 'Password berhasil direset menjadi: password123');
        }
    }

    header('Location: ' . BASE_URL . 'modules/users/');
    exit;
}

// ============================================
// DATA TAMPILAN
// ============================================
$users = $db->query("SELECT * FROM users ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="card mb-3">
    <div class="card-header">
        <h3><i class="fas fa-users-cog"></i> Kelola Pengguna</h3>
        <button type="button" class="btn btn-primary" data-modal="#modal-add-user">
            <i class="fas fa-plus"></i> Tambah User
        </button>
    </div>
    <div class="card-body" style="padding:0;">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>NIK</th>
                        <th>Nama</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Divisi</th>
                        <th>Bidang</th>
                        <th>Jabatan</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="10" class="text-center text-muted" style="padding:40px;">Belum ada pengguna.</td>
                </tr>
                <?php endif; ?>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td style="font-size:12px;"><?= htmlspecialchars($u['nik'] ?? '-') ?></td>
                    <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                    <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($u['email'] ?? '-') ?></td>
                    <td><span class="badge badge-info"><?= htmlspecialchars($u['department'] ?? '-') ?></span></td>
                    <td><span class="badge badge-primary"><?= htmlspecialchars($u['division'] ?? '-') ?></span></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($u['position'] ?? '-') ?></td>
                    <td>
                        <span class="badge <?= $u['role'] === 'admin' ? 'badge-danger' : 'badge-secondary' ?>">
                            <?= ucfirst($u['role']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= $u['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                            <?= $u['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                        </span>
                    </td>
                    <td>
                        <div class="btn-group">
                            <button type="button" class="btn btn-warning btn-sm" title="Edit User"
                                onclick="openEditUser(
                                    <?= (int)$u['id'] ?>,
                                    '<?= htmlspecialchars($u['nik'] ?? '', ENT_QUOTES) ?>',
                                    '<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>',
                                    '<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES) ?>',
                                    '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>',
                                    '<?= htmlspecialchars($u['department'] ?? '', ENT_QUOTES) ?>',
                                    '<?= htmlspecialchars($u['division'] ?? '', ENT_QUOTES) ?>',
                                    '<?= htmlspecialchars($u['position'] ?? '', ENT_QUOTES) ?>',
                                    '<?= htmlspecialchars($u['role'], ENT_QUOTES) ?>'
                                )">
                                <i class="fas fa-edit"></i>
                            </button>

                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button type="submit"
                                    class="btn btn-<?= $u['is_active'] ? 'secondary' : 'success' ?> btn-sm"
                                    title="<?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                    <i class="fas fa-<?= $u['is_active'] ? 'ban' : 'check' ?>"></i>
                                </button>
                            </form>

                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Reset password user ini menjadi password123?')">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="btn btn-info btn-sm" title="Reset Password">
                                    <i class="fas fa-key"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ===================== MODAL TAMBAH USER ===================== -->
<div class="modal-overlay" id="modal-add-user">
    <div class="modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> Tambah Pengguna Baru</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('modal-add-user').classList.remove('show')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>NIK</label>
                        <input type="text" name="nik" class="form-control" maxlength="20" placeholder="Contoh: 2024001">
                    </div>
                    <div class="form-group">
                        <label class="required">Nama Lengkap</label>
                        <input type="text" name="name" class="form-control" required placeholder="Nama lengkap">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Username</label>
                        <input type="text" name="username" class="form-control" required placeholder="username_login" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" placeholder="user@domain.com">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Password</label>
                        <input type="text" name="password" class="form-control" value="password123" autocomplete="new-password">
                        <div class="form-text">Default: password123</div>
                    </div>
                    <div class="form-group">
                        <label class="required">Role</label>
                        <select name="role" class="form-control" required>
                            <option value="user">User</option>
                            <option value="op_arsip">Arsip Manajer</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Divisi</label>
                        <select name="department" class="form-control">
                            <option value="">-- Pilih --</option>
                            <?php foreach (DEPARTMENTS as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Bidang</label>
                        <select name="division" class="form-control">
                            <option value="">-- Pilih --</option>
                            <?php foreach (DIVISIONS as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Jabatan</label>
                    <select name="position" class="form-control">
                        <option value="">-- Pilih --</option>
                        <?php foreach (POSITIONS as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-add-user').classList.remove('show')">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan User</button>
            </div>
        </form>
    </div>
</div>

<!-- ===================== MODAL EDIT USER ===================== -->
<div class="modal-overlay" id="modal-edit-user">
    <div class="modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3><i class="fas fa-user-edit"></i> Edit Pengguna</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('modal-edit-user').classList.remove('show')">&times;</button>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_user_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>NIK</label>
                        <input type="text" name="nik" id="edit_user_nik" class="form-control" maxlength="20">
                    </div>
                    <div class="form-group">
                        <label class="required">Nama Lengkap</label>
                        <input type="text" name="name" id="edit_user_name" class="form-control" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Username</label>
                        <input type="text" name="username" id="edit_user_username" class="form-control" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="edit_user_email" class="form-control">
                    </div>
                </div>

                <!-- Password opsional -->
                <div style="background:var(--light); padding:14px 16px; border-radius:var(--radius); border:1px solid var(--border); margin-bottom:16px;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label><i class="fas fa-key"></i> Password Baru (Opsional)</label>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <input type="password" name="password" id="edit_user_password" class="form-control"
                                   placeholder="Kosongkan jika tidak ingin mengganti password"
                                   autocomplete="new-password" minlength="6">
                            <button type="button" class="btn btn-outline btn-sm" title="Tampilkan/Sembunyikan"
                                    onclick="toggleEditPassword()">
                                <i class="fas fa-eye" id="edit_pw_icon"></i>
                            </button>
                        </div>
                        <div class="form-text">Minimal 6 karakter. Biarkan kosong agar password lama tetap dipakai.</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Divisi</label>
                        <select name="department" id="edit_user_dept" class="form-control">
                            <option value="">-- Pilih --</option>
                            <?php foreach (DEPARTMENTS as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Bidang</label>
                        <select name="division" id="edit_user_div" class="form-control">
                            <option value="">-- Pilih --</option>
                            <?php foreach (DIVISIONS as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Jabatan</label>
                        <select name="position" id="edit_user_pos" class="form-control">
                            <option value="">-- Pilih --</option>
                            <?php foreach (POSITIONS as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="required">Role</label>
                        <select name="role" id="edit_user_role" class="form-control" required>
                            <option value="user">User</option>
                            <option value="op_arsip">Arsip Manajer</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('modal-edit-user').classList.remove('show')">Batal</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditUser(id, nik, name, email, username, dept, div, pos, role) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_user_nik').value = nik || '';
    document.getElementById('edit_user_name').value = name || '';
    document.getElementById('edit_user_email').value = email || '';
    document.getElementById('edit_user_username').value = username || '';
    document.getElementById('edit_user_dept').value = dept || '';
    document.getElementById('edit_user_div').value = div || '';
    document.getElementById('edit_user_pos').value = pos || '';
    document.getElementById('edit_user_role').value = role || 'user';

    // Selalu kosongkan password saat buka modal
    var pw = document.getElementById('edit_user_password');
    pw.value = '';
    pw.type = 'password';
    var icon = document.getElementById('edit_pw_icon');
    if (icon) {
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }

    document.getElementById('modal-edit-user').classList.add('show');
}

function toggleEditPassword() {
    var pw = document.getElementById('edit_user_password');
    var icon = document.getElementById('edit_pw_icon');
    if (!pw) return;
    if (pw.type === 'password') {
        pw.type = 'text';
        if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
    } else {
        pw.type = 'password';
        if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>