<?php
session_start();
require_once __DIR__ . '/config.php';

// =========================================================================
// 1. SECURITY GATE
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

$iconSize = (int)($_SESSION["iconSize"] ?? 140);
$angle = 90;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars(SITE_NAME); ?> - Search</title>
<style>
@keyframes move {
    0% { background-position: 0% 50%; }
    50% { background-position: 80% 50%; }
    100% { background-position: 0% 50%; }
}

:root {
    --bg-color: #0d0d0d;
    --focus-color: #ff9900;
}

body {
    background: linear-gradient(<?php echo $angle; ?>deg, #0d0d0d, #0d0d0d 70%, #4a0000);
    background-size: 300% 100%;
    animation: move 20s infinite linear;
    color: white;
    font-family: Arial, sans-serif;
    margin: 0;
    padding: 20px 40px;
}

.top-bar {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
    border-bottom: 2px solid #333;
    padding-bottom: 15px;
}

.top-bar h1 {
    margin: 0;
    font-size: 2rem;
    letter-spacing: 1px;
}

.top-bar h1 a {
    color: white;
    text-decoration: none;
}

.top-bar h1 a:hover {
    color: var(--focus-color);
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

.search-wrapper {
  padding: 10px 10px 30px 10px;
  text-align: center;
}

#live-search-input {
  width: 90%;
  max-width: 650px;
  padding: 14px 25px;
  font-size: 1.2rem;
  border-radius: 50px;
  border: 2px solid #4a0000;
  background-color: #1a1a1a;
  color: #f0f0f0;
  box-shadow: 0 0 15px rgba(255, 50, 50, 0.4);
  transition: all 0.3s ease;
}

#live-search-input:focus {
  outline: none;
  border-color: var(--focus-color);
  box-shadow: 0 0 25px rgba(255, 153, 0, 0.7);
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(<?php echo (int)$iconSize; ?>px, 1fr));
    gap: 15px;
}

.grid-item {
    position: relative;
    display: flex;
    flex-direction: column;
}

.grid-item .container {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
    background-color: #222;
    aspect-ratio: 2/3;
}

.grid-item .image1 {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.grid-item .image2 {
    position: absolute;
    bottom: 5px;
    right: 5px;
    width: 22px;
    height: auto;
    opacity: 0.85;
}

.grid-item .text {
    position: absolute;
    bottom: 6px;
    right: 30px;
    font-size: 13px;
    font-weight: bold;
    color: #ffcc00;
    text-shadow: 1px 1px 3px black, -1px -1px 3px black;
}

.grid-item button {
    background-color: transparent;
    border: 3px solid transparent;
    border-radius: 8px;
    padding: 0;
    cursor: pointer;
    width: 100%;
    transition: transform 0.15s ease-out;
}

.grid-item button:focus {
    outline: none;
    border-color: var(--focus-color);
    transform: scale(1.06);
    box-shadow: 0 0 15px rgba(255, 153, 0, 0.5);
}

.title {
    text-align: center;
    color: #ddd;
    margin-top: 6px;
    font-size: 0.9rem;
    word-break: break-word;
}

#search-results-container .status-message {
    grid-column: 1 / -1;
    text-align: center;
    font-size: 20px;
    color: #888;
    margin-top: 40px;
}
</style>
</head>
<body>

<div class="top-bar">
    <h1><a href="GenreGrid.php">← <?php echo htmlspecialchars(SITE_NAME); ?></a></h1>
    <button class="user-indicator" onclick="changeProfile()" title="Click to change your profile name">
        👤 <?php echo $displayLabel; ?>
    </button>
</div>

<div class="search-wrapper">
    <input type="text" id="live-search-input" placeholder="Start typing to find movies..." autofocus>
</div>

<div id="search-results-container" class="grid"></div>

<div id="main-content" style="text-align: center; margin-top: 50px;">
    <p style="color: #666; font-size: 1.2rem;">Type at least 2 characters to search movies</p>
</div>

<script>
const iconSize = <?php echo json_encode((int)$iconSize); ?>;
const userIdentifier = <?php echo json_encode($userIdentifier); ?>;

const searchInput = document.getElementById('live-search-input');
const resultsContainer = document.getElementById('search-results-container');
const mainContent = document.getElementById('main-content');

let debounceTimer;

const performSearch = async (query) => {
    if (query.length < 2) {
        resultsContainer.innerHTML = '';
        mainContent.style.display = 'block';
        return;
    }

    mainContent.style.display = 'none';
    resultsContainer.innerHTML = '<p class="status-message">Searching...</p>';

    try {
        const response = await fetch(`api_search.php?q=${encodeURIComponent(query)}&user=${encodeURIComponent(userIdentifier)}`);
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        const movies = await response.json();

        if (!Array.isArray(movies)) {
            const msg = movies.error ? movies.message : 'Unexpected response from server';
            resultsContainer.innerHTML = `<p class="status-message">Error: ${escapeHTML(msg)}</p>`;
            return;
        }

        resultsContainer.innerHTML = '';

        if (movies.length === 0) {
            resultsContainer.innerHTML = '<p class="status-message">No results found.</p>';
            return;
        }

        movies.forEach(movie => {
            const isRemote = (movie.is_remote === 1 || movie.is_remote === "1");
            const ratingHtml = movie.rating ? `<div class="text">${escapeHTML(movie.rating)}</div>` : '';
            const starImg = movie.rating ? `<img src="image.php?image=media/star.png" class="image2" alt="Rating">` : '';

            const movieHtml = `
                <div class="grid-item">
                    <form action="videoPlayer.php" method="GET">
                        <input type="hidden" name="remote" value="${isRemote ? '1' : '0'}">
                        <button name="video" value="${escapeHTML(movie.path)}">
                            <div class="container">
                                <img src="${escapeHTML(movie.thumbnailSrc)}" class="image1" alt="Poster" loading="lazy">
                                ${starImg}
                                ${ratingHtml}
                            </div>
                        </button>
                    </form>
                    <p class="title">${movie.watchedSymbol || ''} ${escapeHTML(movie.title)}</p>
                </div>
            `;

            resultsContainer.innerHTML += movieHtml;
        });
    } catch (error) {
        console.error('Search failed:', error);
        resultsContainer.innerHTML = '<p class="status-message">Error performing search.</p>';
    }
};

searchInput.addEventListener('input', (e) => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        performSearch(e.target.value);
    }, 300);
});

function escapeHTML(str) {
    if (str == null) return '';
    str = String(str);
    return str.replace(/[&<>"']/g, function(match) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[match];
    });
}

function changeProfile() {
    const current = <?php echo json_encode($_COOKIE['viewer_profile'] ?? ''); ?>;
    const promptMsg = current
        ? `Current profile: "${current}"\n\nEnter a new name, or leave blank to reset to Device ID:`
        : "Enter your name (e.g. Alex, Mom, Sam) to sync your watch progress:";

    const name = prompt(promptMsg, current);
    if (name !== null) {
        window.location.href = `set_profile.php?name=${encodeURIComponent(name.trim())}`;
    }
}
</script>

</body>
</html>