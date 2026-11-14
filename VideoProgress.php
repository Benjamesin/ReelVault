<?php
// VideoProgress.php - Pure Device ID / User Profile Progress Saver
$movie       = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($_POST['movie'] ?? '', PATHINFO_FILENAME));
$duration    = (float)($_POST['duration'] ?? 0);
$currentTime = (float)($_POST['currentTime'] ?? 0);

// 1. Resolve User Identity: POST param -> Profile Cookie -> Device ID Cookie
$rawIdentifier = trim($_POST['userIdentifier'] ?? '');

if (empty($rawIdentifier)) {
    if (!empty($_COOKIE['viewer_profile'])) {
        $clean = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $_COOKIE['viewer_profile']));
        $rawIdentifier = 'user_' . $clean;
    } elseif (!empty($_COOKIE['device_id'])) {
        $rawIdentifier = $_COOKIE['device_id'];
    } else {
        $rawIdentifier = 'dev_' . bin2hex(random_bytes(6));
        setcookie('device_id', $rawIdentifier, time() + (86400 * 365 * 10), '/');
        $_COOKIE['device_id'] = $rawIdentifier;
    }
}

// Sanitize identifier for filename safety
$userIdentifier = preg_replace('/[^a-zA-Z0-9_.-]/', '', $rawIdentifier);

// 2. Save Progress JSON
if (!empty($movie) && $duration > 0 && !empty($userIdentifier)) {
    $dir = __DIR__ . '/IPdata/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    
    $file = $dir . "progress_{$userIdentifier}_{$movie}.json";
    
    $data = [
        'currentTime' => $currentTime,
        'duration'    => $duration,
        'updated_at'  => time()
    ];
    
    @file_put_contents($file, json_encode($data));
}