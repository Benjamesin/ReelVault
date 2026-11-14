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

$iconSize = $_SESSION["iconSize"] ?? 140;
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["icon-size"])) {
    $iconSize = (int)$_POST["icon-size"];
    $_SESSION["iconSize"] = $iconSize;
}

$genres = [
    'Action', 'Adventure', 'Animation', 'Comedy', 'Crime', 'Drama',
    'Family', 'Fantasy', 'History', 'Horror', 'Mystery', 'Romance',
    'Science Fiction', 'Thriller', 'Tv Movie', 'War'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars(SITE_NAME); ?></title>
<style>
    :root {
        --bg-color: #121212;
        --card-bg: #1e1e1e;
        --card-hover: #2a2a2a;
        --focus-color: #ff9900;
        --text-color: #e0e0e0;
    }

    * { box-sizing: border-box; }

    body {
        background-color: var(--bg-color);
        color: var(--text-color);
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        margin: 0;
        padding: 20px 40px;
        overflow-x: hidden;
    }

    .container {
        max-width: 1800px;
        margin: 0 auto;
    }

    /* --- TOP BAR --- */
    .top-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 25px;
        border-bottom: 2px solid #2a2a2a;
        padding-bottom: 15px;
        gap: 15px;
        flex-wrap: wrap;
    }

    .header h1 {
        margin: 0;
        font-size: 2rem;
        color: #fff;
        text-transform: uppercase;
        letter-spacing: 2px;
        font-weight: 800;
    }

    .top-actions {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .nav-btn {
        background: var(--card-bg);
        border: 2px solid #333;
        color: #ddd;
        padding: 8px 18px;
        border-radius: 50px;
        font-weight: 600;
        font-size: 0.95rem;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .nav-btn.highlight {
        border-color: var(--focus-color);
        color: var(--focus-color);
        background: transparent;
    }

    .nav-btn:hover, .nav-btn:focus {
        background-color: var(--focus-color);
        border-color: var(--focus-color);
        color: #000;
        transform: scale(1.05);
        outline: none;
    }

    /* --- SECTION HEADERS --- */
    .section-header-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 35px;
        margin-bottom: 15px;
        border-left: 5px solid var(--focus-color);
        padding-left: 12px;
    }

    .section-header-row h2 {
        font-size: 1.4rem;
        color: #eee;
        margin: 0;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    /* --- QUICK ACTIONS ROW --- */
    .quick-links {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .quick-link-card a {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        background-color: var(--card-bg);
        padding: 14px 20px;
        text-decoration: none;
        color: white;
        border-radius: 8px;
        border: 2px solid #2a2a2a;
        font-size: 1rem;
        font-weight: bold;
        transition: all 0.2s ease;
    }

    .quick-link-card a:hover, .quick-link-card a:focus {
        background-color: var(--focus-color);
        border-color: var(--focus-color);
        color: #000;
        transform: scale(1.03);
        outline: none;
    }

    /* --- GENRE DRAWER & COMPACT PILLS --- */
    .genre-toggle-btn {
        background: var(--card-bg);
        border: 1px solid #444;
        color: #bbb;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }

    .genre-toggle-btn:hover, .genre-toggle-btn:focus {
        border-color: var(--focus-color);
        color: #fff;
        background: #2a2a2a;
        transform: scale(1.05);
        outline: none;
    }

    .genre-drawer {
        max-height: 0;
        overflow: hidden;
        opacity: 0;
        transition: max-height 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.25s ease, margin 0.25s ease;
        margin-bottom: 0;
    }

    .genre-drawer.open {
        max-height: 500px;
        opacity: 1;
        margin-bottom: 25px;
    }

    .genre-chips-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        gap: 10px;
        background: rgba(255, 255, 255, 0.02);
        padding: 15px;
        border-radius: 10px;
        border: 1px solid #222;
    }

    .genre-chip {
        background-color: var(--card-bg);
        border: 1px solid #333;
        color: #ccc;
        padding: 10px 14px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 600;
        text-align: center;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s ease;
    }

    .genre-chip:hover, .genre-chip:focus {
        border-color: var(--focus-color);
        background-color: var(--focus-color);
        color: #000;
        transform: scale(1.06);
        outline: none;
        z-index: 2;
    }

    /* --- VIDEO / POSTER GRIDS --- */
    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(<?php echo (int)$iconSize; ?>px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .grid-item {
        position: relative;
        display: flex;
        flex-direction: column;
    }

    .grid-item a {
        display: block;
        position: relative;
        border: 3px solid transparent;
        border-radius: 8px;
        background-color: #222;
        aspect-ratio: 3/2;
        overflow: hidden;
        transition: transform 0.15s ease-out;
    }

    .grid-item img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .grid-item a:hover, .grid-item a:focus {
        outline: none;
        border-color: var(--focus-color);
        transform: scale(1.06);
        z-index: 5;
        box-shadow: 0 0 15px rgba(255, 153, 0, 0.4);
    }

    .title {
        margin-top: 8px;
        font-size: 0.88rem;
        color: #bbb;
        text-align: center;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .locked-card {
        background-color: #250505;
        border: 1px solid #4a0d0d;
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        font-size: 2rem;
    }

    @media (max-width: 768px), (orientation: portrait) {
        body { padding: 15px; }
        .top-bar { margin-bottom: 15px; }
        .header h1 { font-size: 1.6rem; }
        .quick-links { grid-template-columns: 1fr; gap: 10px; }
        .genre-chips-grid { grid-template-columns: repeat(auto-fill, minmax(105px, 1fr)); gap: 8px; padding: 10px; }
        .genre-chip { padding: 8px 10px; font-size: 0.78rem; }
        .grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 12px; }
    }

    .profile-nav-wrapper {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .profile-warning-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background-color: #fff3cd;
        color: #856404;
        border: 1px solid #ffeeba;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.82rem;
        cursor: pointer;
        white-space: nowrap;
        transition: background-color 0.2s ease, transform 0.1s ease;
    }

    .profile-warning-badge:hover {
        background-color: #ffe8a1;
        transform: translateY(-1px);
    }

    .profile-warning-badge strong {
        text-decoration: underline;
    }
</style>
</head>
<body>

<div class="container">
    <!-- Top Navigation -->
    <div class="top-bar">
        <div class="header">
            <h1><?php echo htmlspecialchars(SITE_NAME); ?></h1>
        </div>
        <div class="top-actions">
            <?php
            $raw_profile = $_COOKIE['viewer_profile'] ?? '';
            $lower_profile = strtolower($raw_profile);
            $is_generic_device = empty($raw_profile) || str_starts_with($lower_profile, 'device_') || str_starts_with($lower_profile, 'dev_');
            $display_name = !empty($raw_profile) ? htmlspecialchars(ucfirst($raw_profile)) : 'Set Name';
            ?>

            <div class="profile-nav-wrapper">
                <button class="nav-btn highlight" onclick="changeProfile()" title="Switch user profile">
                    👤 <?php echo $display_name; ?>
                </button>

                <?php if ($is_generic_device): ?>
                    <span class="profile-warning-badge" onclick="changeProfile()" title="Click to set your profile name">
                        ⚠️ <strong>Set your name</strong> to save your progress &amp; history
                    </span>
                <?php endif; ?>
            </div>

            <button id="jump-to-shows-btn" class="nav-btn" onclick="jumpToShows()">📺 TV Shows</button>
            <a href="GridViewSearch.php" class="nav-btn">🔍 Search</a>
        </div>
    </div>

    <!-- Quick Actions Row -->
    <div class="quick-links">
        <div class="quick-link-card">
            <a href="GridViewAdaptive.php?search_type=recent">
                <span>⭐</span> Latest Movies
            </a>
        </div>
        <div class="quick-link-card">
            <a href="RecentlyWatched.php">
                <span>🕐</span> History
            </a>
        </div>
        <div class="quick-link-card">
            <a href="GridViewAdaptive.php?search_type=all">
                <span>🎬</span> All Movies
            </a>
        </div>
    </div>

    <!-- Browse by Genre (Collapsible Drawer) -->
    <div class="section-header-row">
        <h2>Genres</h2>
        <button id="genre-toggle-btn" class="genre-toggle-btn" onclick="toggleGenreDrawer()">
            <span id="genre-toggle-icon">▾</span> <span id="genre-toggle-text">Show (<?php echo count($genres); ?>)</span>
        </button>
    </div>

    <div id="genre-drawer" class="genre-drawer">
        <div class="genre-chips-grid">
            <?php foreach ($genres as $genre): ?>
                <a class="genre-chip" href="GridViewAdaptive.php?search_type=genre&filter=<?php echo urlencode($genre); ?>">
                    <?php echo htmlspecialchars($genre); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Optional Series Section -->
    <?php if (SERIES_DIR !== '' && is_dir(SERIES_DIR)): ?>
    <div class="section-header-row">
        <h2>Series</h2>
    </div>
    <div class="grid">
        <?php
        $files = scandir(SERIES_DIR) ?: [];
        foreach ($files as $file) {
            if (is_dir(SERIES_DIR . '/' . $file) && !in_array($file, [".", ".."])) {
                $icon = "media/folderIcon.png";
                $localIconLower = SERIES_DIR . "/" . $file . "/foldericon.png";
                $localIconUpper = SERIES_DIR . "/" . $file . "/folderIcon.png";

                if (file_exists($localIconLower)) {
                    $icon = $localIconLower;
                } elseif (file_exists($localIconUpper)) {
                    $icon = $localIconUpper;
                }
                ?>
                <div class="grid-item">
                    <a href="showGridTwo.php?folder=<?php echo urlencode($file); ?>&root=<?php echo urlencode(SERIES_DIR); ?>">
                        <img loading="lazy" src="image.php?image=<?php echo urlencode($icon); ?>" alt="<?php echo htmlspecialchars($file); ?>" width="150" height="100">
                    </a>
                    <p class="title"><?php echo htmlspecialchars($file); ?></p>
                </div>
                <?php
            }
        }
        ?>
    </div>
    <?php endif; ?>

    <!-- TV Shows -->
    <div class="section-header-row" id="shows-header">
        <h2>TV Shows</h2>
    </div>
    <div class="grid" id="shows">
        <?php
        // 1. LOCAL TV SHOWS
        if (LOCAL_SHOWS_DIR !== '' && is_dir(LOCAL_SHOWS_DIR) && is_readable(LOCAL_SHOWS_DIR)) {
            $files = scandir(LOCAL_SHOWS_DIR) ?: [];
            foreach ($files as $file) {
                if (is_dir(LOCAL_SHOWS_DIR . '/' . $file) && !in_array($file, [".", ".."])) {
                    $icon = "media/folderIcon.png";
                    $localLower = LOCAL_SHOWS_DIR . "/" . $file . "/foldericon.png";
                    $localUpper = LOCAL_SHOWS_DIR . "/" . $file . "/folderIcon.png";

                    if (file_exists($localLower)) {
                        $icon = $localLower;
                    } elseif (file_exists($localUpper)) {
                        $icon = $localUpper;
                    }
                    ?>
                    <div class="grid-item">
                        <a href="showGridTwo.php?folder=<?php echo urlencode($file); ?>&root=<?php echo urlencode(LOCAL_SHOWS_DIR); ?>">
                            <img loading="lazy" src="image.php?image=<?php echo urlencode($icon); ?>" alt="<?php echo htmlspecialchars($file); ?>" width="150" height="100">
                        </a>
                        <p class="title"><?php echo htmlspecialchars($file); ?></p>
                    </div>
                    <?php
                }
            }
        }

        // 2. OPTIONAL REMOTE TV SHOWS (Slave PC via Reverse Proxy)
        if (REMOTE_ENABLED && REMOTE_SHOWS_DIR !== '') {
            $remoteShows = [];
            $localPort = $_SERVER['SERVER_PORT'] ?? '80';
            $remoteApiUrl = "http://127.0.0.1:{$localPort}" . REMOTE_API_PREFIX . "/list_shows.php";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $remoteApiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            $response = curl_exec($ch);
            if ($response) {
                $remoteShows = json_decode($response, true) ?: [];
            }
            curl_close($ch);

            foreach ($remoteShows as $show) {
                $title = $show['name'];
                $iconSrc = (isset($show['has_foldericon']) && $show['has_foldericon'])
                    ? REMOTE_API_PREFIX . "/image.php?image=" . urlencode(REMOTE_SHOWS_DIR . "/{$title}/foldericon.png")
                    : "image.php?image=media/folderIcon.png";
                ?>
                <div class="grid-item">
                    <a href="showGridTwo.php?folder=<?php echo urlencode($title); ?>&root=<?php echo urlencode(REMOTE_SHOWS_DIR); ?>&remote=1">
                        <img loading="lazy" src="<?php echo htmlspecialchars($iconSrc); ?>" alt="<?php echo htmlspecialchars($title); ?>" width="150" height="100">
                    </a>
                    <p class="title"><?php echo htmlspecialchars($title); ?></p>
                </div>
                <?php
            }
        }
        ?>
    </div>

    <!-- Optional Locked Folder -->
    <?php if (SHOW_LOCKED_FOLDER): ?>
    <div class="grid" style="margin-top: 10px;">
        <div class="grid-item">
            <a href="locked_view.php">
                <div class="locked-card">🔒</div>
            </a>
            <p class="title">Locked Folder</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
    const drawer = document.getElementById('genre-drawer');
    const toggleIcon = document.getElementById('genre-toggle-icon');
    const toggleText = document.getElementById('genre-toggle-text');

    function applyDrawerState(isOpen) {
        if (isOpen) {
            drawer.classList.add('open');
            toggleIcon.textContent = '▴';
            toggleText.textContent = 'Hide';
        } else {
            drawer.classList.remove('open');
            toggleIcon.textContent = '▾';
            toggleText.textContent = 'Show (<?php echo count($genres); ?>)';
        }
    }

    function toggleGenreDrawer() {
        const willOpen = !drawer.classList.contains('open');
        applyDrawerState(willOpen);
        localStorage.setItem('genre_drawer_open', willOpen ? '1' : '0');
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (localStorage.getItem('genre_drawer_open') === '1') {
            applyDrawerState(true);
        }
        if (!document.activeElement || document.activeElement === document.body) {
            const firstItem = document.querySelector('.quick-link-card a');
            if (firstItem) firstItem.focus();
        }
    });

    function changeProfile() {
        const current = <?php echo json_encode($_COOKIE['viewer_profile'] ?? ''); ?>;
        const promptMsg = current
            ? `Current profile: "${current}"\n\nEnter a new name to switch, or leave blank to reset to this device:`
            : "Enter your name (e.g. Alex, Mom, Sam) to sync your watch progress across all your devices:";

        const name = prompt(promptMsg, current);
        if (name !== null) {
            window.location.href = `set_profile.php?name=${encodeURIComponent(name.trim())}`;
        }
    }

    document.addEventListener('focus', function(event) {
        const target = event.target;
        if (target && target.scrollIntoView) {
            target.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
        }
    }, true);

    function jumpToShows() {
        const showsSection = document.getElementById('shows');
        if (showsSection) {
            showsSection.scrollIntoView({ behavior: 'smooth' });
            const firstShow = showsSection.querySelector('a');
            if (firstShow) {
                setTimeout(() => firstShow.focus(), 300);
            }
        }
    }
</script>

</body>
</html>