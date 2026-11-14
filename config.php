<?php
// Load .env from the website root into $_ENV / getenv()
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\n\r\0\x0B\"'");
        $_ENV[$key] = $val;
    }
}

function env_val(string $key, string $default = ''): string {
    return $_ENV[$key] ?? (getenv($key) !== false ? (string)getenv($key) : $default);
}

define('SITE_NAME',      env_val('SITE_NAME', 'ReelVault'));
define('SITE_PIN',       env_val('SITE_PIN', '')); // Leave blank in .env to disable PIN lock
define('SITE_TIMEZONE',  env_val('SITE_TIMEZONE', 'America/Los_Angeles'));
define('LANDING_BG',     env_val('LANDING_BG', 'media/background/001.png'));
define('LANDING_VIDEOS', env_val('LANDING_VIDEOS', '')); // Comma-separated; blank = auto-scan media/*.mp4
define('SHOW_STATS',     strtolower(env_val('SHOW_STATS', 'true')) === 'true');
define('FOOTER_NOTE',    env_val('FOOTER_NOTE', 'Create a profile name to keep track of your watch progress across devices.'));

date_default_timezone_set(SITE_TIMEZONE);

// Navigation & Library Paths
define('REQUIRE_CAPTCHA',    strtolower(env_val('REQUIRE_CAPTCHA', 'false')) === 'true');
define('SERIES_DIR',         env_val('SERIES_DIR', '')); // Blank = hide Series section
define('LOCAL_SHOWS_DIR',    env_val('LOCAL_SHOWS_DIR', '/path/to/shows'));
define('SHOW_LOCKED_FOLDER', strtolower(env_val('SHOW_LOCKED_FOLDER', 'false')) === 'true');

// Optional Slave/Remote Machine Proxy
define('REMOTE_ENABLED',     strtolower(env_val('REMOTE_ENABLED', 'false')) === 'true');
define('REMOTE_API_PREFIX',  env_val('REMOTE_API_PREFIX', '/remote-api'));
define('REMOTE_SHOWS_DIR',   env_val('REMOTE_SHOWS_DIR', ''));

define('REMOTE_VIDEOS_DIR',    env_val('REMOTE_VIDEOS_DIR', ''));
define('REMOTE_VIDEOS_PREFIX', env_val('REMOTE_VIDEOS_PREFIX', '/remote-videos'));

define('LOCAL_VIDEOS_DIR', env_val('LOCAL_VIDEOS_DIR', '/path/to/videos'));
define('REMOTE_HOST',      env_val('REMOTE_HOST', ''));
define('REMOTE_API_PORT',  (int)env_val('REMOTE_API_PORT', '8080'));

define('WEB_VIDEOS_PATH', env_val('WEB_VIDEOS_PATH', 'videos/'));
define('WEB_SHOWS_PATH',  env_val('WEB_SHOWS_PATH', 'shows/'));