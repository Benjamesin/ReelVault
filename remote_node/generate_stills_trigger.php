<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$logFile = __DIR__ . '/stills_trigger_log.txt';
$log = fn(string $msg) => @file_put_contents($logFile, date('Y-m-d H:i:s') . ' | ' . $msg . "\n", FILE_APPEND);

$path = $_GET['path'] ?? $_GET['video'] ?? '';
if (empty($path)) {
    echo json_encode(['error' => 'No path']);
    exit;
}

$parsedPath = parse_url($path, PHP_URL_PATH) ?: $path;
$parsedPath = preg_replace('#^/remote-(api|videos)/?#', '', $parsedPath);

$candidates = [
    $parsedPath,
    REMOTE_BASE_DIR . '/' . ltrim($parsedPath, '/'),
    ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/' . ltrim($parsedPath, '/'),
];

$videoPath = false;
foreach ($candidates as $cand) {
    if (file_exists($cand)) {
        $videoPath = realpath($cand);
        break;
    }
}

if (!$videoPath || !is_allowed_remote_path($videoPath)) {
    $log("ERROR: Invalid or missing file ($parsedPath)");
    echo json_encode(['error' => 'File not found or unauthorized']);
    exit;
}

$stillsDir = dirname($videoPath) . '/stills/' . pathinfo($videoPath, PATHINFO_FILENAME);
if (is_dir($stillsDir) && !empty(glob($stillsDir . '/*.jpg'))) {
    echo json_encode(['status' => 'already_exists']);
    exit;
}

if (!is_dir($stillsDir) && !mkdir($stillsDir, 0775, true) && !is_dir($stillsDir)) {
    echo json_encode(['error' => 'Permission denied creating directory']);
    exit;
}

$ffmpegLog  = __DIR__ . '/ffmpeg_log.txt';
$outputPath = $stillsDir . '/still_%03d.jpg';
$cmd = sprintf(
    'ffmpeg -i %s -vf "fps=1/10,scale=160:90" -q:v 4 %s -y > %s 2>&1 &',
    escapeshellarg($videoPath),
    escapeshellarg($outputPath),
    escapeshellarg($ffmpegLog)
);
exec($cmd);

echo json_encode(['status' => 'generating']);