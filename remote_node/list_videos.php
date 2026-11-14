<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$videosDir = REMOTE_VIDEOS_DIR;
$cacheFile = __DIR__ . '/list_cache.json';
$cacheTTL  = 300;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    $cachedData = @file_get_contents($cacheFile);
    if ($cachedData) {
        echo $cachedData;
        exit();
    }
}

$fileList = [];

if (is_dir($videosDir)) {
    try {
        $dirIterator = new RecursiveDirectoryIterator($videosDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator(
            $dirIterator,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        $files = new RegexIterator($iterator, '/\.(mp4|mkv)$/i', RegexIterator::MATCH);

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        if (!str_contains($host, ':')) {
            $host .= ':' . REMOTE_NODE_PORT;
        }

        foreach ($files as $fileinfo) {
            try {
                $normalized = str_replace('\\', '/', $fileinfo->getPathname());
                if (preg_match('#(^|/)720p(/|$)#i', $normalized)) continue;

                $normalizedBase     = str_replace('\\', '/', $videosDir);
                $relativePath       = str_replace($normalizedBase, '', $normalized);
                $localThumbnailPath = pathinfo($normalized, PATHINFO_DIRNAME) . "/" . pathinfo($normalized, PATHINFO_FILENAME) . "-poster.jpg";

                $fileList[] = [
                    'path'           => 'http://' . $host . '/videos' . $relativePath,
                    'name'           => $fileinfo->getFilename(),
                    'directory'      => pathinfo($relativePath, PATHINFO_DIRNAME),
                    'thumbnail_path' => $localThumbnailPath,
                    'mtime'          => $fileinfo->getMTime() ?: 0
                ];
            } catch (Throwable $e) {
                continue;
            }
        }
    } catch (Throwable $e) {}
}

$jsonData = json_encode($fileList);
@file_put_contents($cacheFile, $jsonData);
echo $jsonData;
exit();