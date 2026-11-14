<?php
session_start();
require_once __DIR__ . '/config.php';

$logDir = __DIR__ . '/poster_logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

function logMessage($message) {
    global $logDir;
    $logFile = $logDir . '/generate_poster.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

function isAllowedMediaPath($videoPath) {
    $real = realpath($videoPath);
    if ($real === false) return false;
    $real = str_replace('\\', '/', $real);

    $allowed = array_filter([LOCAL_VIDEOS_DIR, LOCAL_SHOWS_DIR, SERIES_DIR]);
    foreach ($allowed as $dir) {
        $realDir = realpath($dir);
        if ($realDir !== false) {
            $realDir = rtrim(str_replace('\\', '/', $realDir), '/') . '/';
            if (str_starts_with($real, $realDir)) {
                return true;
            }
        }
    }
    return false;
}

function generatePosterForVideo($videoPath) {
    logMessage("=== START generatePosterForVideo ===");
    logMessage("Video path: {$videoPath}");

    if (!file_exists($videoPath)) {
        logMessage("ERROR: Video file not found");
        return ['success' => false, 'error' => 'Video file not found'];
    }

    if (!isAllowedMediaPath($videoPath)) {
        logMessage("ERROR: Path outside configured media directories");
        return ['success' => false, 'error' => 'Unauthorized path'];
    }

    $directory  = dirname($videoPath);
    $filename   = pathinfo($videoPath, PATHINFO_FILENAME);
    $posterPath = $directory . "/" . $filename . "-poster.jpg";

    if (file_exists($posterPath)) {
        return ['success' => true, 'message' => 'Poster already exists', 'path' => $posterPath];
    }

    $isWin        = (stripos(PHP_OS, 'WIN') === 0);
    $whichCmd     = $isWin ? 'where' : 'which';
    $ffmpegCheck  = trim((string)shell_exec("{$whichCmd} ffmpeg 2>/dev/null"));
    $ffprobeCheck = trim((string)shell_exec("{$whichCmd} ffprobe 2>/dev/null"));

    if (!$ffmpegCheck || !$ffprobeCheck) {
        logMessage("ERROR: FFmpeg or ffprobe not found");
        return ['success' => false, 'error' => 'FFmpeg or ffprobe not found in system PATH'];
    }

    try {
        $durationCmd = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellarg($videoPath)
        );
        $duration = trim((string)shell_exec($durationCmd));

        if (empty($duration) || !is_numeric($duration)) {
            return ['success' => false, 'error' => 'Could not determine video duration'];
        }

        $totalSeconds = (int)$duration;
        $targetTime   = max(1, (int)($totalSeconds / 3));

        $ffmpegCmd = sprintf(
            'ffmpeg -ss %d -i %s -frames:v 1 -q:v 2 %s -y -loglevel quiet 2>&1',
            $targetTime,
            escapeshellarg($videoPath),
            escapeshellarg($posterPath)
        );
        shell_exec($ffmpegCmd);

        $posterExists = file_exists($posterPath);
        $posterSize   = $posterExists ? filesize($posterPath) : 0;

        if ($posterExists && $posterSize > 0) {
            return [
                'success'     => true,
                'message'     => 'Poster generated successfully',
                'path'        => $posterPath,
                'duration'    => $totalSeconds,
                'target_time' => $targetTime,
                'size'        => $posterSize
            ];
        }
        return ['success' => false, 'error' => 'FFmpeg failed to generate poster'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['video'])) {
    header('Content-Type: application/json');

    if (SITE_PIN !== '') {
        $AUTH_HASH = hash('sha256', SITE_PIN);
        $ok = (
            (!empty($_SESSION['site_unlocked']) && $_SESSION['site_unlocked'] === $AUTH_HASH) ||
            (!empty($_COOKIE['site_unlocked'])  && $_COOKIE['site_unlocked']  === $AUTH_HASH)
        );
        if (!$ok) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
    }

    echo json_encode(generatePosterForVideo($_GET['video']));
    exit;
}