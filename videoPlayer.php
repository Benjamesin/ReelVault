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

$video = $_GET["video"] ?? '';
$root = $_GET["root"] ?? '';
$folder = $_GET["folder"] ?? '';
$isRemoteParam = isset($_GET["remote"]) && $_GET["remote"] == "1";

// =========================================================================
// 1. IDENTITY RESOLUTION: User Name (Overrides) -> Device ID (Fallback)
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
} else {
    $userIdentifier = $deviceId;
}

// Mount & Web Path Configuration (from config.php)
$local_videos_mount = rtrim(LOCAL_VIDEOS_DIR, '/') . '/';
$local_shows_mount  = rtrim(LOCAL_SHOWS_DIR, '/') . '/';
$remote_videos_dir  = rtrim(REMOTE_VIDEOS_DIR, '/');
$remote_base_root   = $remote_videos_dir !== ''
    ? rtrim(dirname($remote_videos_dir), '/')
    : (REMOTE_SHOWS_DIR !== '' ? rtrim(dirname(rtrim(REMOTE_SHOWS_DIR, '/')), '/') : '');

$web_videos_path = rtrim(WEB_VIDEOS_PATH, '/') . '/';
$web_shows_path  = rtrim(WEB_SHOWS_PATH, '/') . '/';
$api_prefix      = rtrim(REMOTE_API_PREFIX, '/');
$vid_prefix      = rtrim(REMOTE_VIDEOS_PREFIX, '/');
$localPort       = $_SERVER['SERVER_PORT'] ?? '80';

// =========================================================================
// 2. REMOTE VIDEO DETECTION & PATH SETUP
// =========================================================================
$is_remote_video = (
    str_starts_with($video, 'http://') ||
    str_starts_with($video, 'https://') ||
    str_starts_with($video, '/remote-') ||
    ($api_prefix !== '' && str_starts_with($video, $api_prefix . '/')) ||
    ($vid_prefix !== '' && str_starts_with($video, $vid_prefix . '/')) ||
    $isRemoteParam
);

if ($is_remote_video) {
    // --- REMOTE LOGIC ---
    $cleanVideo = $video;
    if (str_starts_with($cleanVideo, 'http://') || str_starts_with($cleanVideo, 'https://')) {
        $cleanVideo = parse_url($cleanVideo, PHP_URL_PATH) ?? '';
    }

    $cleanPath = preg_replace('#^(' . preg_quote($api_prefix, '#') . '|' . preg_quote($vid_prefix, '#') . '|/remote-api|/remote-videos)/?#', '', $cleanVideo);
    if ($vid_prefix !== '' && str_starts_with($video, $vid_prefix)) {
        $cleanPath = 'videos/' . ltrim($cleanPath, '/');
    }
    $cleanPath = ltrim($cleanPath, '/');

    $remoteFsPath = ($remote_base_root !== '' ? $remote_base_root . '/' : '') . $cleanPath;
    $thumbnail = dirname($remoteFsPath) . "/" . pathinfo($video, PATHINFO_FILENAME) . "-fanart.jpg";

    $subFileName = str_replace(['.mp4', '.mkv'], '', $cleanPath) . ".vtt";
    $subtitles = "get_subtitle.php?file=" . urlencode($subFileName);

    $videoSrc = $video;
    $video720pSrc = dirname($video) . "/720p/" . basename($video);
    $absoluteVideoUrl = $video;

} else {
    // --- LOCAL LOGIC ---
    $thumbnail = str_replace(['.mp4', '.mkv'], '', $video) . "-fanart.jpg";
    $alt = str_replace(['.mp4', '.mkv'], '', $video) . ".png";
    if (!file_exists($thumbnail)) {
        $thumbnail = $alt;
    }

    // Map local filesystem path to web path (supports both videos and shows)
    if (str_starts_with($video, $local_shows_mount)) {
        $videoSrc = str_replace($local_shows_mount, $web_shows_path, $video);
        $cleanLocal = 'shows/' . ltrim(str_replace($local_shows_mount, '', $video), '/');
        $video720p_fs = dirname($video) . "/720p/" . basename($video);
        $video720pSrc = file_exists($video720p_fs) ? str_replace($local_shows_mount, $web_shows_path, $video720p_fs) : $videoSrc;
    } else {
        $videoSrc = str_replace($local_videos_mount, $web_videos_path, $video);
        $cleanLocal = ltrim(str_replace($local_videos_mount, '', $video), '/');
        $video720p_fs = dirname($video) . "/720p/" . basename($video);
        $video720pSrc = file_exists($video720p_fs) ? str_replace($local_videos_mount, $web_videos_path, $video720p_fs) : $videoSrc;
    }

    $subFileName = str_replace(['.mp4', '.mkv'], '', $cleanLocal) . ".vtt";
    $subtitles = "get_subtitle.php?file=" . urlencode($subFileName);

    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $cleanSrc = ltrim($videoSrc, '/\\');
    $absoluteVideoUrl = "$protocol://$host$dir/$cleanSrc";
}

// =========================================================================
// 3. DYNAMICALLY FIND NEXT VIDEO
// =========================================================================
$nextVideoUrl = "";
if (!empty($root) && !empty($folder)) {
    $fileList = [];

    if ($is_remote_video && REMOTE_ENABLED) {
        $remoteApiUrl = "http://127.0.0.1:{$localPort}{$api_prefix}/list_folder.php?path=" . urlencode($root . "/" . $folder);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $remoteApiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                foreach ($data as $f) {
                    $fn = is_array($f) ? $f['name'] : $f;
                    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                    if ($ext == 'mp4' || $ext == 'mkv') $fileList[] = $fn;
                }
            }
        }
    } else {
        $scanDir = str_replace('\\', '/', "$root/$folder");
        if (is_dir($scanDir)) {
            $rawFiles = scandir($scanDir);
            foreach ($rawFiles as $f) {
                if ($f == '.' || $f == '..') continue;
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if ($ext == 'mp4' || $ext == 'mkv') $fileList[] = $f;
            }
        }
    }

    $currentFileName = basename($is_remote_video ? (parse_url($video, PHP_URL_PATH) ?? '') : $video);
    $key = array_search($currentFileName, $fileList);

    if ($key !== false && isset($fileList[$key + 1])) {
        $nextFileName = $fileList[$key + 1];
        if ($is_remote_video) {
            $cleanRoot = rtrim($root, '/\\');
            $cleanFolder = trim($folder, '/\\');
            $remotePath = "{$cleanRoot}/{$cleanFolder}/{$nextFileName}";

            $stripBases = $remote_base_root !== '' ? [$remote_base_root . '/', '/' . $remote_base_root . '/'] : [];
            $cleanRemoteRelative = '/' . ltrim(str_replace($stripBases, '', $remotePath), '/');
            $nextVideoUrl = $api_prefix . $cleanRemoteRelative;
        } else {
            $nextVideoUrl = "$root/$folder/$nextFileName";
        }
        $nextParams = [
            'video'  => $nextVideoUrl,
            'root'   => $root,
            'folder' => $folder,
            'remote' => $is_remote_video ? '1' : '0'
        ];
        $nextLink = "videoPlayer.php?" . http_build_query($nextParams);
    }
}

// =========================================================================
// 4. BACKGROUND STILLS GENERATION TRIGGER
// =========================================================================
if (!$is_remote_video) {
    $videoFsPath = realpath($video) ?: $video;
    $videoFilename = pathinfo($videoFsPath, PATHINFO_FILENAME);
    $stillsDir = dirname($videoFsPath) . DIRECTORY_SEPARATOR . 'stills' . DIRECTORY_SEPARATOR . $videoFilename;

    $hasStills = false;
    if (is_dir($stillsDir)) {
        $files = scandir($stillsDir);
        if ($files) {
            foreach ($files as $f) {
                if (preg_match('/\.(jpg|jpeg|png)$/i', $f)) {
                    $hasStills = true;
                    break;
                }
            }
        }
    }

    if (!$hasStills) {
        $script = __DIR__ . DIRECTORY_SEPARATOR . 'generate_stills.php';
        $phpCli = 'php';
        if (defined('PHP_BINARY')) {
            $binary = PHP_BINARY;
            if (stripos($binary, 'httpd') === false && stripos($binary, 'apache') === false && stripos($binary, 'fpm') === false) {
                $phpCli = $binary;
            }
        }

        $cmd = escapeshellarg($phpCli) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($videoFsPath);
        $launchLog = __DIR__ . DIRECTORY_SEPARATOR . 'stills_launch.txt';
        $backgroundCmd = $cmd . ' >> ' . escapeshellarg($launchLog) . ' 2>&1 &';
        exec($backgroundCmd);
    }
} elseif (REMOTE_ENABLED) {
    $remotePath = parse_url($video, PHP_URL_PATH) ?? '';
    $triggerUrl = "http://127.0.0.1:{$localPort}{$api_prefix}/generate_stills_trigger.php?path=" . urlencode($remotePath);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $triggerUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_exec($ch);
    curl_close($ch);
}
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
<style>
    body {
        background-color: #000;
        margin: 0;
        padding: 0;
        overflow: hidden;
        font-family: Arial, sans-serif;
    }
    #video-container {
        position: relative;
        width: 100vw;
        height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        background: black;
    }
    video {
        width: 100%;
        height: 100%;
        object-fit: contain;
        position: relative;
        z-index: 2;
    }
    #poster-bg {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-size: cover;
        background-position: center;
        opacity: 0.3;
        z-index: 0;
        pointer-events: none;
    }
    .controls-overlay {
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        background: linear-gradient(to top, rgba(0,0,0,0.95), transparent);
        padding: 20px 40px;
        box-sizing: border-box;
        opacity: 0;
        transition: opacity 0.3s ease;
        display: flex;
        flex-direction: column;
        gap: 15px;
        z-index: 10;
    }
    .controls-overlay.visible { opacity: 1; }

    .progress-container {
        width: 100%;
        height: 12px;
        background: rgba(255,255,255,0.2);
        border-radius: 6px;
        position: relative;
        cursor: pointer;
    }
    .progress-fill {
        height: 100%;
        background: #ff9900;
        width: 0%;
        border-radius: 6px;
    }
    .buttons-row {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 25px;
    }
    button, .icon-btn {
        background: transparent;
        border: 2px solid transparent;
        color: white;
        font-size: 24px;
        width: 55px;
        height: 55px;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        text-decoration: none;
    }
    button:focus, .icon-btn:focus {
        border-color: #ff9900;
        background: rgba(255, 153, 0, 0.2);
        transform: scale(1.15);
        outline: none;
    }
    .info-row {
        display: flex;
        justify-content: space-between;
        color: #ddd;
        font-size: 14px;
        margin-top: -10px;
        padding: 0 5px;
    }
    #quality-btn {
        font-size: 14px;
        width: auto;
        padding: 0 15px;
        border-radius: 20px;
        background: rgba(255,255,255,0.1);
    }
    #loading {
        position: absolute;
        z-index: 5;
        color: white;
        font-size: 20px;
        background: rgba(0,0,0,0.7);
        padding: 15px 30px;
        border-radius: 10px;
        display: none;
    }

    /* PREVIEW THUMB */
    #preview-thumb {
        position: absolute;
        bottom: 40px;
        left: 0;
        transform: translateX(-50%);
        pointer-events: none;
        z-index: 20;
        display: none;
        flex-direction: column;
        align-items: center;
        gap: 4px;
    }
    #preview-img {
        width: 160px;
        height: 90px;
        border-radius: 6px;
        box-shadow: 0 6px 18px rgba(0,0,0,0.6);
        background: #000;
        background-repeat: no-repeat;
        background-size: contain;
        background-position: 0 0;
    }
    #preview-time {
        background: rgba(0,0,0,0.7);
        color: #fff;
        font-size: 12px;
        padding: 3px 6px;
        border-radius: 4px;
    }

    /* NEXT EPISODE OVERLAY */
    #next-episode-overlay {
        position: absolute;
        bottom: 120px;
        right: 5vw;
        background: rgba(0, 0, 0, 0.85);
        border: 2px solid #333;
        padding: 15px 25px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 15px;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.5s ease, transform 0.5s ease;
        transform: translateY(20px);
        z-index: 9999;
    }
    #next-episode-overlay.show {
        opacity: 1;
        pointer-events: auto;
        transform: translateY(0);
    }
    .next-btn-text { color: #fff; font-size: 16px; font-weight: bold; }
    .next-btn-sub { color: #aaa; font-size: 12px; }
    #btn-next-ep { background: #ff9900; color: black; width: 45px; height: 45px; font-size: 18px; }
    #btn-next-ep:hover, #btn-next-ep:focus { background: white; transform: scale(1.1); }

    /* ADVANCED SETTINGS MENU */
    #settings-modal {
        display: none;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: #1a1a1a;
        border: 2px solid #333;
        padding: 25px;
        border-radius: 10px;
        z-index: 10000;
        min-width: 300px;
        box-shadow: 0 0 30px rgba(0,0,0,0.8);
        text-align: center;
        color: #eee;
    }
    #settings-modal h3 { margin-top: 0; color: #ff9900; margin-bottom: 20px; }
    .setting-group { margin-bottom: 20px; }
    .setting-label { font-size: 14px; color: #aaa; margin-bottom: 8px; display: block; }
    .sync-controls {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    .sync-controls button {
        font-size: 12px;
        width: 40px;
        height: 40px;
        background: #333;
        border: 1px solid #555;
    }
    .sync-controls button:hover, .sync-controls button:focus {
        background: #ff9900;
        color: black;
    }
    #sync-value { font-family: monospace; font-size: 16px; width: 60px; text-align: center; }

    .close-modal {
        margin-top: 15px;
        font-size: 14px;
        width: 100%;
        height: 40px;
        border-radius: 4px;
        background: #333;
    }
    .close-modal:hover, .close-modal:focus { background: #555; }
    #resolutionIndicator {
        position: absolute;
        bottom: 8px;
        right: 12px;
        font-size: 12px;
        color: rgba(200,200,200,0.8);
        background: rgba(0,0,0,0.4);
        padding: 2px 6px;
        border-radius: 6px;
        pointer-events: none;
        font-family: sans-serif;
        transition: opacity 0.3s ease;
    }

    /* FRAME CARDS OVERLAY */
    #frame-cards-overlay {
        position: absolute;
        bottom: 140px;
        left: 50%;
        transform: translateX(-50%);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 999;
        background: rgba(0,0,0,0.75);
        padding: 15px 25px;
        border-radius: 12px;
        backdrop-filter: blur(5px);
        transition: opacity 0.2s ease, visibility 0.2s ease;
        pointer-events: none;
        border: 1px solid #333;
        opacity: 0;
        visibility: hidden;
    }
    #frame-cards-overlay.visible {
        opacity: 1;
        visibility: visible;
    }

    .cards-wrapper {
        display: flex;
        gap: 15px;
        align-items: flex-end;
    }
    .card {
        width: 120px;
        height: 68px;
        background: #111;
        border: 2px solid #444;
        border-radius: 6px;
        overflow: hidden;
        position: relative;
        transition: all 0.2s ease;
        opacity: 0.5;
        display: flex;
        flex-direction: column;
        justify-content: flex-end;
    }
    .card .card-img {
        width: 100%;
        height: 100%;
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
    }
    .card-time {
        position: absolute;
        bottom: 4px;
        right: 4px;
        background: rgba(0,0,0,0.8);
        color: #fff;
        font-size: 10px;
        padding: 2px 4px;
        border-radius: 3px;
        font-family: monospace;
    }
    .card.active {
        width: 180px;
        height: 101px;
        border-color: #ff9900;
        box-shadow: 0 0 20px rgba(255, 153, 0, 0.6);
        opacity: 1;
        transform: scale(1.05);
    }
    .card.active .card-time {
        font-size: 12px;
        padding: 3px 6px;
    }
    .card-label {
        position: absolute;
        top: 4px;
        left: 4px;
        background: #ff9900;
        color: #000;
        font-size: 10px;
        font-weight: bold;
        padding: 1px 4px;
        border-radius: 3px;
    }
</style>
</head>
<body>

<div id="video-container">

    <?php
        $imgSrc = $is_remote_video
            ? $api_prefix . "/image.php?image=" . urlencode($thumbnail)
            : "image.php?image=" . urlencode($thumbnail);
    ?>

    <div id="loading"><i class="fa fa-spinner fa-spin"></i> Buffering...</div>

    <video id="video" playsinline autoplay>
        <source src="<?php echo htmlspecialchars($videoSrc); ?>" type="video/mp4">
        <track id="main-track" kind="subtitles" src="<?php echo htmlspecialchars($subtitles); ?>" srclang="en" label="English" default>
    </video>

    <!-- NEXT EPISODE OVERLAY -->
    <?php if (isset($nextLink)): ?>
    <div id="next-episode-overlay">
        <div>
            <div class="next-btn-text">Next Episode</div>
            <div class="next-btn-sub">Up Next</div>
        </div>
        <button id="btn-next-ep" onclick="goToNext()" title="Play Next"><i class="fa fa-step-forward"></i></button>
    </div>
    <?php endif; ?>

    <!-- FRAME CARDS OVERLAY -->
    <div id="frame-cards-overlay">
        <div class="cards-wrapper">
            <div class="card" id="card-prev2"><div class="card-img"></div><span class="card-time"></span></div>
            <div class="card" id="card-prev1"><div class="card-img"></div><span class="card-time"></span></div>
            <div class="card active" id="card-center"><div class="card-img"></div><span class="card-time"></span><span class="card-label">Seek Destination</span></div>
            <div class="card" id="card-next1"><div class="card-img"></div><span class="card-time"></span></div>
            <div class="card" id="card-next2"><div class="card-img"></div><span class="card-time"></span></div>
        </div>
    </div>

    <!-- SETTINGS MODAL -->
    <div id="settings-modal">
        <h3>Advanced Settings</h3>

        <div class="setting-group">
            <span class="setting-label">Subtitle Sync (Delay)</span>
            <div class="sync-controls">
                <button onclick="adjustSync(-1)" title="-1.0s">--</button>
                <button onclick="adjustSync(-0.1)" title="-0.1s">-</button>
                <span id="sync-value">0.0s</span>
                <button onclick="adjustSync(0.1)" title="+0.1s">+</button>
                <button onclick="adjustSync(1)" title="+1.0s">++</button>
            </div>
        </div>

        <button class="close-modal" onclick="toggleSettings()">Close</button>
    </div>

    <!-- CONTROLS -->
    <div class="controls-overlay" id="controls">
        <div class="info-row">
            <span id="time-display">00:00 / 00:00</span>
            <span id="status-msg"></span>
        </div>

        <div class="progress-container" id="progress-bar">
            <div class="progress-fill" id="progress-fill"></div>
            <div id="preview-thumb">
                <div id="preview-img"></div>
                <div id="preview-time"></div>
            </div>
        </div>

         <div class="buttons-row">
            <div id="resolutionIndicator">1080p</div>
            <button id="btn-quality" onclick="cycleQuality()">Auto</button>
            <button onclick="restartVideo()" title="Restart"><i class="fa fa-fast-backward"></i></button>
            <button onclick="skip(-10)"><i class="fa fa-backward"></i></button>
            <button id="btn-play" onclick="togglePlay()"><i class="fa fa-play"></i></button>
            <button onclick="skip(10)"><i class="fa fa-forward"></i></button>

            <?php if (isset($nextLink)): ?>
            <button onclick="goToNext()" title="Next Episode"><i class="fa fa-step-forward"></i></button>
            <?php endif; ?>

            <button id="btn-subtitles" onclick="toggleSubtitles()" title="Subtitles"><i class="fa fa-cc"></i></button>
            <button id="btn-settings" onclick="toggleSettings()" title="Advanced Settings"><i class="fa fa-cog"></i></button>

            <!-- AIRPLAY BUTTON (WebKit/Apple Only) -->
            <button id="btn-airplay" onclick="triggerAirPlay()" title="AirPlay" style="display: none;">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
                    <path d="M6 2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-3v-2h3V4H6v10h3v2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm6 13l6 6H6l6-6z"/>
                </svg>
            </button>

            <!-- FULLSCREEN BUTTON -->
            <button onclick="toggleFullscreen()"><i class="fa fa-expand"></i></button>
        </div>
    </div>
</div>

<script>
    const video = document.getElementById('video');
    const controls = document.getElementById('controls');
    const playBtnIcon = document.querySelector('#btn-play i');
    const progressBar = document.getElementById('progress-fill');
    const progressContainerEl = document.getElementById('progress-bar');
    const timeDisplay = document.getElementById('time-display');
    const qualityBtn = document.getElementById('btn-quality');
    const loading = document.getElementById('loading');
    const previewThumb = document.getElementById('preview-thumb');
    const previewImg = document.getElementById('preview-img');
    const previewTime = document.getElementById('preview-time');
    const nextOverlay = document.getElementById('next-episode-overlay');
    const nextBtn = document.getElementById('btn-next-ep');
    const airplayBtn = document.getElementById('btn-airplay');

    const settingsModal = document.getElementById('settings-modal');
    const syncValueDisplay = document.getElementById('sync-value');

    const highSource = <?php echo json_encode($videoSrc); ?>;
    const lowSource = <?php echo json_encode($video720pSrc); ?>;
    const userIdentifier = <?php echo json_encode($userIdentifier); ?>;

    const videoName = <?php echo json_encode(preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($video, PATHINFO_FILENAME))); ?>;
    const nextVideoUrl = <?php echo isset($nextLink) ? json_encode($nextLink) : "null"; ?>;

    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
                  (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    let hideControlsTimeout;
    let qualityState = 0;
    let autoModeActive = true;
    let currentQuality = "high";
    let bufferingEvents = 0;
    let lastSwitchTime = 0;
    let autoStableTimer = null;

    const AUTO_BUFFER_THRESHOLD = 3;
    const AUTO_BUFFERING_LIMIT = 2;
    const AUTO_STABLE_TIME = 15000;
    const AUTO_SWITCH_COOLDOWN = 10000;
    let has720p = false;
    let stillsList = [];
    let isScrubbing = false;
    let userInteractionCooldown = false;
    let interactionTimer = null;
    const AUTO_INTERACTION_GRACE = 4000;

    let currentSyncOffset = 0.0;
    let isVisualSkipping = false;
    let visualSeekTime = 0;
    let visualSeekTimer = null;
    let visualHideTimer = null;
    const VISUAL_SEEK_DEBOUNCE = 450;
    const VISUAL_HIDE_DEBOUNCE = 1500;
    const stillsUrl = 'get_stills.php?video=' + encodeURIComponent(<?php echo json_encode($video); ?>);
    let spritesData = null;
    const previewDisplayW = 160, previewDisplayH = 90;

    window.onload = function() {
        video.play().then(() => {
            playBtnIcon.className = "fa fa-pause";
        }).catch(() => {
            playBtnIcon.className = "fa fa-play";
        });

        fetch(lowSource, { method: 'HEAD' }).then(res => {
            if (res.ok) has720p = true;
        }).catch(() => {});

        document.getElementById('btn-play').focus();
        loadProgress();

        const trackElement = document.getElementById('main-track');
        if (trackElement) {
            trackElement.addEventListener('load', function() {
                const track = video.textTracks[0];
                track.mode = 'showing';
                if (track && track.cues && track.cues.length > 0) {
                    Array.from(track.cues).forEach(c => {
                        c.snapToLines = false;
                        c.line = 50;
                    });
                }
            });
        }

        fetch(stillsUrl).then(r => r.json()).then(j => {
            if (j && j.type === 'sprites' && Array.isArray(j.sprites)) spritesData = j;
            else if (j && Array.isArray(j.stills)) stillsList = j.stills;
        }).catch(() => {});

        video.addEventListener('loadedmetadata', () => {
            if (video.textTracks && video.textTracks.length > 0) {
                video.textTracks[0].mode = 'showing';
                const subBtn = document.getElementById('btn-subtitles');
                if (subBtn) subBtn.style.opacity = '1';
            }
        }, { once: true });

        progressContainerEl.addEventListener('mousemove', function(e) {
            showControls();
            if (!video.duration) return;
            const rect = progressContainerEl.getBoundingClientRect();
            let pct = (e.clientX - rect.left) / rect.width;
            if (pct < 0) pct = 0; if (pct > 1) pct = 1;

            if (spritesData) {
                const total = spritesData.total || 0;
                if (total > 0) {
                    const globalIdx = Math.floor(pct * total);
                    let spriteObj = spritesData.sprites.find(s => globalIdx >= s.start_index && globalIdx < s.start_index + s.count);
                    if (spriteObj) {
                        const localIdx = globalIdx - spriteObj.start_index;
                        const cols = spriteObj.columns;
                        const row = Math.floor(localIdx / cols);
                        const col = localIdx % cols;

                        previewImg.style.backgroundImage = `url("${spriteObj.url}")`;
                        const bgW = cols * previewDisplayW;
                        const bgH = Math.ceil(spriteObj.count / cols) * previewDisplayH;
                        previewImg.style.backgroundSize = `${bgW}px ${bgH}px`;
                        previewImg.style.backgroundPosition = `-${col * previewDisplayW}px -${row * previewDisplayH}px`;
                        previewTime.textContent = formatTime(pct * video.duration);

                        previewThumb.style.left = (pct * 100) + '%';
                        previewThumb.style.display = 'flex';
                    }
                }
            } else if (stillsList && stillsList.length > 0) {
                const idx = Math.floor(pct * stillsList.length);
                const safeIdx = Math.max(0, Math.min(stillsList.length - 1, idx));
                if (stillsList[safeIdx]) {
                    previewImg.style.backgroundImage = `url("${stillsList[safeIdx]}")`;
                    previewImg.style.backgroundSize = 'cover';
                    previewTime.textContent = formatTime(pct * video.duration);

                    previewThumb.style.left = (pct * 100) + '%';
                    previewThumb.style.display = 'flex';
                }
            }
            if (isScrubbing) video.currentTime = pct * video.duration;
        });

        function seekFromEvent(e) {
            if (!video.duration) return;
            const rect = progressContainerEl.getBoundingClientRect();
            const clientX = (e.touches && e.touches.length > 0) ? e.touches[0].clientX : e.clientX;
            let pct = (clientX - rect.left) / rect.width;
            if (pct < 0) pct = 0;
            if (pct > 1) pct = 1;

            video.currentTime = pct * video.duration;
            progressBar.style.width = (pct * 100) + "%";
            showControls();
        }

        progressContainerEl.addEventListener('mouseleave', () => { previewThumb.style.display = 'none'; });

        progressContainerEl.addEventListener('mousedown', (e) => {
            isScrubbing = true;
            markUserInteraction();
            seekFromEvent(e);
        });

        progressContainerEl.addEventListener('click', (e) => {
            seekFromEvent(e);
        });

        document.addEventListener('mouseup', () => {
            if (isScrubbing) {
                isScrubbing = false;
                previewThumb.style.display = 'none';
                markUserInteraction();
            }
        });

        progressContainerEl.addEventListener('touchstart', (e) => {
            isScrubbing = true;
            markUserInteraction();
            seekFromEvent(e);
        }, { passive: true });

        progressContainerEl.addEventListener('touchmove', (e) => {
            if (isScrubbing) seekFromEvent(e);
        }, { passive: true });

        document.addEventListener('touchend', () => {
            if (isScrubbing) {
                isScrubbing = false;
                previewThumb.style.display = 'none';
                markUserInteraction();
            }
        });
    };

    if (window.WebKitPlaybackTargetAvailabilityEvent && airplayBtn) {
        video.addEventListener('webkitplaybacktargetavailabilitychanged', function(event) {
            airplayBtn.style.display = (event.availability === 'available') ? 'flex' : 'none';
        });
    }

    function triggerAirPlay() {
        if (video.webkitShowPlaybackTargetPicker) {
            video.webkitShowPlaybackTargetPicker();
        }
    }

    function toggleSettings() {
        if (settingsModal.style.display === "block") {
            settingsModal.style.display = "none";
            document.getElementById('btn-settings').focus();
        } else {
            settingsModal.style.display = "block";
            setTimeout(() => settingsModal.querySelector('button').focus(), 100);
        }
    }

    function markUserInteraction() {
        userInteractionCooldown = true;
        if (interactionTimer) clearTimeout(interactionTimer);
        interactionTimer = setTimeout(() => {
            userInteractionCooldown = false;
        }, AUTO_INTERACTION_GRACE);
    }

    function adjustSync(seconds) {
        currentSyncOffset += seconds;
        currentSyncOffset = Math.round(currentSyncOffset * 10) / 10;
        syncValueDisplay.innerText = (currentSyncOffset > 0 ? "+" : "") + currentSyncOffset.toFixed(1) + "s";
        applySubtitleOffset(seconds);
    }

    function applySubtitleOffset(deltaSeconds) {
        const track = video.textTracks[0];
        if (!track || !track.cues) return;
        Array.from(track.cues).forEach(cue => {
            cue.startTime += deltaSeconds;
            cue.endTime += deltaSeconds;
        });
    }

    function reApplyTotalSync() {
        if (currentSyncOffset === 0) return;
        let attempts = 0;
        const interval = setInterval(() => {
            const track = video.textTracks[0];
            if (track && track.cues && track.cues.length > 0) {
                Array.from(track.cues).forEach(cue => {
                    cue.startTime += currentSyncOffset;
                    cue.endTime += currentSyncOffset;
                });
                clearInterval(interval);
            }
            attempts++;
            if (attempts > 50) clearInterval(interval);
        }, 100);
    }

    function toggleSubtitles() {
        const track = video.textTracks[0];
        if (!track) return;
        if (track.mode === 'hidden' || track.mode === 'showing') {
            track.mode = 'disabled';
            document.getElementById('btn-subtitles').style.opacity = '0.5';
        } else {
            track.mode = 'showing';
            document.getElementById('btn-subtitles').style.opacity = '1';
        }
    }

    function goToNext() {
        video.currentTime = 0.13;
        saveProgress();
        setTimeout(function() {
            if (nextVideoUrl) window.location.replace(nextVideoUrl);
        }, 150);
    }

    document.addEventListener('keydown', function(e) {
        showControls();
        if (settingsModal.style.display === "block") {
            if (e.keyCode === 27 || e.keyCode === 8) toggleSettings();
            return;
        }

        switch (e.keyCode) {
            case 13: case 23:
                if (isVisualSkipping && document.activeElement.tagName !== 'BUTTON') {
                    if (visualSeekTimer) clearTimeout(visualSeekTimer);
                    if (visualHideTimer) clearTimeout(visualHideTimer);
                    video.currentTime = visualSeekTime;
                    isVisualSkipping = false;
                    document.getElementById('frame-cards-overlay').classList.remove('visible');
                    video.play();
                    playBtnIcon.className = "fa fa-pause";
                    e.preventDefault();
                } else if (document.activeElement === document.body || document.activeElement === video) {
                    togglePlay();
                }
                break;
            case 37: if (document.activeElement.tagName !== 'BUTTON' && document.activeElement.tagName !== 'A') skip(-10); break;
            case 39: if (document.activeElement.tagName !== 'BUTTON' && document.activeElement.tagName !== 'A') skip(10); break;
            case 179: case 85: togglePlay(); break;
            case 227: skip(-10); break;
            case 228: skip(10); break;
            case 176: case 87: goToNext(); break;
        }
    });

    video.addEventListener('waiting', () => {
        if (!autoModeActive || !has720p || userInteractionCooldown) return;
        bufferingEvents++;
        const now = Date.now();
        if (bufferingEvents >= AUTO_BUFFERING_LIMIT && currentQuality === "high" && now - lastSwitchTime > AUTO_SWITCH_COOLDOWN) {
            switchSource(lowSource, video.currentTime, !video.paused);
            currentQuality = "low";
            bufferingEvents = 0;
        }
    });

    video.addEventListener('seeking', () => { markUserInteraction(); });

    video.addEventListener('playing', () => {
        bufferingEvents = 0;
        if (!autoModeActive || currentQuality !== "low" || userInteractionCooldown) return;
        if (autoStableTimer) clearTimeout(autoStableTimer);

        autoStableTimer = setTimeout(() => {
            const bufferAhead = getBufferAhead();
            if (bufferAhead >= AUTO_BUFFER_THRESHOLD && Date.now() - lastSwitchTime > AUTO_SWITCH_COOLDOWN) {
                switchSource(highSource, video.currentTime, !video.paused);
                currentQuality = "high";
            }
        }, AUTO_STABLE_TIME);
    });

    function togglePlay() {
        if (video.paused) {
            video.play();
            playBtnIcon.className = "fa fa-pause";
        } else {
            video.pause();
            saveProgress();
            playBtnIcon.className = "fa fa-play";
            showControls(false);
        }
    }

    function skip(seconds) {
        if ((stillsList && stillsList.length > 0) || (spritesData && spritesData.sprites)) {
            triggerVisualSkip(seconds);
        } else {
            video.currentTime += seconds;
            showControls();
        }
    }

    function restartVideo() {
        video.currentTime = 0;
        video.play();
        playBtnIcon.className = "fa fa-pause";
        showControls();
    }

    function toggleFullscreen() {
        if (isIOS && video.webkitEnterFullscreen) {
            video.webkitEnterFullscreen();
        } else if (!document.fullscreenElement) {
            if (document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen();
            } else if (video.webkitRequestFullscreen) {
                video.webkitRequestFullscreen();
            } else if (video.webkitEnterFullscreen) {
                video.webkitEnterFullscreen();
            }
        } else if (document.exitFullscreen) {
            document.exitFullscreen();
        }
    }

    video.addEventListener('webkitendfullscreen', () => {
        saveProgress();
        if (video.paused) playBtnIcon.className = "fa fa-play";
    });

    function showControls(autoHide = true) {
        controls.classList.add('visible');
        clearTimeout(hideControlsTimeout);
        document.body.style.cursor = 'default';
        if (autoHide && !video.paused && settingsModal.style.display !== "block") {
            const hideDelay = document.fullscreenElement ? 2000 : 3500;
            hideControlsTimeout = setTimeout(() => {
                controls.classList.remove('visible');
                document.body.style.cursor = 'none';
                if (document.activeElement && document.activeElement !== nextBtn) document.activeElement.blur();
            }, hideDelay);
        }
    }

    function triggerVisualSkip(seconds) {
        showControls();
        markUserInteraction();

        if (!isVisualSkipping) {
            isVisualSkipping = true;
            visualSeekTime = video.currentTime;
        }

        visualSeekTime += seconds;
        if (visualSeekTime < 0) visualSeekTime = 0;
        if (visualSeekTime > video.duration) visualSeekTime = video.duration;

        updateFrameCards(visualSeekTime);

        const cardsOverlay = document.getElementById('frame-cards-overlay');
        cardsOverlay.classList.add('visible');

        if (visualSeekTimer) clearTimeout(visualSeekTimer);
        visualSeekTimer = setTimeout(() => {
            video.currentTime = visualSeekTime;
        }, VISUAL_SEEK_DEBOUNCE);

        if (visualHideTimer) clearTimeout(visualHideTimer);
        visualHideTimer = setTimeout(() => {
            isVisualSkipping = false;
            cardsOverlay.classList.remove('visible');
        }, VISUAL_HIDE_DEBOUNCE);
    }

    function updateFrameCards(centerTime) {
        const offsets = [-20, -10, 0, 10, 20];
        const cardIds = ['card-prev2', 'card-prev1', 'card-center', 'card-next1', 'card-next2'];

        offsets.forEach((offset, idx) => {
            const targetTime = centerTime + offset;
            const cardEl = document.getElementById(cardIds[idx]);
            if (!cardEl) return;

            const timeEl = cardEl.querySelector('.card-time');
            const imgEl = cardEl.querySelector('.card-img');

            if (targetTime < 0 || targetTime > video.duration) {
                cardEl.style.opacity = '0';
                cardEl.style.pointerEvents = 'none';
                return;
            }
            cardEl.style.opacity = idx === 2 ? '1' : '0.5';
            cardEl.style.pointerEvents = 'auto';

            timeEl.textContent = formatTime(targetTime);
            const pct = targetTime / video.duration;

            if (spritesData) {
                const total = spritesData.total || 0;
                if (total > 0) {
                    const globalIdx = Math.floor(pct * total);
                    let spriteObj = spritesData.sprites.find(s => globalIdx >= s.start_index && globalIdx < s.start_index + s.count);
                    if (spriteObj) {
                        const localIdx = globalIdx - spriteObj.start_index;
                        const cols = spriteObj.columns;
                        const row = Math.floor(localIdx / cols);
                        const col = localIdx % cols;

                        imgEl.style.backgroundImage = `url("${spriteObj.url}")`;
                        const bgW = cols * previewDisplayW;
                        const bgH = Math.ceil(spriteObj.count / cols) * previewDisplayH;
                        imgEl.style.backgroundSize = `${bgW}px ${bgH}px`;
                        imgEl.style.backgroundPosition = `-${col * previewDisplayW}px -${row * previewDisplayH}px`;
                    }
                }
            } else if (stillsList && stillsList.length > 0) {
                const fileIdx = Math.floor(pct * stillsList.length);
                const safeIdx = Math.max(0, Math.min(stillsList.length - 1, fileIdx));
                if (stillsList[safeIdx]) {
                    imgEl.style.backgroundImage = `url("${stillsList[safeIdx]}")`;
                    imgEl.style.backgroundSize = 'cover';
                    imgEl.style.backgroundPosition = 'center';
                }
            }
        });
    }

    let lastInteract = Date.now();
    ['mousemove','touchmove','touchstart','keydown'].forEach(evt =>
        document.addEventListener(evt, () => {
            lastInteract = Date.now();
            showControls();
        })
    );
    setInterval(() => {
        if (!video.paused && controls.classList.contains('visible') && settingsModal.style.display !== "block") {
            if (Date.now() - lastInteract > 3500) {
                controls.classList.remove('visible');
                document.body.style.cursor = 'none';
                if (document.activeElement && document.activeElement !== nextBtn) document.activeElement.blur();
            }
        }
    }, 500);

    let lastPlayheadTime = -1;

    video.addEventListener('timeupdate', () => {
        const pct = (video.currentTime / video.duration) * 100;
        progressBar.style.width = pct + "%";
        timeDisplay.innerText = `${formatTime(video.currentTime)} / ${formatTime(video.duration)}`;

        if (video.currentTime !== lastPlayheadTime && !video.paused) {
            loading.style.display = 'none';
        }
        lastPlayheadTime = video.currentTime;

        if (nextVideoUrl) {
            const remaining = video.duration - video.currentTime;
            if (remaining < 35 && remaining > 0) {
                if (nextOverlay && !nextOverlay.classList.contains('show')) {
                    nextOverlay.classList.add('show');
                    if (nextBtn && document.activeElement === document.body) nextBtn.focus();
                }
            } else if (nextOverlay && nextOverlay.classList.contains('show')) {
                nextOverlay.classList.remove('show');
                if (document.activeElement === nextBtn) document.activeElement.blur();
            }
        }
    });

    video.addEventListener('ended', () => { if (nextVideoUrl) goToNext(); });

    function formatTime(s) {
        if (isNaN(s)) return "00:00";
        let m = Math.floor(s / 60);
        let sec = Math.floor(s % 60);
        if (m < 10) m = "0" + m;
        if (sec < 10) sec = "0" + sec;
        return m + ":" + sec;
    }

    function cycleQuality() {
        const curTime = video.currentTime;
        const wasPlaying = !video.paused;

        qualityState++;
        if (qualityState > 2) qualityState = 0;
        if (qualityState === 2 && !has720p) qualityState = 0;

        autoModeActive = (qualityState === 0);

        let src = highSource;
        let label = "Auto";
        let color = "white";

        if (qualityState === 1) {
            src = highSource;
            label = "High";
            color = "#00ff00";
            currentQuality = "high";
        }
        if (qualityState === 2) {
            src = lowSource;
            label = "720p";
            color = "#ffaa00";
            currentQuality = "low";
        }

        if (!autoModeActive) switchSource(src, curTime, wasPlaying);

        qualityBtn.innerText = label;
        qualityBtn.style.color = color;
    }

    video.addEventListener('waiting', () => loading.style.display = 'block');
    video.addEventListener('playing', () => loading.style.display = 'none');
    video.addEventListener('seeked', () => loading.style.display = 'none');
    video.addEventListener('canplay', () => loading.style.display = 'none');
    video.addEventListener('pause', () => loading.style.display = 'none');
    video.addEventListener('loadedmetadata', () => {
        resolutionIndicator.textContent = video.videoHeight + "p";
    });

    function switchSource(src, resumeTime, shouldPlay) {
        if (!src) return;
        lastSwitchTime = Date.now();
        video.src = src;
        video.currentTime = resumeTime;
        video.addEventListener('loadedmetadata', reApplyTotalSync, { once: true });
        if (shouldPlay) video.play();
    }

    function getBufferAhead() {
        if (video.buffered.length === 0) return 0;
        const end = video.buffered.end(video.buffered.length - 1);
        return end - video.currentTime;
    }

    function loadProgress() {
        if (!videoName || !userIdentifier) return;
        const url = `IPdata/progress_${encodeURIComponent(userIdentifier)}_${encodeURIComponent(videoName)}.json?t=${Date.now()}`;

        fetch(url)
            .then(response => {
                if (!response.ok) throw new Error("No progress file found");
                return response.json();
            })
            .then(data => {
                if (data && typeof data.currentTime !== 'undefined' && data.currentTime > 5) {
                    if (video.readyState >= 1) {
                        video.currentTime = data.currentTime;
                    } else {
                        video.addEventListener('loadedmetadata', () => {
                            video.currentTime = data.currentTime;
                        }, { once: true });
                    }
                }
            })
            .catch(() => {});
    }

    function saveProgress() {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'VideoProgress.php');
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        const params = `duration=${encodeURIComponent(video.duration)}&currentTime=${encodeURIComponent(video.currentTime)}&userIdentifier=${encodeURIComponent(userIdentifier)}&movie=${encodeURIComponent(videoName)}`;
        xhr.send(params);
    }
    setInterval(saveProgress, 10000);
</script>
</body>
</html>