<?php
require_once __DIR__ . '/config.php';
header('Access-Control-Allow-Origin: *');
header('Content-Type: text/vtt; charset=utf-8');

$file = $_GET['file'] ?? '';
if (empty($file)) {
    http_response_code(400);
    echo "WEBVTT\n\nNOTE Missing file parameter.";
    exit;
}

$file = preg_replace('#/+#', '/', str_replace('\\', '/', $file));
if (str_contains($file, '..') || !str_ends_with(strtolower($file), '.vtt')) {
    http_response_code(403);
    echo "WEBVTT\n\nNOTE Access denied.";
    exit;
}

$candidates = [
    REMOTE_BASE_DIR . '/' . ltrim($file, '/'),
    REMOTE_VIDEOS_DIR . '/' . ltrim($file, '/'),
    REMOTE_SHOWS_DIR . '/' . ltrim($file, '/')
];

foreach ($candidates as $subPath) {
    if (file_exists($subPath) && is_readable($subPath) && is_allowed_remote_path($subPath)) {
        http_response_code(200);
        while (ob_get_level()) ob_end_clean();
        readfile($subPath);
        exit;
    }
}

http_response_code(404);
echo "WEBVTT\n\nNOTE Subtitle file not found.";
exit;