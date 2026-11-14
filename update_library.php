
<?php
session_start();
require_once __DIR__ . '/config.php';

// =========================================================================
// SECURITY CHECK
// =========================================================================
if (SITE_PIN !== '') {
    $AUTH_HASH = hash('sha256', SITE_PIN);
    $is_authorized = (
        (!empty($_SESSION['site_unlocked']) && $_SESSION['site_unlocked'] === $AUTH_HASH) ||
        (!empty($_COOKIE['site_unlocked'])  && $_COOKIE['site_unlocked']  === $AUTH_HASH)
    );
    if (!$is_authorized) {
        header('Location: landingPage.php');
        exit();
    }
}

// ##################################################################
// CONFIGURATION (Loaded from .env via config.php)
// ##################################################################
$local_video_directory  = LOCAL_VIDEOS_DIR;
$remote_video_directory = REMOTE_VIDEOS_DIR;
$remoteHost             = REMOTE_ENABLED ? REMOTE_HOST : '';
$remote_api_port        = REMOTE_API_PORT;

// Stop output buffering so updates stream in real-time
while (ob_get_level()) ob_end_clean();
header('Content-Type: text/html; charset=UTF-8');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');

echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Database Sync</title>
    <style>
        :root {
            --bg-color:    #121212;
            --focus-color: #ff9900;
            --text-color:  #e0e0e0;
            --box-bg:      #1e1e1e;
        }
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 40px 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 80vh;
        }
        .container {
            width: 100%;
            max-width: 650px;
            background: var(--box-bg);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            border: 1px solid #333;
        }
        h1 { margin: 0 0 10px 0; font-size: 1.8rem; color: #fff; text-align: center; }
        p.subtitle { margin: 0 0 25px 0; color: #888; font-size: 0.95rem; text-align: center; }

        .progress-track {
            background: #2b2b2b;
            height: 24px;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 12px;
            border: 1px solid #444;
            position: relative;
        }
        .progress-bar {
            background: var(--focus-color);
            width: 0%;
            height: 100%;
            border-radius: 12px;
            transition: width 0.2s ease-out;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: bold;
            color: #121212;
        }

        .status-container {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            margin-bottom: 25px;
            color: #bbb;
        }
        #status-text { font-weight: bold; color: var(--focus-color); }

        .log-box {
            background: #0b0b0b;
            border: 1px solid #333;
            height: 180px;
            border-radius: 6px;
            padding: 12px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.85rem;
            overflow-y: auto;
            color: #00ff66;
            box-sizing: border-box;
        }
        .log-box p { margin: 4px 0; line-height: 1.3; word-break: break-all; }
        .log-box .err { color: #ff3333; }
        .log-box .info { color: #888; }

        .btn-wrapper { margin-top: 25px; display: flex; justify-content: center; }
        .btn-done {
            background: var(--focus-color);
            color: #121212;
            text-decoration: none;
            padding: 10px 24px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 1rem;
            display: none;
            box-shadow: 0 4px 15px rgba(255, 153, 0, 0.3);
            transition: transform 0.15s ease-out;
        }
        .btn-done:hover { transform: scale(1.04); }
    </style>
</head>
<body>

<div class="container">
    <h1>Database Sync Status</h1>
    <p class="subtitle">Scanning local and configured remote movie directories</p>

    <div class="progress-track">
        <div id="progress-bar" class="progress-bar">0%</div>
    </div>

    <div class="status-container">
        <span>Status: <span id="status-text">Initializing...</span></span>
    </div>

    <div id="log-box" class="log-box"></div>

    <div class="btn-wrapper">
        <a href="GenreGrid.php" id="btn-done" class="btn-done">Return to Library</a>
    </div>
</div>

<script>
    const progressBar = document.getElementById('progress-bar');
    const statusText  = document.getElementById('status-text');
    const logBox      = document.getElementById('log-box');
    const btnDone     = document.getElementById('btn-done');

    function updateProgress(status, percent) {
        if (progressBar) {
            progressBar.style.width = percent + '%';
            progressBar.innerText = percent + '%';
        }
        if (statusText) {
            statusText.innerText = status;
        }
        if (percent === 100 && btnDone) {
            btnDone.style.display = 'block';
        }
    }

    function addLog(msg, type = '') {
        if (logBox) {
            const p = document.createElement('p');
            p.innerText = msg;
            if (type === 'err') p.className = 'err';
            if (type === 'info') p.className = 'info';
            logBox.appendChild(p);
            logBox.scrollTop = logBox.scrollHeight;
        }
    }
</script>
HTML;

echo str_repeat(' ', 4096);
flush();

// ------------------------------------------------------------------
// PHP HELPER FUNCTIONS
// ------------------------------------------------------------------
function update_gui(string $status, int $percent, string $log_msg = '', string $log_type = '') {
    echo "<script>";
    echo "updateProgress(" . json_encode($status) . ", " . $percent . ");";
    if ($log_msg !== '') {
        echo "addLog(" . json_encode($log_msg) . ", " . json_encode($log_type) . ");";
    }
    echo "</script>";
    echo str_repeat(' ', 1024);
    flush();
}

function scan_local_videos(string $dir): array {
    $files = [];
    $normalized_dir = str_replace('\\', '/', $dir);
    if ($normalized_dir === '' || !is_dir($normalized_dir)) return [];

    try {
        $directoryIterator = new RecursiveDirectoryIterator($normalized_dir, FilesystemIterator::SKIP_DOTS);
        $filteredIterator  = new RecursiveCallbackFilterIterator($directoryIterator, function ($current) {
            if ($current->isDir() && strtolower($current->getFilename()) === '720p') return false;
            return true;
        });
        $iterator    = new RecursiveIteratorIterator($filteredIterator);
        $video_files = new RegexIterator($iterator, '/\.(mp4|mkv)$/i', RegexIterator::MATCH);
        foreach ($video_files as $file) {
            $files[] = [
                'path'  => str_replace('\\', '/', $file->getPathname()),
                'mtime' => $file->getMTime()
            ];
        }
    } catch (Exception $e) { }
    return $files;
}

function fetch_remote_videos(string $host, int $port): array {
    if (empty($host)) return [];
    $ch = curl_init("http://{$host}:{$port}/list_videos.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }
    return [];
}

function parse_nfo_xml(?string $nfo_content, string $video_file): array {
    $xml = $nfo_content ? @simplexml_load_string($nfo_content) : null;

    $title  = ($xml && !empty((string)$xml->title)) ? (string)$xml->title : pathinfo($video_file, PATHINFO_FILENAME);
    $rating = ($xml && isset($xml->ratings->rating->value)) ? (string)$xml->ratings->rating->value : '';

    $genres = [];
    $tags   = [];
    if ($xml) {
        foreach ($xml->genre as $g) $genres[] = (string)$g;
        foreach ($xml->tag   as $t) $tags[]   = strtolower((string)$t);
    }
    return [
        'title'  => $title,
        'rating' => $rating,
        'genres' => $genres,
        'tags'   => $tags,
    ];
}

function batch_fetch_remote_nfos(array $remote_paths, string $host, int $port, string $remote_base_dir): array {
    if (empty($host) || empty($remote_paths)) return [];

    $remote_base_dir = rtrim(str_replace('\\', '/', $remote_base_dir), '/');
    $results = [];
    $chunks = array_chunk($remote_paths, 30, true);

    foreach ($chunks as $chunk) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($chunk as $video_path) {
            $clean_video_path = str_replace('\\', '/', $video_path);

            $relative_path = $clean_video_path;
            if (str_starts_with($relative_path, 'http://') || str_starts_with($relative_path, 'https://')) {
                $relative_path = parse_url($relative_path, PHP_URL_PATH) ?? '';
            }
            $relative_path = preg_replace('#^/(remote-videos|remote-api/videos|remote-api)/?#', '', $relative_path);
            $relative_path = str_replace($remote_base_dir, '', $relative_path);
            $relative_path = preg_replace('#^/videos/?#', '', $relative_path);
            $relative_path = ltrim($relative_path, '/');

            $relative_dir = dirname($relative_path);
            $file_name    = pathinfo($relative_path, PATHINFO_FILENAME);

            $nfo_subpath  = ($relative_dir !== '.' && !empty($relative_dir) ? $relative_dir . '/' : '') . $file_name . ".nfo";
            $nfo_path     = "{$remote_base_dir}/{$nfo_subpath}";

            $url = "http://{$host}:{$port}/get_file.php?file=" . urlencode($nfo_path);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[$video_path] = $ch;
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) === -1) {
                usleep(1000);
            }
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }

        foreach ($handles as $v_path => $ch) {
            $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $content = curl_multi_getcontent($ch);
            $results[$v_path] = ($code === 200 && !empty($content)) ? $content : null;

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
    }

    return $results;
}

// ------------------------------------------------------------------
// BACKEND SYNCHRONIZATION RUN
// ------------------------------------------------------------------
try {
    update_gui("Connecting to Database...", 5, "Connecting to local SQLite database...");

    $db = new PDO('sqlite:' . __DIR__ . '/media.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS movies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        path TEXT UNIQUE,
        title TEXT,
        rating REAL,
        genres TEXT,
        tags TEXT,
        mtime INTEGER,
        is_remote INTEGER
    )");

    // 1. Scan Local Directories
    update_gui("Scanning local folder...", 15, "Scanning directory: {$local_video_directory}...");
    $local_list = scan_local_videos($local_video_directory);
    update_gui("Scanning local folder...", 25, "Found " . count($local_list) . " local video files.", "info");

    // 2. Scan Remote Directories (only if REMOTE_ENABLED=true)
    $remote_list = [];
    if ($remoteHost !== '') {
        update_gui("Contacting remote server...", 35, "Connecting to remote node at {$remoteHost}:{$remote_api_port}...");
        $remote_list = fetch_remote_videos($remoteHost, $remote_api_port);
        update_gui("Contacting remote server...", 45, "Found " . count($remote_list) . " remote video files.", "info");
    } else {
        update_gui("Skipping remote scan...", 45, "Remote node disabled in .env (local-only mode).", "info");
    }

    // 3. Retrieve database current index state
    update_gui("Comparing library status...", 50, "Retrieving current database entries...");
    $stmt = $db->query("SELECT path, mtime FROM movies");
    $db_movies = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $db_movies[$row['path']] = (int)$row['mtime'];
    }

    $scanned_movies = [];
    foreach ($local_list as $item) {
        $scanned_movies[$item['path']] = ['mtime' => $item['mtime'], 'is_remote' => 0];
    }
    foreach ($remote_list as $item) {
        $scanned_movies[$item['path']] = ['mtime' => (int)$item['mtime'], 'is_remote' => 1];
    }

    $to_delete = [];
    $to_update = [];

    foreach ($db_movies as $path => $mtime) {
        if (!isset($scanned_movies[$path])) {
            $to_delete[] = $path;
        }
    }

    foreach ($scanned_movies as $path => $info) {
        if (!isset($db_movies[$path]) || $db_movies[$path] !== $info['mtime']) {
            $to_update[$path] = $info;
        }
    }

    update_gui("Analysis complete.", 55, "Identified " . count($to_delete) . " deleted files and " . count($to_update) . " changes.");

    $remote_updates = [];
    foreach ($to_update as $path => $info) {
        if ($info['is_remote'] === 1) {
            $remote_updates[] = $path;
        }
    }

    $remote_nfos = [];
    if (!empty($remote_updates) && $remoteHost !== '') {
        update_gui("Fetching remote NFO metadata...", 60, "Parallel downloading of " . count($remote_updates) . " remote NFO files...");
        $remote_nfos = batch_fetch_remote_nfos($remote_updates, $remoteHost, $remote_api_port, $remote_video_directory);
        update_gui("Fetching remote NFO metadata...", 70, "Finished NFO batch fetch.", "info");
    } else {
        update_gui("Analysis complete.", 70);
    }

    if (!empty($to_delete) || !empty($to_update)) {
        $db->beginTransaction();

        if (!empty($to_delete)) {
            $delete_stmt = $db->prepare("DELETE FROM movies WHERE path = ?");
            foreach ($to_delete as $path) {
                $delete_stmt->execute([$path]);
                update_gui("Removing old files...", 70, "Removed dead reference: " . basename($path), "info");
            }
        }

        if (!empty($to_update)) {
            $insert_stmt = $db->prepare("INSERT OR REPLACE INTO movies (path, title, rating, genres, tags, mtime, is_remote) VALUES (:path, :title, :rating, :genres, :tags, :mtime, :is_remote)");

            $total_items   = count($to_update);
            $current_idx   = 0;
            $step_interval = max(1, (int)ceil($total_items / 10));

            foreach ($to_update as $path => $info) {
                $current_idx++;
                $pct_slice = 70 + (int)(($current_idx / $total_items) * 25);

                $nfo_content = null;
                if ($info['is_remote'] === 1) {
                    $nfo_content = $remote_nfos[$path] ?? null;
                } else {
                    $file_name   = pathinfo($path, PATHINFO_FILENAME);
                    $nfo_path    = dirname($path) . "/{$file_name}.nfo";
                    $nfo_content = file_exists($nfo_path) ? file_get_contents($nfo_path) : null;
                }

                $meta = parse_nfo_xml($nfo_content, $path);

                $genres_formatted = !empty($meta['genres']) ? '|' . implode('|', $meta['genres']) . '|' : '';
                $tags_formatted   = !empty($meta['tags']) ? '|' . implode('|', $meta['tags']) . '|' : '';

                $insert_stmt->execute([
                    ':path'      => $path,
                    ':title'     => $meta['title'],
                    ':rating'    => $meta['rating'] !== '' ? (float)$meta['rating'] : null,
                    ':genres'    => $genres_formatted,
                    ':tags'      => $tags_formatted,
                    ':mtime'     => $info['mtime'],
                    ':is_remote' => $info['is_remote']
                ]);

                if ($total_items < 15 || $current_idx % $step_interval === 0 || $current_idx === $total_items) {
                    update_gui("Updating files ($current_idx/$total_items)...", $pct_slice, "Indexed: " . basename($path));
                }
            }
        }

        $db->commit();
    }

    update_gui("Finished!", 100, "All library entries are synchronized and up-to-date.");

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    update_gui("Error!", 100, "Execution terminated: " . $e->getMessage(), "err");
}

echo "</body></html>";
?>