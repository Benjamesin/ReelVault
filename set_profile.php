<?php
// set_profile.php - Saves or clears user profile name
session_start();

$name = trim($_GET['name'] ?? $_POST['name'] ?? '');
// Clean name: lowercase letters, numbers, dashes, and underscores only
$clean = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $name));

if (!empty($clean)) {
    // Save profile cookie for 10 years
    setcookie('viewer_profile', $clean, time() + (86400 * 365 * 10), '/');
    $_COOKIE['viewer_profile'] = $clean;
} else {
    // If blank name submitted, delete profile cookie to revert to Device ID
    setcookie('viewer_profile', '', time() - 3600, '/');
    unset($_COOKIE['viewer_profile']);
}

// Redirect back to the page the user was on
$redirect = $_SERVER['HTTP_REFERER'] ?? 'GenreGrid.php';
header("Location: $redirect");
exit;