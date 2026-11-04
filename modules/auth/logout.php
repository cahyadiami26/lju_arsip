<?php
// FILE: modules/auth/logout.php
require_once __DIR__ . '/../../config/database.php';

if (isLoggedIn()) {
    logAudit('logout', 'auth', null, null, 'Logout');
}

session_destroy();
header('Location: https://dms.lju.co.id');
exit;