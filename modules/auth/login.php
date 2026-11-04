<?php
// FILE: modules/auth/login.php
require_once __DIR__ . '/../../config/database.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'modules/dashboard/');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['division'] = $user['division'];
            $_SESSION['position'] = $user['position'];
            $_SESSION['avatar'] = $user['avatar'];

            logAudit('login', 'auth', null, null, 'Login berhasil');

            header('Location: ' . BASE_URL . 'modules/dashboard/');
            exit;
        } else {
            $error = 'Username atau password salah!';
        }
    } else {
        $error = 'Harap isi username dan password!';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - E-DAMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="logo">
            <i class="fas fa-archive" style="font-size:48px; color: var(--primary); margin-bottom:12px; display:block;"></i>
            <h2>E-DAMS</h2>
            <small style="color: var(--secondary);">Electronic Document Archive Management System</small>
        </div>

        <h1 style="text-align:center;">Masuk</h1>
        <p class="subtitle" style="text-align:center;">Silakan masuk untuk mengakses sistem</p>

        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label><i class="fas fa-user"></i> Username</label>
                <input type="text" name="username" class="form-control" placeholder="Masukkan username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label><i class="fas fa-lock"></i> Password</label>
                <input type="password" name="password" class="form-control" placeholder="Masukkan password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100" style="justify-content:center;">
                <i class="fas fa-sign-in-alt"></i> Masuk
            </button>
        </form>

        <div style="text-align:center; margin-top:20px; font-size:12px; color:var(--secondary);">
            Default: admin / password
        </div>
    </div>
</div>
</body>
</html>