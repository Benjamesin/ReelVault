<?php
require_once __DIR__ . '/config.php';

$path = $_GET['path'] ?? '';
if (empty($path)) {
    http_response_code(400);
    exit(json_encode([]));
}

$path = preg_replace('#/+#', '/', str_replace('\\', '/', $path));
if ($path !== '/') {
    $path = rtrim($path, '/');
}

if (!is_allowed_remote_path($path)) {
    http_response_code(403);
    exit(json_encode([]));
}

$realPath = @realpath($path);
if ($realPath === false || !is_dir($realPath)) {
    http_response_code(404);
    exit(json_encode([]));
}

$files = [];
foreach (scandir($realPath) ?: [] as $item) {
    if ($item === '.' || $item === '..') continue;
    if (strcasecmp($item, '720p') === 0 || strcasecmp($item, 'stills') === 0) continue;

    $fullPath = $realPath . DIRECTORY_SEPARATOR . $item;
    $files[] = [
        'name'   => $item,
        'is_dir' => is_dir($fullPath)
    ];
}

header('Content-Type: application/json');
echo json_encode($files);