<?php
require_once __DIR__ . '/config.php';
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$showsDir = REMOTE_SHOWS_DIR;
$shows    = [];

if (is_dir($showsDir)) {
    $files = scandir($showsDir) ?: [];
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && is_dir($showsDir . '/' . $file) && strcasecmp($file, 'ShowsArchived') !== 0) {
            $hasIcon = file_exists($showsDir . '/' . $file . '/folderIcon.png') ||
                       file_exists($showsDir . '/' . $file . '/foldericon.png');
            $shows[] = [
                'name'           => $file,
                'has_foldericon' => $hasIcon
            ];
        }
    }
}

echo json_encode($shows);