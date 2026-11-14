<?php
// get_subtitle.php
require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Content-Type: text/vtt; charset=utf-8');

$file = $_GET['file'] ?? '';

if (empty($file)) {
    http_response_code(400);
    echo "WEBVTT\n\nNOTE Missing file parameter.";
    exit;
}

// Security: Reject directory traversal and non-.vtt requests
if (str_contains($file, '..') || !str_ends_with(strtolower($file), '.vtt')) {
    http_response_code(403);
    echo "WEBVTT\n\nNOTE Access denied.";
    exit;
}

$localPort       = $_SERVER['SERVER_PORT'] ?? '80';
$localVideosDir  = rtrim(LOCAL_VIDEOS_DIR, '/') . '/';
$localShowsDir   = rtrim(LOCAL_SHOWS_DIR, '/') . '/';
$webRoot         = __DIR__ . '/';
$apiPrefix       = rtrim(REMOTE_API_PREFIX, '/');

// ------------------------------------------------------------------
// 1. Check if the subtitle exists locally
// ------------------------------------------------------------------
$localCandidates = [
    $webRoot . ltrim($file, '/'),
    $localVideosDir . ltrim(preg_replace('#^videos/?#', '', $file), '/'),
    $localShowsDir . ltrim(preg_replace('#^shows/?#', '', $file), '/'),
];

foreach ($localCandidates as $candidate) {
    if (file_exists($candidate) && is_readable($candidate)) {
        http_response_code(200);
        while (ob_get_level()) {
            ob_end_clean();
        }
        readfile($candidate);
        exit;
    }
}

// ------------------------------------------------------------------
// 2. If not local, fetch from secondary node via reverse proxy
// ------------------------------------------------------------------
if (REMOTE_ENABLED) {
    $remoteApiUrl = "http://127.0.0.1:{$localPort}{$apiPrefix}/subtitle.php?file=" . rawurlencode($file);

    $ch = curl_init($remoteApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response !== false) {
        http_response_code(200);
        while (ob_get_level()) {
            ob_end_clean();
        }
        echo $response;
        exit;
    }
}

// ------------------------------------------------------------------
// 3. Fallback: Not Found
// ------------------------------------------------------------------
http_response_code(404);
echo "WEBVTT\n\nNOTE Subtitle not found.";
exit;