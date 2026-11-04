<?php
// FILE: config/database.php
if (!ob_get_level()) {
    ob_start();
}
define('DB_HOST', 'localhost');
define('DB_NAME', 'ljucoid_dms');
define('DB_USER', 'ljucoid_dms');
define('DB_PASS', 'icikiwir2026');
define('LOGIN_URL', 'https://dms.lju.co.id/arsip');
define('BASE_URL', '/arsip/');
define('AVATAR_URL','/uploads/avatars/');
define('UPLOAD_DIR', __DIR__ . '/../uploads/documents/');
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50MB
define('ALLOWED_TYPES', ['pdf','doc','docx','xls','xlsx','jpg','jpeg','png','gif']);
// Daftar Division & Department (Global)
define('DIVISIONS', [
    'Direksi','Keuangan', 'Pengadaan', 'Keamanan', 'Infokom', 'SDM', 
    'IT', 'Legal', 'Kesekretariatan', 'Jasa Konstruksi', 
    'Pengangkutan & Pergudangan', 'Perdagangan', 'Energi & Migas'
]);

// Daftar department yang diizinkan melihat seluruh dokumen meskipun non-admin
define('specialUsers',['Direksi']);

define('DEPARTMENTS', [
    'Keuangan & Umum', 'Bisnis', 'Sekretariat', 'Pengawas Internal', 'Direksi'
]);

define('POSITIONS', [
    'Direktur Utama', 'Direktur Operasional', 'Kepala Bidang', 
    'Kepala Divisi', 'Manager', 'Sekretaris', 'Staff', 'System Administrator',
    'Corporate Secretary','Sekretaris Direksi','Sekretaris Komisaris'
]);
//define('AVATAR_DIR', __DIR__ . '/uploads/avatars/');
define('AVATAR_DIR', realpath(__DIR__ . '/../../uploads/avatars') . '/');

// Session configuration
// 1. Atur nama session terlebih dahulu
session_name('LJU_APPS');

// 2. Atur cookie params SEBELUM session_start()
ini_set('session.cookie_domain', '.lju.co.id'); // Titik di depan LJU sangat penting!
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '.lju.co.id', // Pastikan domain utama aplikasi Anda benar
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Proteksi Halaman: Jika belum login, redirect ke Portal SSO dengan parameter target
if (empty($_SESSION['user_id'])) {
    $current_url = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    
    // Redirect ke SSO Portal
    header('Location: https://lju.co.id/dashboard/?target_system=' . urlencode('https://dms.lju.co.id/arsip') . '&redirect_after_login=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

// Database connection
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

// Auth helper functions
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        // Logika fungsi isLoggedIn yang sudah ada sebelumnya
        return isset($_SESSION['user_id']); // contoh implementasi standar
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin() {
        if (!isLoggedIn()) {
            header('Location: https://lju.co.id/dashboard/?target_system=' . urlencode('https://dms.lju.co.id/arsip') . '&redirect_after_login=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }
    }
}


function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Cek apakah user memiliki hak mengunggah dokumen dan mengelola hak akses
// (Hanya Admin atau User dengan Department 'Sekretariat')
function canUploadAndManage() {
    if (!isLoggedIn()) return false;
    $user = currentUser();
    //return ($user['role'] === 'admin' || $user['department'] === 'Sekretariat' || $user['division'] === 'Legal');
    return ($user['role'] === 'admin' || $user['role'] === 'op_arsip');
}

if (!function_exists('currentUser')) {
    function currentUser() {
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'username' => $_SESSION['username'] ?? '',
            'name'     => $_SESSION['user_name'] ?? '', // Membaca $_SESSION['user_name']
            'email' => $_SESSION['user_email'] ?? '',
            'role' => $_SESSION['role'] ?? 'user',
            'department' => $_SESSION['department'] ?? '',
            'division' => $_SESSION['division'] ?? '',
            'position' => $_SESSION['position'] ?? '',
            'avatar' => $_SESSION['avatar'] ?? ''
        ];
    }
}

// Audit Trail Logger
function logAudit($action, $module = null, $documentId = null, $documentTitle = null, $details = null) {
    if (!isLoggedIn()) return;
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO audit_trail (user_id, user_name, action, module, document_id, document_title, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['user_id'],
        $_SESSION['username'],
        $action,
        $module,
        $documentId,
        $documentTitle,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
}

// Auto Numbering Generator (Updated with {DIVISION} support)
function generateArchiveNumber($categoryId, $division = '', $department = '') {
    $db = getDB();
    $year = date('Y');
    $month = date('n');

    $stmt = $db->prepare("SELECT code, numbering_format FROM document_categories WHERE id = ?");
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch();
    if (!$category) return 'DOC-' . time();

    $stmt = $db->prepare("INSERT INTO numbering_counters (category_id, year, month, counter) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE counter = counter + 1");
    $stmt->execute([$categoryId, $year, $month]);

    $stmt = $db->prepare("SELECT counter FROM numbering_counters WHERE category_id = ? AND year = ? AND month = ?");
    $stmt->execute([$categoryId, $year, $month]);
    $counter = $stmt->fetchColumn();

    $romanMonths = ['','I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];

    // Mapping Division ke Kode Singkat
    $divMap = [
        'Keuangan'                  => 'KEU',
        'Pengadaan'                 => 'PGD',
        'Keamanan'                  => 'KAM',
        'Infokom'                   => 'INF',
        'SDM'                       => 'SDM',
        'Direksi'                   => 'DIR',
        'IT'                        => 'IT',
        'Legal'                     => 'LGL',
        'Kesekretariatan'           => 'KES',
        'JaKon'                     => 'JKN',
        'Pengangkutan & Pergudangan'=> 'PGK',
        'Perdagangan'               => 'PRD',
        'Energi & Migas'            => 'ENM'
    ];

    // Mapping Department ke Kode Singkat
    $deptMap = [
        'Keuangan & Umum'    => 'KU',
        'Bisnis'             => 'BS',
        'Sekretariat'        => 'SK',
        'Pengawas Internal'  => 'PI',
        'Direksi'            => 'DIR'
    ];

    $divCode  = isset($divMap[$division]) ? $divMap[$division] : strtoupper(substr($division, 0, 3));
    $deptCode = isset($deptMap[$department]) ? $deptMap[$department] : strtoupper(substr($department, 0, 2));

    $format = $category['numbering_format'] ?: '{COUNTER}/{CODE}/{DIVISION}/{DEPARTMENT}/{ROMAN_MONTH}/{YEAR}';

    $number = str_replace(
        ['{COUNTER}', '{CODE}', '{YEAR}', '{MONTH}', '{ROMAN_MONTH}', '{DIVISION}', '{DEPARTMENT}'],
        [
            str_pad($counter, 4, '0', STR_PAD_LEFT),
            $category['code'],
            $year,
            str_pad($month, 2, '0', STR_PAD_LEFT),
            $romanMonths[$month],
            $divCode,
            $deptCode
        ],
        $format
    );

    return $number;
}

function checkSpecialuser() {
    $user = currentUser();
    
    $department = $user['department'] ?? '';
    $division = $user['division'] ?? '';
    
    return in_array($department, specialUsers) || in_array($division, specialUsers);
}

function checkDocPermission($documentId, $permission = 'can_read') {
    if (isAdmin()) return true;
    
    
    $db = getDB();
    $user = currentUser();
    $userId = (int)($user['id'] ?? 0);
    $userDivision = $user['division'] ?? '';
    $userDepartment = $user['department'] ?? '';
    if (in_array($user['department'] ?? '', specialUsers)) return true;
    
    // Ambil data dokumen
    $stmt = $db->prepare("SELECT uploaded_by, division, department, is_confidential FROM documents_arsip WHERE id = ?");
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch();
    if (!$doc) return false;

    // 1. Pemilik dokumen → akses penuh
    if ((int)$doc['uploaded_by'] === $userId) return true;

    // 2. Cek permission spesifik di document_permissions
    //    - user_id langsung
    //    - division saja
    //    - department saja
    //    - division + department
    $stmt = $db->prepare("
        SELECT can_read, can_download, can_edit, can_delete
        FROM document_permissions
        WHERE document_id = ?
          AND (
                (user_id = ?)
             OR (user_id IS NULL AND division = ? AND (department IS NULL OR department = ''))
             OR (user_id IS NULL AND department = ? AND (division IS NULL OR division = ''))
             OR (user_id IS NULL AND division = ? AND department = ?)
          )
    ");
    $stmt->execute([
        $documentId,
        $userId,
        $userDivision,      // match division only
        $userDepartment,    // match department only
        $userDivision,      // match division + department
        $userDepartment
    ]);
    $perms = $stmt->fetchAll();

    if (!empty($perms)) {
        foreach ($perms as $p) {
            if (!empty($p[$permission])) {
                return true;
            }
        }
        // Ada baris permission tapi tidak punya hak yang diminta
        return false;
    }

    // 3. Dokumen rahasia tanpa permission spesifik → tolak (kecuali pemilik/admin, sudah di atas)
    if (!empty($doc['is_confidential'])) {
        return false;
    }

    // 4. Default: user satu department ATAU satu division dengan dokumen
    if (
        ($userDepartment !== '' && $doc['department'] === $userDepartment) ||
        ($userDivision !== '' && $doc['division'] === $userDivision)
    ) {
        // Default hanya read (dan download opsional — sesuaikan kebutuhan)
        if ($permission === 'can_read' || $permission === 'can_download') {
            return true;
        }
        return false; // edit/delete default tidak diizinkan
    }

    return false;
}

// Notification helper
function createNotification($userId, $docId, $type, $title, $message) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO notifications (user_id, document_id, type, title, message) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $docId, $type, $title, $message]);
}

function getUnreadNotifications($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// File size formatter
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// Check expiry alerts (run on dashboard load)
function checkExpiryAlerts() {
    $db = getDB();
    // Find documents expiring soon
    $stmt = $db->prepare("
        SELECT d.*, dc.name as category_name 
        FROM documents_arsip d 
        LEFT JOIN document_categories dc ON d.category_id = dc.id 
        WHERE d.status = 'active' 
        AND d.expiry_date IS NOT NULL 
        AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL d.expiry_alert_days DAY)
        AND d.expiry_notified = 0
    ");
    $stmt->execute();
    $docs = $stmt->fetchAll();
    
    foreach ($docs as $doc) {
        $daysLeft = (strtotime($doc['expiry_date']) - time()) / 86400;
        $daysLeft = ceil($daysLeft);
        
        if ($daysLeft <= 0) {
            $type = 'expiry_expired';
            $title = 'Dokumen Telah Expired';
            $message = "Dokumen \"{$doc['title']}\" ({$doc['category_name']}) telah melewati masa berlaku pada " . date('d/m/Y', strtotime($doc['expiry_date']));
            // Update status
            $db->prepare("UPDATE documents_arsip SET status = 'expired' WHERE id = ?")->execute([$doc['id']]);
        } else {
            $type = 'expiry_warning';
            $title = 'Peringatan Masa Berlaku';
            $message = "Dokumen \"{$doc['title']}\" ({$doc['category_name']}) akan berakhir dalam {$daysLeft} hari (". date('d/m/Y', strtotime($doc['expiry_date'])) .")";
        }
        
        // Notify uploader
        createNotification($doc['uploaded_by'], $doc['id'], $type, $title, $message);
        
        // Notify admins
        $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();
        foreach ($admins as $admin) {
            if ($admin['id'] != $doc['uploaded_by']) {
                createNotification($admin['id'], $doc['id'], $type, $title, $message);
            }
        }
        
        $db->prepare("UPDATE documents_arsip SET expiry_notified = 1 WHERE id = ?")->execute([$doc['id']]);
    }
}

// Flash message
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
?>