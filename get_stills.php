<?php
// get_stills.php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$localPort   = $_SERVER['SERVER_PORT'] ?? '80';
$apiPrefix   = rtrim(REMOTE_API_PREFIX, '/');
$vidPrefix   = rtrim(REMOTE_VIDEOS_PREFIX, '/');
$remoteMount = REMOTE_VIDEOS_DIR !== ''
    ? rtrim(dirname(rtrim(REMOTE_VIDEOS_DIR, '/')), '/')
    : (REMOTE_SHOWS_DIR !== '' ? rtrim(dirname(rtrim(REMOTE_SHOWS_DIR, '/')), '/') : '');

$logFile = __DIR__ . '/get_stills_log.txt';
$log = function(string $msg) use ($logFile) {
    @file_put_contents($logFile, date('Y-m-d H:i:s') . ' | ' . $msg . "\n", FILE_APPEND);
};

$video = $_GET['video'] ?? '';
$log("START | video=" . $video);

if (empty($video)) {
    $log("EXIT | no video param");
    echo json_encode(['stills' => []]);
    exit;
}

$is_remote = REMOTE_ENABLED && (
    str_starts_with($video, 'http://') ||
    str_starts_with($video, 'https://') ||
    str_starts_with($video, '/remote-') ||
    ($apiPrefix !== '' && str_starts_with($video, $apiPrefix . '/')) ||
    ($vidPrefix !== '' && str_starts_with($video, $vidPrefix . '/')) ||
    (REMOTE_HOST !== '' && str_contains($video, REMOTE_HOST))
);

$log("is_remote=" . ($is_remote ? 'true' : 'false'));

if ($is_remote && $remoteMount !== '') {
    // --- REMOTE LOGIC (Via Reverse Proxy) ---
    $cleanVideo = $video;

    if (str_starts_with($cleanVideo, 'http://') || str_starts_with($cleanVideo, 'https://')) {
        $cleanVideo = parse_url($cleanVideo, PHP_URL_PATH) ?? '';
    }

    $cleanVideo = preg_replace('#^(' . preg_quote($apiPrefix, '#') . '|/remote-api)/?#', '', $cleanVideo);
    $cleanVideo = preg_replace('#^(' . preg_quote($vidPrefix, '#') . '|/remote-videos)/?#', 'videos/', $cleanVideo);
    $cleanVideo = ltrim($cleanVideo, '/');

    $remoteFsPath = $remoteMount . '/' . $cleanVideo;
    $remoteUrl    = "http://127.0.0.1:{$localPort}{$apiPrefix}/get_stills.php?path=" . urlencode($remoteFsPath);
    $log("remoteFsPath=$remoteFsPath | remoteUrl=$remoteUrl");

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $remoteUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $log("curl httpCode=$httpCode | curlError=" . ($curlError ?: 'none'));

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);

        if (!empty($data['stills']) && is_array($data['stills'])) {
            $data['stills'] = array_map(function($url) use ($apiPrefix) {
                $parsed = parse_url($url);
                $pathAndQuery = ($parsed['path'] ?? '') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
                return $apiPrefix . '/' . ltrim($pathAndQuery, '/');
            }, $data['stills']);
            $log("rewrote " . count($data['stills']) . " still URLs to {$apiPrefix}/");
        }

        if (!empty($data['sprites']) && is_array($data['sprites'])) {
            foreach ($data['sprites'] as &$sprite) {
                if (isset($sprite['url'])) {
                    $parsed = parse_url($sprite['url']);
                    $pathAndQuery = ($parsed['path'] ?? '') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
                    $sprite['url'] = $apiPrefix . '/' . ltrim($pathAndQuery, '/');
                }
            }
            $log("rewrote sprite URLs to {$apiPrefix}/");
        }

        echo json_encode($data ?: ['stills' => []]);
    } else {
        $log("EXIT | curl failed or bad status");
        echo json_encode(['stills' => []]);
    }
    exit;

} else {
    // --- LOCAL LOGIC ---
    $videoFsPath   = realpath($video) ?: $video;
    $videoFilename = pathinfo($videoFsPath, PATHINFO_FILENAME);
    $stillsDir     = dirname($videoFsPath) . '/stills/' . $videoFilename;
    $log("LOCAL | videoFsPath=$videoFsPath | stillsDir=$stillsDir");

    if (!is_dir($stillsDir)) {
        $log("EXIT | stillsDir does not exist");
        echo json_encode(['stills' => []]);
        exit;
    }

    $files = glob($stillsDir . '/still_*.{jpg,jpeg,png}', GLOB_BRACE);
    if (!$files) {
        $files = glob($stillsDir . '/still_*.jpg');
    }

    if (!$files) {
        $log("EXIT | no stills found");
        echo json_encode(['stills' => []]);
        exit;
    }

    natsort($files);
    $stillsUrls = array_values(array_map(
        fn($f) => "image.php?image=" . urlencode($f),
        $files
    ));
    $log("SUCCESS | returning " . count($stillsUrls) . " stills");

    echo json_encode(['stills' => $stillsUrls]);
    exit;
}