<?php
// image.php
require_once __DIR__ . '/config.php';
header('Access-Control-Allow-Origin: *');

$image = $_GET["image"] ?? '';
if (empty($image)) {
    header("HTTP/1.1 404 Not Found");
    exit;
}

// =========================================================================
// 1. REMOTE PROXY LOGIC (Optional Secondary Node)
// =========================================================================
$isRemoteUrl = (
    (REMOTE_ENABLED && REMOTE_HOST !== '' && str_contains($image, REMOTE_HOST)) ||
    str_starts_with($image, 'http://') ||
    str_starts_with($image, 'https://')
);

if ($isRemoteUrl) {
    $remoteUrl = preg_replace('#(?<!:)//+#', '/', $image);

    if (!str_starts_with($remoteUrl, 'http://') && !str_starts_with($remoteUrl, 'https://')) {
        $remoteUrl = 'http://' . ltrim($remoteUrl, '/');
    }

    $ch = curl_init($remoteUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);

    $data        = curl_exec($ch);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $data) {
        $etag        = md5($data);
        $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') : '';
        if ($ifNoneMatch === $etag) {
            header("HTTP/1.1 304 Not Modified");
            exit;
        }

        header("HTTP/1.1 200 OK");
        header("ETag: \"$etag\"");
        header("Content-Type: " . ($contentType ?: 'image/jpeg'));
        header("Content-Length: " . strlen($data));

        while (ob_get_level()) {
            ob_end_clean();
        }

        echo $data;
        exit;
    } else {
        header("HTTP/1.1 404 Not Found");
        exit;
    }
}

// =========================================================================
// 2. LOCAL IMAGE LOGIC
// =========================================================================
$allowedDirs = array_values(array_filter(array_map(
    fn($d) => $d !== '' ? rtrim(str_replace('\\', '/', $d), '/') . '/' : '',
    [LOCAL_VIDEOS_DIR, LOCAL_SHOWS_DIR, SERIES_DIR, REMOTE_VIDEOS_DIR, REMOTE_SHOWS_DIR]
)));
$webRoot = str_replace('\\', '/', __DIR__) . '/';

$normalizedPath = str_replace('\\', '/', $image);
$normalizedPath = preg_replace('#/+#', '/', $normalizedPath);

if (str_contains($normalizedPath, '..')) {
    header("HTTP/1.1 403 Forbidden");
    echo "Access denied (traversal detected).";
    exit;
}

if (!str_starts_with($normalizedPath, '/')) {
    $normalizedPath = $webRoot . $normalizedPath;
}

$isAllowed = str_starts_with($normalizedPath, $webRoot);
if (!$isAllowed) {
    foreach ($allowedDirs as $dir) {
        if (stripos($normalizedPath, $dir) === 0) {
            $isAllowed = true;
            break;
        }
    }
}

if (!$isAllowed) {
    header("HTTP/1.1 403 Forbidden");
    echo "Access denied (unauthorized folder).";
    exit;
}

// Linux Case-Sensitivity Fallback for folderIcon.png / foldericon.png
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

$extension    = strtolower(pathinfo($normalizedPath, PATHINFO_EXTENSION));
$contentTypes = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp'
];

if (!isset($contentTypes[$extension])) {
    header("HTTP/1.1 403 Forbidden");
    echo "Invalid file type.";
    exit;
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

while (ob_get_level()) {
    ob_end_clean();
}

readfile($normalizedPath);
exit;