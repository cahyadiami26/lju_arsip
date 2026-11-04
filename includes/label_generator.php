<?php
// FILE: includes/label_generator.php

// Pastikan lokasi qrlib.php sesuai dengan direktori Anda
require_once __DIR__ . '/../vendor/phpqrcode/qrlib.php';

function generateDocumentLabel($docId, $archiveNumber) {
    $labelDir = __DIR__ . '/../uploads/images/labels/';
    if (!is_dir($labelDir)) {
        mkdir($labelDir, 0755, true);
    }

    $fileName = 'label_' . $docId . '.png';
    $outputPath = $labelDir . $fileName;
    
    // 1. URL yang akan dimasukkan ke dalam QR Code
    // Sesuaikan host dengan domain/ip lokal Anda
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $qrUrl = $protocol . "://" . $host . BASE_URL . "scan.php?id=" . $docId;
    
    // 2. Generate QR Code ke file temporary
    $tempQr = $labelDir . 'temp_qr_' . $docId . '.png';
    QRcode::png($qrUrl, $tempQr, QR_ECLEVEL_L, 6, 2);
    
    // 3. Setup Canvas Label Fisik (Sesuai Gambar Referensi)
    $width = 600;
    $height = 400;
    $img = imagecreatetruecolor($width, $height);
    
    // Warna
    $bgColor = imagecolorallocate($img, 142, 162, 175); // Abu-abu kebiruan
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    
    // Isi background
    imagefill($img, 0, 0, $bgColor);
    
    // Gambar Border Putih membulat (simulasi manual dengan ketebalan 10px)
    imagesetthickness($img, 8);
    // Draw outer rounded rectangle (GD workaround)
    $radius = 40;
    $padding = 15;
    // Corners
    imagearc($img, $padding + $radius, $padding + $radius, $radius*2, $radius*2, 180, 270, $white);
    imagearc($img, $width - $padding - $radius, $padding + $radius, $radius*2, $radius*2, 270, 360, $white);
    imagearc($img, $width - $padding - $radius, $height - $padding - $radius, $radius*2, $radius*2, 0, 90, $white);
    imagearc($img, $padding + $radius, $height - $padding - $radius, $radius*2, $radius*2, 90, 180, $white);
    // Lines
    imageline($img, $padding + $radius, $padding, $width - $padding - $radius, $padding, $white);
    imageline($img, $padding + $radius, $height - $padding, $width - $padding - $radius, $height - $padding, $white);
    imageline($img, $padding, $padding + $radius, $padding, $height - $padding - $radius, $white);
    imageline($img, $width - $padding, $padding + $radius, $width - $padding, $height - $padding - $radius, $white);
    imagesetthickness($img, 1);

    // 4. Tambahkan Teks (Gunakan font Arial)
    $fontPath = __DIR__ . '/../assets/fonts/arial.ttf';
    
    if (file_exists($fontPath)) {
        // Judul
        imagettftext($img, 20, 0, 110, 70, $black, $fontPath, "Arsip PT. Lampung Jasa Utama");
        // Label Nomor
        imagettftext($img, 18, 0, 50, 115, $black, $fontPath, "Nomor Arsip :");
        // Garis bawah nomor
        imagesetthickness($img, 3);
        imageline($img, 230, 120, 550, 120, $black);
        // Teks Nomor Arsip di atas garis
        imagettftext($img, 16, 0, 240, 110, $black, $fontPath, $archiveNumber);
    } else {
        // Fallback jika font tidak ditemukan
        imagestring($img, 5, 150, 40, "Arsip PT. Lampung Jasa Utama", $black);
        imagestring($img, 5, 50, 90, "Nomor Arsip : " . $archiveNumber, $black);
    }

    // 5. Gabungkan QR Code ke Label
    $qrImg = imagecreatefrompng($tempQr);
    $qrWidth = imagesx($qrImg);
    $qrHeight = imagesy($qrImg);
    
    // Letakkan di tengah bawah
    $dstX = ($width - $qrWidth) / 2;
    $dstY = 150;
    
    // Buat background kotak putih untuk QR code agar rapi
    imagefilledrectangle($img, $dstX - 10, $dstY - 10, $dstX + $qrWidth + 10, $dstY + $qrHeight + 10, $black);
    imagefilledrectangle($img, $dstX - 7, $dstY - 7, $dstX + $qrWidth + 7, $dstY + $qrHeight + 7, $white);
    
    // Copy QR
    imagecopyresampled($img, $qrImg, $dstX, $dstY, 0, 0, $qrWidth, $qrHeight, $qrWidth, $qrHeight);
    
    // 6. Simpan hasil akhir & bersihkan memori
    imagepng($img, $outputPath);
    
    imagedestroy($img);
    imagedestroy($qrImg);
    unlink($tempQr); // hapus temp QR

    return $fileName;
}