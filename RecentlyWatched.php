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
        header('Location: landingPage.php');
        exit();
    }
}

// =========================================================================
// 2. IDENTITY RESOLUTION: User Name (Overrides) -> Device ID (Fallback)
// =========================================================================
if (empty($_COOKIE['device_id'])) {
    $deviceId = 'dev_' . bin2hex(random_bytes(6));
    setcookie('device_id', $deviceId, time() + (86400 * 365 * 10), '/');
    $_COOKIE['device_id'] = $deviceId;
} else {
    $deviceId = $_COOKIE['device_id'];
}

if (!empty($_COOKIE['viewer_profile'])) {
    $cleanProfile = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $_COOKIE['viewer_profile']));
    $userIdentifier = !empty($cleanProfile) ? 'user_' . $cleanProfile : $deviceId;
    $displayLabel = "User: <span>" . htmlspecialchars(ucfirst($cleanProfile)) . "</span>";
} else {
    $userIdentifier = $deviceId;
    $displayLabel = "Device ID: <span>" . htmlspecialchars($deviceId) . "</span>";
}

$iconSize = (int)($_SESSION['iconSize'] ?? 150);
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["icon-size"])) {
    $iconSize = (int)$_POST["icon-size"];
    $_SESSION["iconSize"] = $iconSize;
}

// =========================================================================
// 3. SHOW INDEXER (Filesystem Scanner & Cache)
// =========================================================================
$SHOWS_INDEX_FILE = __DIR__ . '/IPdata/shows_index.json';
$CACHE_LIFETIME   = 86400; // 24 hours (or manual refresh via ?reindex=1)

$apiPrefix       = rtrim(REMOTE_API_PREFIX, '/');
$vidPrefix       = rtrim(REMOTE_VIDEOS_PREFIX, '/');
$remoteBaseDir   = REMOTE_VIDEOS_DIR !== '' ? rtrim(REMOTE_VIDEOS_DIR, '/') : '';
$remoteParentDir = $remoteBaseDir !== '' ? rtrim(dirname($remoteBaseDir), '/') : '';

function fetchRemoteFolderList($path) {
    if (!REMOTE_ENABLED) return [];
    $localPort = $_SERVER['SERVER_PORT'] ?? '80';
    $apiPrefix = rtrim(REMOTE_API_PREFIX, '/');
    $url = "http://127.0.0.1:{$localPort}{$apiPrefix}/list_folder.php?path=" . urlencode($path);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && $response) {
        $data = json_decode($response, true);
        if (is_array($data)) return $data;
    }
    return [];
}

function buildShowsIndex($cacheFile) {
    $index = [];
    $showRoots = [];
    if (LOCAL_SHOWS_DIR !== '') {
        $showRoots[] = ['path' => rtrim(LOCAL_SHOWS_DIR, '/'), 'remote' => false];
    }
    if (REMOTE_ENABLED && REMOTE_SHOWS_DIR !== '') {
        $showRoots[] = ['path' => rtrim(REMOTE_SHOWS_DIR, '/'), 'remote' => true];
    }

    $videoExtensions = ['mp4', 'mkv', 'avi', 'mov', 'webm', 'm4v', 'ts', 'mpg', 'wmv'];

    foreach ($showRoots as $rootCfg) {
        $rootPath = $rootCfg['path'];
        $isRemote = $rootCfg['remote'];

        $shows = [];
        if (!$isRemote && is_dir($rootPath)) {
            $raw = @scandir($rootPath) ?: [];
            foreach ($raw as $s) {
                if ($s !== '.' && $s !== '..' && is_dir($rootPath . '/' . $s)) {
                    $shows[] = ['name' => $s, 'is_dir' => true];
                }
            }
        } elseif ($isRemote) {
            $shows = fetchRemoteFolderList($rootPath);
        }

        foreach ($shows as $showItem) {
            $showDir = is_array($showItem) ? $showItem['name'] : $showItem;
            $isDir   = is_array($showItem) ? ($showItem['is_dir'] ?? true) : true;

            if (!$isDir || in_array(strtolower($showDir), ['.', '..', '720p', 'stills', 'showsarchived'])) {
                continue;
            }

            $fullShowPath = $rootPath . '/' . $showDir;

            $subItems = [];
            if (!$isRemote) {
                $raw = @scandir($fullShowPath) ?: [];
                foreach ($raw as $sub) {
                    if ($sub !== '.' && $sub !== '..') {
                        $subItems[] = ['name' => $sub, 'is_dir' => is_dir($fullShowPath . '/' . $sub)];
                    }
                }
            } else {
                $subItems = fetchRemoteFolderList($fullShowPath);
            }

            $showFilesOnly = [];
            foreach ($subItems as $item) {
                $name = is_array($item) ? $item['name'] : $item;
                $isD  = is_array($item) ? ($item['is_dir'] ?? false) : false;
                if (!$isD) {
                    $showFilesOnly[] = $name;
                }
            }

            $showPoster = '';
            foreach (['poster.jpg', 'poster.png', 'foldericon.png', 'folderIcon.png'] as $cand) {
                foreach ($showFilesOnly as $fn) {
                    if (strcasecmp($fn, $cand) === 0) {
                        $showPoster = $fullShowPath . '/' . $fn;
                        break 2;
                    }
                }
            }
            if (!$showPoster) {
                $showPoster = $fullShowPath . '/foldericon.png';
            }

            foreach ($subItems as $subItem) {
                $subName  = is_array($subItem) ? $subItem['name'] : $subItem;
                $subIsDir = is_array($subItem) ? ($subItem['is_dir'] ?? false) : false;

                if (in_array(strtolower($subName), ['.', '..', '720p', 'stills'])) {
                    continue;
                }

                $fullSubPath = $fullShowPath . '/' . $subName;

                if ($subIsDir) {
                    $seasonName = $subName;

                    $possibleSeasonPosters = [
                        "{$seasonName}-poster.jpg",
                        "{$seasonName}-poster.png"
                    ];
                    if (preg_match('/^season\s*0*(\d+)$/i', $seasonName, $m)) {
                        $sNum = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                        $possibleSeasonPosters[] = "season{$sNum}-poster.jpg";
                        $possibleSeasonPosters[] = "season{$sNum}-poster.png";
                    } elseif (preg_match('/^specials?$/i', $seasonName)) {
                        $possibleSeasonPosters[] = "season00-poster.jpg";
                        $possibleSeasonPosters[] = "season00-poster.png";
                    }

                    $seasonPoster = '';
                    foreach ($possibleSeasonPosters as $p) {
                        foreach ($showFilesOnly as $fn) {
                            if (strcasecmp($fn, $p) === 0) {
                                $seasonPoster = $fullShowPath . '/' . $fn;
                                break 2;
                            }
                        }
                    }

                    $episodes = [];
                    if (!$isRemote) {
                        $raw = @scandir($fullSubPath) ?: [];
                        foreach ($raw as $ep) {
                            if ($ep !== '.' && $ep !== '..') {
                                $episodes[] = ['name' => $ep, 'is_dir' => false];
                            }
                        }
                    } else {
                        $episodes = fetchRemoteFolderList($fullSubPath);
                    }

                    if (!$seasonPoster) {
                        foreach ($episodes as $epItem) {
                            $epName = is_array($epItem) ? $epItem['name'] : $epItem;
                            if (strcasecmp($epName, 'foldericon.png') === 0 || strcasecmp($epName, 'folderIcon.png') === 0) {
                                $seasonPoster = $fullSubPath . '/' . $epName;
                                break;
                            }
                        }
                    }

                    if (!$seasonPoster) {
                        $seasonPoster = $showPoster;
                    }

                    foreach ($episodes as $epItem) {
                        $ep  = is_array($epItem) ? $epItem['name'] : $epItem;
                        $ext = strtolower(pathinfo($ep, PATHINFO_EXTENSION));

                        if (in_array($ext, $videoExtensions)) {
                            $rawName    = pathinfo($ep, PATHINFO_FILENAME);
                            $safeName   = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawName);
                            $cleanNoSym = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rawName));

                            $entry = [
                                'type'         => 'show',
                                'show_root'    => $fullShowPath,
                                'show_title'   => $showDir,
                                'season'       => $seasonName,
                                'episode_path' => $fullSubPath . '/' . $ep,
                                'poster'       => $seasonPoster,
                                'is_remote'    => $isRemote
                            ];

                            $index[$safeName]             = $entry;
                            $index[$rawName]              = $entry;
                            $index[strtolower($safeName)] = $entry;
                            $index[strtolower($rawName)]  = $entry;
                            $index[$cleanNoSym]           = $entry;
                        }
                    }
                } else {
                    $ext = strtolower(pathinfo($subName, PATHINFO_EXTENSION));
                    if (in_array($ext, $videoExtensions)) {
                        $rawName    = pathinfo($subName, PATHINFO_FILENAME);
                        $safeName   = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawName);
                        $cleanNoSym = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rawName));

                        $entry = [
                            'type'         => 'show',
                            'show_root'    => $fullShowPath,
                            'show_title'   => $showDir,
                            'season'       => '',
                            'episode_path' => $fullSubPath,
                            'poster'       => $showPoster,
                            'is_remote'    => $isRemote
                        ];

                        $index[$safeName]             = $entry;
                        $index[$rawName]              = $entry;
                        $index[strtolower($safeName)] = $entry;
                        $index[strtolower($rawName)]  = $entry;
                        $index[$cleanNoSym]           = $entry;
                    }
                }
            }
        }
    }

    if (!is_dir(dirname($cacheFile))) {
        @mkdir(dirname($cacheFile), 0775, true);
    }
    @file_put_contents($cacheFile, json_encode($index));
    return $index;
}

function getShowsIndex($cacheFile, $maxAge) {
    if (isset($_GET['reindex']) || !file_exists($cacheFile) || (time() - filemtime($cacheFile)) > $maxAge) {
        return buildShowsIndex($cacheFile);
    }
    $content = @file_get_contents($cacheFile);
    $data = json_decode($content, true);
    return is_array($data) ? $data : buildShowsIndex($cacheFile);
}

$showsIndex = getShowsIndex($SHOWS_INDEX_FILE, $CACHE_LIFETIME);

// =========================================================================
// 4. DATABASE & PROGRESS SCANNING
// =========================================================================
$watchedItems = [];

try {
    $dbPath = __DIR__ . '/media.db';
    if (file_exists($dbPath)) {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->query("SELECT path, title, rating, is_remote FROM movies");
        $db_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $db_records = [];
    }

    $movieLookup = [];
    foreach ($db_records as $row) {
        $rawFilename  = pathinfo($row['path'], PATHINFO_FILENAME);
        $safeFilename = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawFilename);
        $movieData = [
            'type'      => 'movie',
            'path'      => $row['path'],
            'title'     => $row['title'],
            'rating'    => ($row['rating'] !== null && $row['rating'] !== '') ? $row['rating'] : 'N/A',
            'filename'  => $rawFilename,
            'is_remote' => (int)$row['is_remote'] === 1
        ];
        $movieLookup[$safeFilename] = $movieData;
        $movieLookup[$rawFilename]  = $movieData;
    }

    $directory    = __DIR__ . '/IPdata/';
    $targetPrefix = "progress_{$userIdentifier}_";
    $showsTracked = [];

    if (is_dir($directory) && ($handle = opendir($directory))) {
        while (false !== ($file = readdir($handle))) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'json' && str_starts_with($file, $targetPrefix)) {
                $videoKey = preg_replace("/^progress_" . preg_quote($userIdentifier, '/') . "_(.+)\.json$/", '$1', $file);
                if (empty($videoKey)) continue;

                $filePath    = $directory . $file;
                $lastWatched = filemtime($filePath);

                $progressJson = json_decode(@file_get_contents($filePath), true);
                $statusClass  = '';
                if ($progressJson && isset($progressJson['currentTime'], $progressJson['duration'])) {
                    $cur = (float)$progressJson['currentTime'];
                    $dur = (float)$progressJson['duration'];
                    if ($dur > 0) {
                        $pct = ($cur / $dur) * 100;
                        if ($pct >= 90 || $cur == 0.13) {
                            $statusClass = 'status-watched';
                        } elseif ($pct > 3 || $cur > 5) {
                            $statusClass = 'status-partial';
                        }
                    }
                }

                $decodedKey = urldecode($videoKey);
                $safeKey    = preg_replace('/[^a-zA-Z0-9_-]/', '', $videoKey);
                $cleanNoSym = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $decodedKey));

                $matchedShow = $showsIndex[$videoKey]
                            ?? ($showsIndex[$safeKey]
                            ?? ($showsIndex[strtolower($videoKey)]
                            ?? ($showsIndex[strtolower($safeKey)]
                            ?? ($showsIndex[$decodedKey]
                            ?? ($showsIndex[$cleanNoSym] ?? null)))));

                if ($matchedShow !== null) {
                    $showInfo = $matchedShow;
                    $showRoot = $showInfo['show_root'];

                    if (!isset($showsTracked[$showRoot]) || $lastWatched > $showsTracked[$showRoot]['last_watched']) {
                        $showsTracked[$showRoot] = [
                            'type'         => 'show',
                            'path'         => $showRoot,
                            'title'        => $showInfo['show_title'],
                            'season'       => $showInfo['season'],
                            'episode_path' => $showInfo['episode_path'],
                            'poster'       => $showInfo['poster'],
                            'is_remote'    => $showInfo['is_remote'],
                            'rating'       => 'N/A',
                            'last_watched' => $lastWatched,
                            'status_class' => $statusClass
                        ];
                    }
                } elseif (isset($movieLookup[$videoKey]) || isset($movieLookup[$safeKey])) {
                    $movieInfo = $movieLookup[$videoKey] ?? $movieLookup[$safeKey];
                    $movieInfo['last_watched'] = $lastWatched;
                    $movieInfo['status_class'] = $statusClass;
                    $watchedItems[] = $movieInfo;
                }
            }
        }
        closedir($handle);
    }

    foreach ($showsTracked as $show) {
        $watchedItems[] = $show;
    }

    usort($watchedItems, function($a, $b) {
        return $b['last_watched'] - $a['last_watched'];
    });

    $watchedItems = array_slice($watchedItems, 0, 30);

} catch (Exception $e) {
    error_log("Recently Watched Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recently Watched</title>
    <style>
        :root {
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --focus-color: #ff9900;
            --text-color: #e0e0e0;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 20px 40px;
            overflow-x: hidden;
            min-height: 100vh;
        }
        .container { max-width: 1800px; margin: 0 auto; }

        .header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            gap: 15px;
        }
        .header h1 {
            font-size: 2rem;
            color: white;
            margin: 0;
            flex: 1;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background: #1e1e1e;
            border: 1px solid #444;
            border-radius: 6px;
            color: #e0e0e0;
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        .back-link:hover, .back-link:focus {
            background: #2a2a2a;
            border-color: var(--focus-color);
            color: #fff;
            transform: scale(1.05);
            outline: none;
        }

        .user-indicator {
            margin-left: auto;
            background: #1e1e1e;
            border: 1px solid #444;
            padding: 8px 16px;
            border-radius: 50px;
            font-size: 0.9rem;
            color: #bbb;
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .user-indicator:hover, .user-indicator:focus {
            border-color: var(--focus-color);
            background: #2a2a2a;
            color: white;
            transform: scale(1.04);
            outline: none;
        }
        .user-indicator span {
            color: var(--focus-color);
            font-weight: bold;
        }

        .settings-bar {
            margin-top: 30px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .settings-toggle {
            background: transparent;
            border: 1px solid #555;
            color: #aaa;
            padding: 8px 15px;
            font-size: 14px;
            cursor: pointer;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .settings-toggle:hover, .settings-toggle:focus {
            background: #333;
            color: white;
            border-color: var(--focus-color);
            outline: none;
        }
        .sync-btn {
            background: #1e1e1e;
            border: 1px solid #444;
            color: #bbb;
            padding: 8px 15px;
            font-size: 14px;
            cursor: pointer;
            border-radius: 4px;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .sync-btn:hover, .sync-btn:focus {
            border-color: var(--focus-color);
            color: white;
            background: #2a2a2a;
            outline: none;
        }
        .slider-container {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, opacity 0.3s ease;
            opacity: 0;
            margin-top: 10px;
            background: #1e1e1e;
            padding: 0 15px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
            width: 100%;
            max-width: 400px;
        }
        .slider-container.show {
            max-height: 80px;
            opacity: 1;
            padding: 15px;
            border: 1px solid #333;
        }
        .slider-container input[type="range"] {
            flex: 1;
            height: 6px;
            background: #444;
            border-radius: 3px;
            outline: none;
            -webkit-appearance: none;
        }
        .slider-container input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 18px;
            height: 18px;
            background: var(--focus-color);
            border-radius: 50%;
            cursor: pointer;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(<?php echo (int)$iconSize; ?>px, 1fr));
            gap: 20px;
            padding-bottom: 20px;
        }
        .grid-item {
            position: relative;
            display: flex;
            flex-direction: column;
        }
        .grid-item button, .grid-item a {
            border: 0;
            background: transparent;
            padding: 0;
            cursor: pointer;
            width: 100%;
            display: block;
            transition: transform 0.15s ease;
            position: relative;
            text-decoration: none;
        }
        .poster-container {
            position: relative;
            width: 100%;
            aspect-ratio: 2/3;
            background: #222;
            border-radius: 8px;
            overflow: hidden;
            border: 3px solid transparent;
        }

        .poster-container > img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        a:focus, button:focus {
            outline: none;
            transform: scale(1.05);
            z-index: 10;
        }
        a:focus .poster-container, button:focus .poster-container {
            border-color: var(--focus-color) !important;
            box-shadow: 0 0 15px rgba(255, 153, 0, 0.4);
        }

        .rating-overlay {
            position: absolute;
            bottom: 6px;
            right: 6px;
            display: flex;
            align-items: center;
            gap: 3px;
            background: rgba(0, 0, 0, 0.85);
            padding: 2px 5px;
            border-radius: 4px;
            line-height: 1;
            z-index: 4;
        }
        .rating-star {
            width: 10px !important;
            height: 10px !important;
            object-fit: contain;
            flex-shrink: 0;
            display: inline-block;
        }
        .rating-text {
            color: #ffd700;
            font-size: 10px;
            font-weight: bold;
            line-height: 1;
        }

        .watched-badge {
            position: absolute;
            bottom: 6px;
            left: 6px;
            background: rgba(0, 0, 0, 0.8);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            color: #ddd;
        }

        .type-badge {
            position: absolute;
            top: 6px;
            left: 6px;
            background: rgba(100, 50, 200, 0.9);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.65rem;
            color: #fff;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-badge {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 2px solid rgba(0,0,0,0.6);
            box-shadow: 0 2px 5px rgba(0,0,0,0.8);
            z-index: 5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }
        .status-watched { background-color: #2ecc71; }
        .status-watched::after { content: '✓'; font-size: 14px; }
        .status-partial { background-color: #f39c12; }
        .status-partial::after { content: '👁'; font-size: 12px; }

        .title {
            text-align: center;
            margin-top: 8px;
            font-size: 0.9rem;
            color: #bbb;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .sub-season-label {
            display: block;
            font-size: 0.78rem;
            color: var(--focus-color);
            margin-top: 2px;
            font-weight: 500;
        }

        .empty-state { text-align: center; padding: 80px 20px; color: #888; }
        .empty-state-icon { font-size: 4rem; margin-bottom: 20px; opacity: 0.5; }
        .empty-state h2 { font-size: 1.5rem; margin-bottom: 10px; color: #bbb; }

        @media (max-width: 768px) {
            body { padding: 15px; }
            .header { margin-bottom: 15px; }
            .grid { gap: 12px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="GenreGrid.php" class="back-link">← Library</a>
            <h1>Recently Watched</h1>
            <button class="user-indicator" onclick="changeProfile()" title="Click to change your profile name">
                👤 <?php echo $displayLabel; ?>
            </button>
        </div>

        <?php if (empty($watchedItems)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🎬</div>
                <h2>No Watch History Yet</h2>
                <p>Media played under this profile will automatically appear here</p>
            </div>
        <?php else: ?>
            <div class="grid">
                <?php
                foreach ($watchedItems as $item) {
                    $isShow   = ($item['type'] === 'show');
                    $isRemote = $item['is_remote'];

                    $timeAgo = time() - $item['last_watched'];
                    if ($timeAgo < 3600) {
                        $lastWatched = max(1, floor($timeAgo / 60)) . 'm ago';
                    } elseif ($timeAgo < 86400) {
                        $lastWatched = floor($timeAgo / 3600) . 'h ago';
                    } else {
                        $lastWatched = floor($timeAgo / 86400) . 'd ago';
                    }
                    ?>
                    <div class="grid-item">
                        <?php if ($isShow): ?>
                            <?php
                            $seasonFolder = $item['season'];
                            $targetRoot   = !empty($seasonFolder) ? $item['path'] : dirname($item['path']);
                            $targetFolder = !empty($seasonFolder) ? $seasonFolder : basename($item['path']);
                            $imageApi     = $isRemote ? "{$apiPrefix}/image.php" : "image.php";
                            $posterUrl    = $imageApi . "?image=" . urlencode($item['poster']);
                            ?>
                            <form action="showGridTwo.php" method="GET">
                                <input type="hidden" name="root" value="<?php echo htmlspecialchars($targetRoot); ?>">
                                <input type="hidden" name="remote" value="<?php echo $isRemote ? '1' : '0'; ?>">
                                <button type="submit" name="folder" value="<?php echo htmlspecialchars($targetFolder); ?>">
                                    <div class="poster-container">
                                        <?php if (!empty($item['status_class'])): ?>
                                            <div class="status-badge <?php echo $item['status_class']; ?>"></div>
                                        <?php endif; ?>

                                        <div class="type-badge"><?php echo !empty($seasonFolder) ? htmlspecialchars($seasonFolder) : 'TV'; ?></div>

                                        <img src="<?php echo htmlspecialchars($posterUrl); ?>"
                                             alt="<?php echo htmlspecialchars($item['title']); ?>"
                                             loading="lazy"
                                             onerror="this.onerror=null; this.src='image.php?image=media/folderIcon.png';">

                                        <div class="watched-badge"><?php echo $lastWatched; ?></div>
                                    </div>
                                </button>
                            </form>
                            <div class="title">
                                <?php echo htmlspecialchars($item['title']); ?>
                                <?php if (!empty($seasonFolder)): ?>
                                    <span class="sub-season-label"><?php echo htmlspecialchars($seasonFolder); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php
                            if ($isRemote && $remoteBaseDir !== '') {
                                $rawPath = $item['path'];

                                if (str_starts_with($rawPath, 'http://') || str_starts_with($rawPath, 'https://')) {
                                    $parsed  = parse_url($rawPath);
                                    $rawPath = $parsed['path'] ?? '';
                                }

                                $rawPath = preg_replace('#^(' . preg_quote($vidPrefix, '#') . '|' . preg_quote($apiPrefix, '#') . '|/remote-videos|/remote-api/videos|/remote-api)/?#', '', $rawPath);

                                $stripPrefixes = [
                                    $remoteBaseDir . '/',
                                    $remoteParentDir . '/',
                                    '/' . $remoteParentDir . '/',
                                ];
                                $cleanPath     = str_replace($stripPrefixes, '', $rawPath);
                                $cleanRelative = preg_replace('#^videos/?#', '', ltrim($cleanPath, '/'));
                                $linkValue     = $vidPrefix . '/' . ltrim($cleanRelative, '/');

                                $relative_dir = dirname($cleanRelative);
                                $nfo_dir      = ($relative_dir !== '.' && !empty($relative_dir)) ? rtrim($relative_dir, '/') . '/' : '';

                                $posterFile       = !empty($item['filename']) ? $item['filename'] : pathinfo($cleanRelative, PATHINFO_FILENAME);
                                $remotePosterPath = "{$remoteBaseDir}/{$nfo_dir}{$posterFile}-poster.jpg";
                                $posterUrl        = "{$apiPrefix}/image.php?image=" . urlencode($remotePosterPath);
                            } else {
                                $linkValue  = $item['path'];
                                $folder     = dirname($item['path']);
                                $posterPath = $folder . "/" . $item['filename'] . "-poster.jpg";
                                $posterUrl  = file_exists($posterPath)
                                    ? "image.php?image=" . urlencode($posterPath)
                                    : "image.php?image=media/folderIcon.png";
                            }
                            ?>
                            <form action="videoPlayer.php" method="GET">
                                <input type="hidden" name="remote" value="<?php echo $isRemote ? '1' : '0'; ?>">
                                <button name="video" value="<?php echo htmlspecialchars($linkValue); ?>">
                                    <div class="poster-container">
                                        <?php if (!empty($item['status_class'])): ?>
                                            <div class="status-badge <?php echo $item['status_class']; ?>"></div>
                                        <?php endif; ?>
                                        <img src="<?php echo htmlspecialchars($posterUrl); ?>"
                                             alt="<?php echo htmlspecialchars($item['title']); ?>"
                                             loading="lazy"
                                             onerror="this.onerror=null; this.src='image.php?image=media/folderIcon.png';">
                                        <div class="watched-badge"><?php echo $lastWatched; ?></div>
                                        <?php if ($item['rating'] !== 'N/A'): ?>
                                            <div class="rating-overlay">
                                                <img src="image.php?image=media/star.png" class="rating-star" alt="★">
                                                <span class="rating-text"><?php echo htmlspecialchars($item['rating']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </button>
                            </form>
                            <p class="title"><?php echo htmlspecialchars($item['title']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php } ?>
            </div>
        <?php endif; ?>

        <div class="settings-bar">
            <button id="settings-toggle" class="settings-toggle">
                <span>⚙️</span> Icon Settings
            </button>
            <a href="RecentlyWatched.php?reindex=1" class="sync-btn" title="Re-scan shows directories for newly added files">
                ↻ Sync Shows
            </a>
            <div id="slider-container" class="slider-container">
                <label for="icon-size" style="color:white;">Size:</label>
                <input type="range" id="icon-size" min="48" max="256" step="3" value="<?php echo (int)$iconSize; ?>">
                <span id="icon-size-output" style="color:var(--focus-color); width:50px; text-align:right;"><?php echo (int)$iconSize; ?>px</span>
            </div>
        </div>
    </div>

<script>
    const settingsToggle  = document.getElementById("settings-toggle");
    const sliderContainer = document.getElementById("slider-container");
    const slider          = document.getElementById("icon-size");
    const output          = document.getElementById("icon-size-output");

    if (settingsToggle && sliderContainer) {
        settingsToggle.addEventListener("click", () => {
            sliderContainer.classList.toggle("show");
        });

        slider.addEventListener("input", function() {
            output.textContent = this.value + "px";
        });

        slider.addEventListener("change", function() {
            fetch(`set_icon_size.php?value=${this.value}`)
                .then(response => response.text())
                .then(() => location.reload())
                .catch(err => console.error(err));
        });
    }

    function changeProfile() {
        const current = <?php echo json_encode($_COOKIE['viewer_profile'] ?? ''); ?>;
        const promptMsg = current
            ? `Current profile: "${current}"\n\nEnter a new name to switch, or leave blank to reset to Device ID:`
            : "Enter your name (e.g. Alex, Mom, Sam) to sync your watch progress:";

        const name = prompt(promptMsg, current);
        if (name !== null) {
            window.location.href = `set_profile.php?name=${encodeURIComponent(name.trim())}`;
        }
    }
</script>
</body>
</html>