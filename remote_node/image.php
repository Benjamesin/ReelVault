<?php
require_once __DIR__ . '/config.php';
header('Access-Control-Allow-Origin: *');

$webRoot = str_replace('\\', '/', __DIR__) . '/';
$image   = $_GET["image"] ?? '';
if (empty($image)) {
    header("HTTP/1.1 404 Not Found");
    exit;
}

$normalizedPath = preg_replace('#/+#', '/', str_replace('\\', '/', $image));
$normalizedPath = str_replace('/mnt/network_share/', REMOTE_BASE_DIR . '/', $normalizedPath);

if (str_contains($normalizedPath, '..')) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access Denied");
}

if (!str_starts_with($normalizedPath, '/')) {
    $normalizedPath = $webRoot . $normalizedPath;
}

// Case-Sensitivity Fallback for folderIcon.png / foldericon.png
if (basename($normalizedPath) === 'foldericon.png' && !file_exists($normalizedPath)) {
    $altPath = dirname($normalizedPath) . '/folderIcon.png';
    if (file_exists($altPath)) $normalizedPath = $altPath;
} elseif (basename($normalizedPath) === 'folderIcon.png' && !file_exists($normalizedPath)) {
    $altPath = dirname($normalizedPath) . '/foldericon.png';
    if (file_exists($altPath)) $normalizedPath = $altPath;
}

if (!file_exists($normalizedPath) || !is_readable($normalizedPath)) {
    header("HTTP/1.1 404 Not Found");
    exit;
}

if (!is_allowed_remote_path($normalizedPath, true)) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access Denied");
}

$extension    = strtolower(pathinfo($normalizedPath, PATHINFO_EXTENSION));
$contentTypes = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',  'gif'  => 'image/gif',
    'webp' => 'image/webp'
];

if (!isset($contentTypes[$extension])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Invalid File Type");
}

$mtime       = filemtime($normalizedPath);
$size        = filesize($normalizedPath);
$etag        = md5($mtime . $size);
$ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') : '';

if ($ifNoneMatch === $etag) {
    header("HTTP/1.1 304 Not Modified");
    exit;
}

header("HTTP/1.1 200 OK");
header("ETag: \"$etag\"");
header("Content-Type: " . $contentTypes[$extension]);
header("Content-Length: " . $size);
while (ob_get_level()) ob_end_clean();
readfile($normalizedPath);
exit;