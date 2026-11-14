<?php
require_once __DIR__ . '/config.php';

$file = $_GET['file'] ?? '';
if (empty($file)) {
    http_response_code(400);
    exit('No file specified');
}

if (!is_allowed_remote_path($file)) {
    http_response_code(403);
    exit('Access denied');
}

$realFile = realpath(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file));
if (!$realFile || !file_exists($realFile)) {
    http_response_code(404);
    exit('File not found');
}

header('Content-Type: text/plain');
readfile($realFile);