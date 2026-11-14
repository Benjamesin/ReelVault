<?php
session_start();
require_once __DIR__ . '/config.php';

// =========================================================================
// 1. SECURITY CHECK
// =========================================================================
if (SITE_PIN !== '') {
    $AUTH_HASH = hash('sha256', SITE_PIN);
    $is_authorized = (
        (!empty($_SESSION['site_unlocked']) && $_SESSION['site_unlocked'] === $AUTH_HASH) ||
        (!empty($_COOKIE['site_unlocked'])  && $_COOKIE['site_unlocked']  === $AUTH_HASH)
    );

    if (!$is_authorized) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode(['error' => true, 'message' => 'Unauthorized']);
        exit();
    }
}

header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

// =========================================================================
// 2. CONFIGURATION (From config.php)
// =========================================================================
$remoteBaseDir   = REMOTE_VIDEOS_DIR !== '' ? rtrim(REMOTE_VIDEOS_DIR, '/') : '';
$remoteParentDir = $remoteBaseDir !== '' ? rtrim(dirname($remoteBaseDir), '/') : '';
$vidPrefix       = rtrim(REMOTE_VIDEOS_PREFIX, '/');
$apiPrefix       = rtrim(REMOTE_API_PREFIX, '/');

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 2) {
    echo json_encode([]);
    exit();
}

// =========================================================================
// 3. RESOLVE USER IDENTIFIER
// =========================================================================
$userIdentifier = trim($_GET['user'] ?? '');
if (empty($userIdentifier)) {
    if (!empty($_COOKIE['viewer_profile'])) {
        $cleanProfile = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $_COOKIE['viewer_profile']));
        $userIdentifier = !empty($cleanProfile) ? 'user_' . $cleanProfile : '';
    }
    if (empty($userIdentifier)) {
        $userIdentifier = $_COOKIE['device_id'] ?? 'unknown';
    }
}
$userIdentifier = preg_replace('/[^a-zA-Z0-9_.-]/', '', $userIdentifier);

$results = [];

try {
    $dbPath = __DIR__ . '/media.db';
    if (!file_exists($dbPath)) {
        throw new Exception("Database file not found. Please run update_library.php first.");
    }

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare("SELECT * FROM movies WHERE title LIKE :q OR path LIKE :q ORDER BY title COLLATE NOCASE ASC");
    $stmt->execute([':q' => '%' . $query . '%']);
    $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $watchedSymbols = ['', '👀', '✓'];

    foreach ($movies as $movie) {
        $file         = $movie['path'] ?? '';
        $title        = $movie['title'] ?? 'Unknown Movie';
        $rating_value = ($movie['rating'] !== null && $movie['rating'] !== '') ? $movie['rating'] : '';
        $is_remote    = isset($movie['is_remote']) ? (int)$movie['is_remote'] === 1 : false;

        if (empty($file)) {
            continue;
        }

        $video_filename = pathinfo($file, PATHINFO_FILENAME);
        $safe_filename  = preg_replace('/[^a-zA-Z0-9_-]/', '', $video_filename);

        if ($is_remote && $remoteBaseDir !== '') {
            $stripPrefixes = [
                $remoteBaseDir . '/',
                $remoteParentDir . '/',
                '/' . $remoteParentDir . '/',
            ];
            $cleanPath = str_replace($stripPrefixes, '', $file);

            if (str_starts_with($cleanPath, 'http://') || str_starts_with($cleanPath, 'https://')) {
                $parsed = parse_url($cleanPath);
                $cleanPath = ltrim($parsed['path'] ?? '', '/');
            } else {
                $cleanPath = ltrim($cleanPath, '/');
            }

            $cleanRelative = preg_replace('#^videos/?#', '', $cleanPath);
            $videoUrlOut   = $vidPrefix . '/' . ltrim($cleanRelative, '/');

            $relative_dir       = dirname($cleanRelative);
            $nfo_dir            = ($relative_dir !== '.' && !empty($relative_dir)) ? rtrim($relative_dir, '/') . '/' : '';
            $remote_poster_path = $remoteBaseDir . '/' . $nfo_dir . "{$video_filename}-poster.jpg";

            $thumbnailSrc = $apiPrefix . "/image.php?image=" . urlencode($remote_poster_path);
        } else {
            $videoUrlOut  = $file;
            $local_poster = dirname($file) . "/{$video_filename}-poster.jpg";
            if (!file_exists($local_poster)) {
                $local_poster = dirname($file) . "/{$video_filename}.png";
            }

            if (!file_exists($local_poster)) {
                $thumbnailSrc = "media/folderIcon.png";
            } else {
                $thumbnailSrc = "image.php?image=" . urlencode($local_poster);
            }
        }

        $watchedSymbol = $watchedSymbols[0];
        $progressFile  = __DIR__ . "/IPdata/progress_{$userIdentifier}_{$safe_filename}.json";

        if (!file_exists($progressFile)) {
            $altFile = __DIR__ . "/IPdata/progress_{$userIdentifier}_{$video_filename}.json";
            if (file_exists($altFile)) {
                $progressFile = $altFile;
            }
        }

        if (file_exists($progressFile)) {
            $content = @file_get_contents($progressFile);
            if ($content) {
                $progress = json_decode($content, true);
                if ($progress && isset($progress['currentTime'], $progress['duration'])) {
                    $currentTime = (float)$progress['currentTime'];
                    $duration    = (float)$progress['duration'];

                    if ($duration > 0) {
                        $pct = ($currentTime / $duration) * 100;
                        if ($pct >= 90 || $currentTime == 0.13) {
                            $watchedSymbol = $watchedSymbols[2];
                        } elseif ($pct > 3) {
                            $watchedSymbol = $watchedSymbols[1];
                        }
                    }
                }
            }
        }

        $results[] = [
            'type'          => 'movie',
            'path'          => $videoUrlOut,
            'title'         => $title,
            'rating'        => $rating_value,
            'thumbnailSrc'  => $thumbnailSrc,
            'watchedSymbol' => $watchedSymbol,
            'is_remote'     => $is_remote ? 1 : 0
        ];
    }

    echo json_encode($results);

} catch (Throwable $e) {
    echo json_encode([
        'error'   => true,
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine()
    ]);
}
exit();