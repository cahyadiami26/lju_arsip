<?php
// FILE: modules/users/profile.php
require_once __DIR__ . '/../../config/database.php';
requireLogin();

$db = getDB();
$user = currentUser();

$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$userData = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nik        = trim($_POST['nik'] ?? '');
    $name       = trim($_POST['name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $position   = $_POST['position'] ?? '';
    $division   = $_POST['division'] ?? '';
    $department = $_POST['department'] ?? '';
    $username   = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (empty($name) || empty($username)) {
        setFlash('danger', 'Nama dan Username wajib diisi!');
        header('Location: ' . BASE_URL . 'modules/users/profile.php');
        exit;
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $user['id']]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("Username '{$username}' sudah digunakan!");
        }

        if ($email) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user['id']]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Email '{$email}' sudah digunakan!");
            }
        }

        $avatarName = $userData['avatar'];
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedAvatars = ['jpg', 'jpeg', 'png', 'gif'];

            if (!in_array($ext, $allowedAvatars)) {
                throw new Exception("Format avatar tidak valid! Hanya: " . implode(', ', $allowedAvatars));
            }
            if ($file['size'] > 2 * 1024 * 1024) {
                throw new Exception("Ukuran avatar maksimal 2MB!");
            }

            if (!is_dir(AVATAR_DIR)) mkdir(AVATAR_DIR, 0755, true);

            if (!empty($userData['avatar']) && file_exists(AVATAR_DIR . $userData['avatar'])) {
                unlink(AVATAR_DIR . $userData['avatar']);
            }

            $avatarName = 'avatar_' . $user['id'] . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], AVATAR_DIR . $avatarName)) {
                throw new Exception("Gagal menyimpan avatar.");
            }
        }

        $sql = "UPDATE users SET nik=?, name=?, email=?, position=?, division=?, department=?, username=?, avatar=? WHERE id=?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$nik ?: null, $name, $email ?: null, $position, $division, $department, $username, $avatarName, $user['id']]);

        if (!empty($password)) {
            $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }

        $db->commit();

        $_SESSION['user_name']  = $name;
        $_SESSION['user_email'] = $email;
        $_SESSION['position']   = $position;
        $_SESSION['division']   = $division;
        $_SESSION['department'] = $department;
        $_SESSION['avatar']     = $avatarName;

        logAudit('edit', 'users', $user['id'], $name, 'Memperbarui profil');
        setFlash('success', 'Profil berhasil diperbarui!');
        header('Location: ' . BASE_URL . 'modules/users/profile.php');
        exit;

    } catch (Exception $e) {
        $db->rollBack();
        setFlash('danger', $e->getMessage());
        header('Location: ' . BASE_URL . 'modules/users/profile.php');
        exit;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <div class="card-header">
        <h3><i class="fas fa-user-edit"></i> Ubah Profil Saya</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">

            <!-- Avatar -->
            <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--border);">
                <div style="width: 100px; height: 100px; border-radius: 50%; overflow: hidden; background: var(--primary); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 36px; font-weight: bold; flex-shrink: 0; border: 3px solid var(--border);">
                    <?php 
                    $avatarPath = AVATAR_URL . $userData['avatar'];
                    $avatarFilePath = AVATAR_DIR . $userData['avatar'];
                    if (!empty($userData['avatar']) && file_exists($avatarFilePath)): 
                    ?>
                        <img src="<?= AVATAR_URL ?><?= htmlspecialchars($userData['avatar']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        <?= strtoupper(substr($userData['name'], 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <label style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">Foto Profil (Avatar)</label>
                    <input type="file" name="avatar" accept="image/png, image/jpeg, image/jpg, image/gif" style="font-size: 13px;">
                    <p style="font-size: 11px; color: var(--secondary); margin-top: 4px;">Format: JPG, PNG, GIF. Maks: 2MB</p>
                </div>
            </div>

            <!-- Identitas -->
            <div class="form-row">
                <div class="form-group">
                    <label>NIK</label>
                    <input type="text" name="nik" class="form-control" value="<?= htmlspecialchars($userData['nik'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="required">Nama Lengkap</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($userData['name']) ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($userData['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="required">Username</label>
                    <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($userData['username']) ?>" required>
                </div>
            </div>

            <!-- Struktur Organisasi -->
            <div class="form-row">
                <div class="form-group">
                    <label class="required">Departemen</label>
                    <select name="department" class="form-control" required>
                        <option value="">-- Pilih Departemen --</option>
                        <?php foreach (DEPARTMENTS as $dept): ?>
                        <option value="<?= $dept ?>" <?= $userData['department'] == $dept ? 'selected' : '' ?>><?= $dept ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="required">Divisi</label>
                    <select name="division" class="form-control" required>
                        <option value="">-- Pilih Divisi --</option>
                        <?php foreach (DIVISIONS as $div): ?>
                        <option value="<?= $div ?>" <?= $userData['division'] == $div ? 'selected' : '' ?>><?= $div ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="required">Jabatan</label>
                    <select name="position" class="form-control" required>
                        <?php foreach (POSITIONS as $pos): ?>
                        <option value="<?= $pos ?>" <?= $userData['position'] == $pos ? 'selected' : '' ?>><?= $pos ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Password -->
            <div style="background:var(--light); padding:16px; border-radius:var(--radius); border:1px solid var(--border); margin-top:20px;">
                <h4 style="font-size:13px; margin-bottom:12px;"><i class="fas fa-key"></i> Ganti Password (Opsional)</h4>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Password Baru</label>
                    <input type="password" name="password" class="form-control" placeholder="Kosongkan jika tidak ingin mengganti">
                </div>
            </div>

            <div style="margin-top:24px; display:flex; gap:12px;">
                <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Simpan Perubahan</button>
                <a href="<?= BASE_URL ?>modules/dashboard/" class="btn btn-outline btn-lg">Batal</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>