<?php
// showGridTwo.php
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
    $displayLabel = "User: <span>" . htmlspecialchars(ucfirst($cleanProfile)) . "</span>";
} else {
    $userIdentifier = $deviceId;
    $displayLabel = "Device ID: <span>" . htmlspecialchars($deviceId) . "</span>";
}

// Default icon size settings
$iconSize = (int)($_SESSION["iconSize"] ?? 150);
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["icon-size"])) {
    $iconSize = (int)$_POST["icon-size"];
    $_SESSION["iconSize"] = $iconSize;
}

// Config paths
$api_prefix       = rtrim(REMOTE_API_PREFIX, '/');
$remote_shows_dir = rtrim(REMOTE_SHOWS_DIR, '/');
$remote_base_root = $remote_shows_dir !== ''
    ? rtrim(dirname($remote_shows_dir), '/')
    : (REMOTE_VIDEOS_DIR !== '' ? rtrim(dirname(rtrim(REMOTE_VIDEOS_DIR, '/')), '/') : '');

// Logic setup
$root     = $_GET["root"] ?? '';
$folder   = $_GET["folder"] ?? '';
$isRemote = isset($_GET["remote"]) && $_GET["remote"] == "1";
$files    = [];

// --- DATA FETCHING LOGIC ---
if ($isRemote && REMOTE_ENABLED) {
    $localPort    = $_SERVER['SERVER_PORT'] ?? '80';
    $remoteApiUrl = "http://127.0.0.1:{$localPort}{$api_prefix}/list_folder.php?path=" . urlencode($root . "/" . $folder);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $remoteApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $files = json_decode($response, true) ?: [];
    }
} else {
    $attemptDir = str_replace('\\', '/', "$root/$folder");
    if (is_dir($attemptDir) && is_readable($attemptDir)) {
        $files = scandir($attemptDir) ?: [];
    }
}

// --- SORTING LOGIC ---
usort($files, function($a, $b) {
    $nameA = is_array($a) ? $a['name'] : $a;
    $nameB = is_array($b) ? $b['name'] : $b;
    return strnatcasecmp($nameA, $nameB);
});

// --- Pre-scan for files and video files ---
$allVideos     = [];
$fileNamesOnly = [];
foreach ($files as $f) {
    $fn = is_array($f) ? $f['name'] : $f;
    $fileNamesOnly[] = $fn;
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
    if ($ext == "mp4" || $ext == "mkv") {
        $allVideos[] = $fn;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($folder); ?></title>
<style>
    :root {
        --bg-color: #121212;
        --card-bg: #1e1e1e;
        --focus-color: #ff9900;
        --text-color: #e0e0e0;
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
        display: flex;
        align-items: center;
        margin-bottom: 20px;
        border-bottom: 2px solid #333;
        padding-bottom: 15px;
    }
    .header h1 {
        font-size: 2rem;
        margin-left: 15px;
        color: white;
    }
    .header-icon-fallback {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 46px;
        height: 46px;
        border-radius: 50%;
        background: #222;
        border: 1px solid #444;
        font-size: 22px;
        text-decoration: none;
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
        margin-bottom: 20px;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
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
        padding-bottom: 40px;
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
        transition: transform 0.1s;
        position: relative;
    }
    .grid-item img {
        width: 100%;
        height: auto;
        aspect-ratio: 2/3;
        object-fit: cover;
        border-radius: 8px;
        border: 3px solid transparent;
        background: #222;
    }
    a:focus, button:focus {
        outline: none;
        transform: scale(1.05);
        z-index: 10;
    }
    a:focus img, button:focus img {
        border-color: var(--focus-color);
        box-shadow: 0 0 15px rgba(255, 153, 0, 0.4);
    }
    .title {
        text-align: center;
        margin-top: 8px;
        font-size: 0.9rem;
        color: #bbb;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
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
</style>
</head>
<body>

<div class="header">
    <a href="image_generator.php?root=<?php echo urlencode($root."/".$folder."/"); ?><?php echo $isRemote ? "&remote=1" : ""; ?>">
        <?php if (file_exists(__DIR__ . '/media/cat.gif')): ?>
            <img src="media/cat.gif" width="50" height="auto" style="border-radius: 50%;" alt="Home">
        <?php else: ?>
            <span class="header-icon-fallback">🎬</span>
        <?php endif; ?>
    </a>
    <h1><?php echo htmlspecialchars($folder); ?></h1>

    <button class="user-indicator" onclick="changeProfile()" title="Click to change your profile name">
        👤 <?php echo $displayLabel; ?>
    </button>
</div>

<div class="grid">
    <?php
    foreach ($files as $file) {
        $fileName    = is_array($file) ? $file['name'] : $file;
        $isDirectory = is_array($file) ? $file['is_dir'] : (pathinfo($fileName, PATHINFO_EXTENSION) == "" && !in_array($fileName, [".", ".."]));

        // FOLDERS
        if ($isDirectory) {
            $dirStem = strtolower(pathinfo($fileName, PATHINFO_FILENAME));
            if (!in_array($dirStem, ['.', '..', '720p', 'stills'], true)) {
                $fo          = $fileName;
                $title       = pathinfo($fileName, PATHINFO_FILENAME);
                $remoteParam = $isRemote ? "&remote=1" : "";

                $defaultFolderImg = "image.php?image=media/folderIcon.png";
                $folderThumbSrc   = $defaultFolderImg;
                $altRemoteImg     = "";
                $hasFallback      = false;

                $possiblePosters = [
                    "{$title}-poster.jpg",
                    "{$title}-poster.png"
                ];

                if (preg_match('/^season\s*0*(\d+)$/i', $title, $m)) {
                    $sNum = str_pad($m[1], 2, '0', STR_PAD_LEFT);
                    $possiblePosters[] = "season{$sNum}-poster.jpg";
                    $possiblePosters[] = "season{$sNum}-poster.png";
                } elseif (preg_match('/^specials?$/i', $title)) {
                    $possiblePosters[] = "season00-poster.jpg";
                    $possiblePosters[] = "season00-poster.png";
                }

                $posterMatch = "";
                foreach ($possiblePosters as $p) {
                    if (in_array($p, $fileNamesOnly)) {
                        $posterMatch = $p;
                        break;
                    }
                }
                if ($posterMatch === "") {
                    foreach ($possiblePosters as $p) {
                        foreach ($fileNamesOnly as $fName) {
                            if (strcasecmp($fName, $p) === 0) {
                                $posterMatch = $fName;
                                break 2;
                            }
                        }
                    }
                }

                if ($isRemote) {
                    $cleanRoot   = rtrim($root, '/\\');
                    $cleanFolder = trim($folder, '/\\');

                    if ($posterMatch !== "") {
                        $remotePosterPath = "{$cleanRoot}/{$cleanFolder}/{$posterMatch}";
                        $folderThumbSrc   = "{$api_prefix}/image.php?image=" . urlencode($remotePosterPath);
                    } else {
                        $remoteLower    = "{$cleanRoot}/{$cleanFolder}/{$fileName}/foldericon.png";
                        $remoteCamel    = "{$cleanRoot}/{$cleanFolder}/{$fileName}/folderIcon.png";
                        $folderThumbSrc = "{$api_prefix}/image.php?image=" . urlencode($remoteLower);
                        $altRemoteImg   = "{$api_prefix}/image.php?image=" . urlencode($remoteCamel);
                        $hasFallback    = true;
                    }
                } else {
                    if ($posterMatch !== "") {
                        $localPoster    = "$root/$folder/$posterMatch";
                        $folderThumbSrc = "image.php?image=" . urlencode($localPoster);
                    } else {
                        $baseCheckDir     = (isset($attemptDir) ? $attemptDir : "$root/$folder") . "/{$fileName}";
                        $matchedInnerFile = null;

                        if (file_exists("{$baseCheckDir}/foldericon.png")) {
                            $matchedInnerFile = "foldericon.png";
                        } elseif (file_exists("{$baseCheckDir}/folderIcon.png")) {
                            $matchedInnerFile = "folderIcon.png";
                        }

                        if ($matchedInnerFile !== null) {
                            $localInnerIcon = "$root/$folder/{$fileName}/{$matchedInnerFile}";
                            $folderThumbSrc = "image.php?image=" . urlencode($localInnerIcon);
                        } else {
                            $folderThumbSrc = $defaultFolderImg;
                        }
                    }
                }
                ?>
                <div class="grid-item">
                    <a href="showGridTwo.php?folder=<?php echo urlencode($fo); ?>&root=<?php echo urlencode($root."/".$folder); ?><?php echo $remoteParam; ?>">
                        <img loading="lazy" src="<?php echo htmlspecialchars($folderThumbSrc); ?>"
                        <?php
                        if ($hasFallback) {
                            if (!empty($altRemoteImg)) {
                                echo 'onerror="if(!this.dataset.triedAlt){this.dataset.triedAlt=\'1\'; this.src=\'' . htmlspecialchars($altRemoteImg, ENT_QUOTES) . '\';} else {this.onerror=null; this.src=\'' . $defaultFolderImg . '\';}"';
                            } else {
                                echo 'onerror="this.onerror=null; this.src=\'' . $defaultFolderImg . '\';"';
                            }
                        }
                        ?>>
                    </a>
                    <p class="title"><?php echo htmlspecialchars($title); ?></p>
                </div>
                <?php
            }
        }
        // VIDEOS
        else {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($ext == "mp4" || $ext == "mkv") {
                $title = pathinfo($fileName, PATHINFO_FILENAME);

                if ($isRemote) {
                    $cleanRoot        = rtrim($root, '/\\');
                    $cleanFolder      = trim($folder, '/\\');
                    $remoteVideoPath  = "{$cleanRoot}/{$cleanFolder}/{$fileName}";
                    $remotePosterPath = "{$cleanRoot}/{$cleanFolder}/{$title}-poster.jpg";

                    $stripBases = $remote_base_root !== '' ? [$remote_base_root . '/', '/' . $remote_base_root . '/'] : [];
                    $cleanRemoteRelative = '/' . ltrim(str_replace($stripBases, '', $remoteVideoPath), '/');
                    $movieUrl     = $api_prefix . $cleanRemoteRelative;
                    $thumbnailSrc = "{$api_prefix}/image.php?image=" . urlencode($remotePosterPath);

                    $genUrl  = "{$api_prefix}/generate_poster.php";
                    $genPath = $remoteVideoPath;
                } else {
                    $movieUrl   = "$root/$folder/$fileName";
                    $localThumb = "$root/$folder/$title-poster.jpg";
                    if (!file_exists($localThumb)) $localThumb = "$root/$folder/$title.png";
                    $thumbnailSrc = "image.php?image=" . urlencode($localThumb);

                    $genUrl  = "generate_poster.php";
                    $genPath = "$root/$folder/$fileName";
                }

                // Progress check
                $watchStatusClass = "";
                $rawTitle         = pathinfo($fileName, PATHINFO_FILENAME);
                $safeTitle        = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawTitle);
                $progressFile     = __DIR__ . "/IPdata/progress_" . $userIdentifier . "_" . $safeTitle . ".json";

                if (!file_exists($progressFile)) {
                    $altFile = __DIR__ . "/IPdata/progress_" . $userIdentifier . "_" . $rawTitle . ".json";
                    if (file_exists($altFile)) {
                        $progressFile = $altFile;
                    }
                }

                if (file_exists($progressFile)) {
                    $jsonContent = @file_get_contents($progressFile);
                    if ($jsonContent) {
                        $progJson = json_decode($jsonContent, true);
                        $cur = isset($progJson['currentTime']) ? (float)$progJson['currentTime'] : 0.0;
                        $dur = isset($progJson['duration'])    ? (float)$progJson['duration']    : 0.0;
                        if ($dur > 0) {
                            $pct = ($cur / $dur) * 100;
                            if ($pct >= 90 || $cur == 0.13) {
                                $watchStatusClass = "status-watched";
                            } elseif ($pct > 3) {
                                $watchStatusClass = "status-partial";
                            }
                        }
                    }
                }
                ?>
                <div class="grid-item">
                    <form action="videoPlayer.php" method="GET">
                        <button name="video" value="<?php echo htmlspecialchars($movieUrl); ?>">
                            <input type="hidden" name="root" value="<?php echo htmlspecialchars($root); ?>">
                            <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder); ?>">
                            <input type="hidden" name="remote" value="<?php echo $isRemote ? '1' : '0'; ?>">

                            <?php if (!empty($watchStatusClass)): ?>
                                <div class="status-badge <?php echo $watchStatusClass; ?>"></div>
                            <?php endif; ?>

                            <img loading="lazy"
                                src="<?php echo htmlspecialchars($thumbnailSrc); ?>"
                                onerror="handleMissingImage(this, '<?php echo htmlspecialchars($genUrl, ENT_QUOTES); ?>', '<?php echo addslashes($genPath); ?>')"
                                alt="<?php echo htmlspecialchars($title); ?>">
                        </button>
                    </form>
                    <p class="title"><?php echo htmlspecialchars($title); ?></p>
                </div>
                <?php
            }
        }
    }
    ?>
</div>

<!-- SETTINGS SECTION -->
<div class="settings-bar">
    <button id="settings-toggle" class="settings-toggle">
        <span>⚙️</span> Icon Settings
    </button>
    <div id="slider-container" class="slider-container">
        <label for="icon-size" style="color:white;">Size:</label>
        <input type="range" id="icon-size" min="48" max="256" step="3" value="<?php echo (int)$iconSize; ?>">
        <span id="icon-size-output" style="color:var(--focus-color); width:50px; text-align:right;"><?php echo (int)$iconSize; ?>px</span>
    </div>
</div>

<script>
    const settingsToggle  = document.getElementById("settings-toggle");
    const sliderContainer = document.getElementById("slider-container");
    const slider          = document.getElementById("icon-size");
    const output          = document.getElementById("icon-size-output");

    settingsToggle.addEventListener("click", () => {
        sliderContainer.classList.toggle("show");
    });

    slider.addEventListener("input", function() {
        output.textContent = this.value + "px";
    });

    slider.addEventListener("change", function() {
        fetch(`set_icon_size.php?value=${this.value}`)
            .then(response => response.text())
            .then(() => { location.reload(); })
            .catch(err => console.error(err));
    });

    function changeProfile() {
        const current = <?php echo json_encode($_COOKIE['viewer_profile'] ?? ''); ?>;
        const promptMsg = current
            ? `Current profile: "${current}"\n\nEnter a new name to switch, or leave blank to reset to Device ID:`
            : "Enter your name (e.g. Alex, Mom, Sam) to sync your watch progress across all your devices:";

        const name = prompt(promptMsg, current);
        if (name !== null) {
            window.location.href = `set_profile.php?name=${encodeURIComponent(name.trim())}`;
        }
    }

    const generating = new Set();
    function handleMissingImage(imgElement, apiUrl, videoPath) {
        imgElement.onerror = null;
        imgElement.src = "media/folderIcon.png";

        if (generating.has(videoPath)) return;
        generating.add(videoPath);

        fetch(`${apiUrl}?video=${encodeURIComponent(videoPath)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    setTimeout(() => {
                        let base = apiUrl.replace('generate_poster.php', 'image.php');
                        let remoteImg = videoPath.replace(/\.(mp4|mkv)$/i, '-poster.jpg');
                        imgElement.src = `${base}?image=${encodeURIComponent(remoteImg)}`;
                    }, 1000);
                }
            })
            .catch(err => console.error("Poster generation error:", err));
    }
</script>
</body>
</html>