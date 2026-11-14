<?php
session_start();
require_once __DIR__ . '/config.php';

// Check authorization cookie if SITE_PIN is configured
if (SITE_PIN !== '') {
    $AUTH_HASH = hash('sha256', SITE_PIN);
    if (empty($_COOKIE['site_unlocked']) || $_COOKIE['site_unlocked'] !== $AUTH_HASH) {
        header('Location: landingPage.php');
        exit();
    }
}

// Optional captcha gate
if (REQUIRE_CAPTCHA && (empty($_SESSION['passed_captcha']) || !$_SESSION['passed_captcha'])) {
    header('Location: index.php');
    exit();
}

// ##################################################################
// CONFIGURATION
// ##################################################################
$remote_video_directory = REMOTE_VIDEOS_DIR;
$iconSize               = (int)($_SESSION['iconSize'] ?? 200);

// ##################################################################
// GET PARAMETERS
// ##################################################################
$search_type  = $_GET['search_type'] ?? 'all';
$filter_value = $_GET['filter']      ?? '';

// ------------------------------------------------------------------
// STREAM LOADSPINNER IMMEDIATELY
// ------------------------------------------------------------------
$page_title = match($search_type) {
    'genre'  => "Genre: " . htmlspecialchars($filter_value),
    'letter' => "Start with: " . htmlspecialchars(strtoupper($filter_value)),
    'comic'  => 'Based On Comic',
    'recent' => 'New Arrivals',
    default  => 'All Movies'
};

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
    <title>{$page_title}</title>
    <style>
        :root {
            --bg-color:    #121212;
            --focus-color: #ff9900;
            --text-color:  #e0e0e0;
        }
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 20px 40px;
            overflow-x: hidden;
        }
        .header {
            margin-bottom: 25px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { margin: 0; font-size: 2rem; color: #fff; }
        .update-link { color: #ff9900; text-decoration: none; font-weight: bold; border: 2px solid #ff9900; padding: 6px 12px; border-radius: 5px; font-size: 0.9rem; }
        .update-link:hover { background: #ff9900; color: #121212; }

        #loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(18, 18, 18, 0.92);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            gap: 24px;
        }
        .spinner {
            width: 56px;
            height: 56px;
            border: 5px solid #333;
            border-top-color: var(--focus-color);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .loading-text { color: #aaa; font-size: 1.1rem; letter-spacing: 0.05em; }
        .loading-text span { color: var(--focus-color); font-weight: bold; }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax({$iconSize}px, 1fr));
            gap: 20px;
            padding-bottom: 50px;
        }
        .grid-item { position: relative; display: flex; flex-direction: column; }
        .grid-item button {
            background: transparent;
            border: 4px solid transparent;
            padding: 0;
            cursor: pointer;
            border-radius: 8px;
            position: relative;
            transition: transform 0.15s ease-out;
            width: 100%;
            display: block;
        }
        .grid-item img.poster {
            width: 100%;
            height: auto;
            aspect-ratio: 2/3;
            object-fit: cover;
            border-radius: 6px;
            display: block;
            background: #222;
        }
        .grid-item button:focus {
            outline: none;
            border-color: var(--focus-color);
            transform: scale(1.08);
            z-index: 10;
            box-shadow: 0 10px 25px rgba(0,0,0,0.8);
            background: #222;
        }
        .rating-badge {
            position: absolute; bottom: 5px; right: 5px;
            background: rgba(0,0,0,0.7); color: #ffcc00;
            padding: 4px 8px; border-radius: 5px;
            font-weight: bold; font-size: 0.9rem;
            display: flex; align-items: center; gap: 4px;
        }
        .status-badge {
            position: absolute; top: 5px; left: 5px;
            background: rgba(0,0,0,0.7); color: white;
            padding: 4px 8px; border-radius: 5px; font-size: 0.8rem;
        }
        .title {
            margin-top: 8px; text-align: center; font-size: 0.95rem;
            color: #bbb; white-space: normal; overflow-wrap: anywhere; word-break: break-word;
        }
        .grid-item button:focus + .title { color: var(--focus-color); font-weight: bold; }
    </style>
</head>
<body>

<div id="loading-overlay">
    <div class="spinner"></div>
    <p class="loading-text">Loading <span>{$page_title}</span>&hellip;</p>
</div>

<div class="header">
    <h1>{$page_title}</h1>
    <a href="update_library.php" class="update-link">Update Library</a>
</div>

<div class="grid">
HTML;

echo str_pad('', 4096);
flush();

// ##################################################################
// INSTANT QUERY VIA SQLITE DB
// ##################################################################
try {
    $db = new PDO('sqlite:' . __DIR__ . '/media.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $movies = [];
    switch ($search_type) {
        case 'all':
            $stmt = $db->query("SELECT * FROM movies ORDER BY title COLLATE NOCASE ASC");
            $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'genre':
            $stmt = $db->prepare("SELECT * FROM movies WHERE genres LIKE :genre ORDER BY title COLLATE NOCASE ASC");
            $stmt->execute([':genre' => "%|" . $filter_value . "|%"]);
            $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'letter':
            $stmt = $db->prepare("SELECT * FROM movies WHERE title LIKE :letter ORDER BY title COLLATE NOCASE ASC");
            $stmt->execute([':letter' => $filter_value . "%"]);
            $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'comic':
            $stmt = $db->query("SELECT * FROM movies WHERE tags LIKE '%|based on comic|%' OR tags LIKE '%|comic book|%' OR tags LIKE '%|super hero|%' ORDER BY title COLLATE NOCASE ASC");
            $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'recent':
            $stmt = $db->query("SELECT * FROM movies ORDER BY mtime DESC LIMIT 30");
            $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
} catch (Exception $e) {
    echo "</div><p style='color:#ff3333;'>Database connection failed. Did you run <a href='update_library.php' style='color:#ff9900;'>update_library.php</a> first?</p>";
    $movies = [];
}

// ##################################################################
// RENDERING THE RESULTS
// ##################################################################
$userIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rawProfile = $_COOKIE['viewer_profile'] ?? '';
$cleanProfile = !empty($rawProfile) ? strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $rawProfile)) : '';
$deviceId = $_COOKIE['device_id'] ?? '';

$viewerCandidates = array_values(array_filter([
    $cleanProfile !== '' ? 'user_' . $cleanProfile : '',
    $deviceId,
    $userIP,
]));

$remoteBaseDir = $remote_video_directory !== '' ? rtrim($remote_video_directory, '/') : '';
$remoteParentDir = $remoteBaseDir !== '' ? rtrim(dirname($remoteBaseDir), '/') : '';

foreach ($movies as $movie) {
    $file         = $movie['path'];
    $title        = $movie['title'];
    $rating_value = $movie['rating'];
    $is_remote    = (int)$movie['is_remote'] === 1;

    $video_filename = pathinfo($file, PATHINFO_FILENAME);

    // --- STREAM URL & POSTER PATH LOGIC ---
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
        $movieUrl = rtrim(REMOTE_VIDEOS_PREFIX, '/') . '/' . ltrim($cleanRelative, '/');

        $relative_dir = dirname($cleanRelative);
        $nfo_dir = ($relative_dir !== '.' && !empty($relative_dir)) ? rtrim($relative_dir, '/') . '/' : '';
        $remote_poster_path = $remoteBaseDir . '/' . $nfo_dir . "{$video_filename}-poster.jpg";

        $thumbnailSrc = rtrim(REMOTE_API_PREFIX, '/') . "/image.php?image=" . urlencode($remote_poster_path);
    } else {
        $movieUrl = $file;
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

    // Progress Status Icons (checks profile -> device -> IP)
    $statusIcon = "";
    foreach ($viewerCandidates as $vid) {
        $progressFile = __DIR__ . "/IPdata/progress_{$vid}_{$video_filename}.json";
        if (file_exists($progressFile)) {
            $content = @file_get_contents($progressFile);
            if ($content) {
                $progress    = json_decode($content, true);
                $currentTime = isset($progress['currentTime']) ? (float)$progress['currentTime'] : 0.0;
                $duration    = isset($progress['duration'])    ? (float)$progress['duration']    : 0.0;
                if ($duration > 0) {
                    $pct = $currentTime / $duration;
                    if ($pct > 0.9)      $statusIcon = "✓";
                    elseif ($pct > 0.1)  $statusIcon = "👀";
                }
            }
            break;
        }
    }

    $escaped_url   = htmlspecialchars($movieUrl);
    $escaped_thumb = htmlspecialchars($thumbnailSrc);
    $escaped_title = htmlspecialchars($title);
    $remote_flag   = $is_remote ? '1' : '0';
    $rating_html   = '';
    $status_html   = '';

    if (!empty($rating_value) && (float)$rating_value > 0) {
        $escaped_rating = htmlspecialchars($rating_value);
        $rating_html = "<div class=\"rating-badge\">★ {$escaped_rating}</div>";
    }
    if ($statusIcon) {
        $escaped_icon = htmlspecialchars($statusIcon);
        $status_html  = "<div class=\"status-badge\">{$escaped_icon}</div>";
    }

    echo <<<ITEM
    <div class="grid-item">
        <form action="videoPlayer.php" method="GET">
            <input type="hidden" name="remote" value="{$remote_flag}">
            <button name="video" value="{$escaped_url}">
                <img loading="lazy" src="{$escaped_thumb}" class="poster" alt="{$escaped_title}">
                {$rating_html}
                {$status_html}
            </button>
        </form>
        <p class="title">{$escaped_title}</p>
    </div>
ITEM;
}
?>

</div><!-- /.grid -->

<script>
    (function () {
        const overlay = document.getElementById('loading-overlay');
        if (overlay) {
            overlay.style.transition = 'opacity 0.4s ease';
            overlay.style.opacity = '0';
            setTimeout(function () { overlay.style.display = 'none'; }, 420);
        }

        const first = document.querySelector('.grid-item button');
        if (first) first.focus();
    })();

    document.addEventListener('focus', function (event) {
        if (event.target.tagName === 'BUTTON') {
            event.target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        }
    }, true);
</script>

</body>
</html>