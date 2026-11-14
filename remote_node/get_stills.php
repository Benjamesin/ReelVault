<?php
require_once __DIR__ . '/config.php';
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$rawPath = $_GET['path'] ?? '';
if (empty($rawPath)) {
    echo json_encode(['stills' => []]);
    exit;
}

$path = preg_replace('#/+#', '/', str_replace('\\', '/', $rawPath));
$videoPath = str_starts_with($path, REMOTE_BASE_DIR) ? $path : REMOTE_BASE_DIR . '/' . ltrim($path, '/');

if (str_contains($videoPath, '..')) {
    echo json_encode(['stills' => []]);
    exit;
}

$videoFilename = pathinfo($videoPath, PATHINFO_FILENAME);
$stillsDir     = dirname($videoPath) . '/stills/' . $videoFilename;

if (!is_dir($stillsDir) || !is_allowed_remote_path($stillsDir)) {
    echo json_encode(['stills' => []]);
    exit;
}

$spritesJson = $stillsDir . '/sprites.json';
if (file_exists($spritesJson)) {
    $data = json_decode(file_get_contents($spritesJson), true);
    if ($data) {
        if (!empty($data['sprites']) && is_array($data['sprites'])) {
            foreach ($data['sprites'] as &$sprite) {
                if (isset($sprite['file'])) {
                    $sprite['url'] = "image.php?image=" . urlencode($stillsDir . '/' . $sprite['file']);
                }
            }
        }
        echo json_encode($data);
        exit;
    }
}

$files = glob($stillsDir . '/still_*.{jpg,JPG,jpeg,JPEG,png,PNG,webp,WEBP}', GLOB_BRACE) ?: glob($stillsDir . '/still_*.jpg');
if (!$files) {
    echo json_encode(['stills' => []]);
    exit;
}

natsort($files);
echo json_encode(['stills' => array_values(array_map(fn($f) => "image.php?image=" . urlencode($f), $files))]);
exit;