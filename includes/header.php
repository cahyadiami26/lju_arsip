<?php
// FILE: includes/header.php
require_once __DIR__ . '/../config/database.php';
requireLogin();
$currentUser = currentUser();
$notifications = getUnreadNotifications($currentUser['id']);
$notifCount = count($notifications);

// Determine active page
$currentPage = basename(dirname($_SERVER['SCRIPT_NAME']));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title>ARSIP-LJU | Electronic Document Archive Management System</title>
    <link rel="icon" type="image/x-icon" href="../../favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="app-wrapper">