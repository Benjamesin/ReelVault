<?php
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}

function r_env(string $k, string $default = ''): string {
    return $_ENV[$k] ?? (getenv($k) !== false ? (string)getenv($k) : $default);
}

define('REMOTE_BASE_DIR',     rtrim(r_env('MEDIA_BASE_DIR', '/mnt/media'), '/'));
define('REMOTE_VIDEOS_DIR',   rtrim(r_env('VIDEOS_DIR', REMOTE_BASE_DIR . '/videos'), '/'));
define('REMOTE_SHOWS_DIR',    rtrim(r_env('SHOWS_DIR', REMOTE_BASE_DIR . '/shows'), '/'));
define('REMOTE_ARCHIVED_DIR', r_env('ARCHIVED_SHOWS_DIR', '') !== '' ? rtrim(r_env('ARCHIVED_SHOWS_DIR', ''), '/') : '');
define('REMOTE_NODE_PORT',    r_env('REMOTE_PORT', '8080'));

function is_allowed_remote_path(string $path, bool $allowWebRoot = false): bool {
    $real = realpath(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
    if ($real === false) return false;
    $realNorm = str_replace('\\', '/', $real);

    $allowed = array_filter([
        REMOTE_BASE_DIR,
        REMOTE_VIDEOS_DIR,
        REMOTE_SHOWS_DIR,
        REMOTE_ARCHIVED_DIR,
        $allowWebRoot ? __DIR__ : '',
    ]);

    foreach ($allowed as $dir) {
        $realDir = realpath($dir);
        if ($realDir !== false) {
            $dirNorm = rtrim(str_replace('\\', '/', $realDir), '/') . '/';
            if ($realNorm === rtrim($dirNorm, '/') || str_starts_with($realNorm, $dirNorm)) {
                return true;
            }
        }
    }
    return false;
}