<?php
// FILE: index.php
require_once __DIR__ . '/config/database.php';

if (isLoggedIn()) {
    header('Location: https://dms.lju.co.id/arsip/modules/dashboard/');
} else {
    header('Location: https://dms.lju.co.id');
}
exit;