<?php
// 1. START SESSIONS & LOAD CONFIG
session_start();
require_once __DIR__ . '/config.php';

// =========================================================================
// ONE-TIME PASSCODE PROTECTION (Disabled automatically if SITE_PIN is empty)
// =========================================================================
$pin_required = (SITE_PIN !== '');
$AUTH_HASH    = $pin_required ? hash('sha256', SITE_PIN) : '';
$pin_error    = '';

// Handle PIN Submission
if ($pin_required && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin_code'])) {
    if (trim($_POST['pin_code']) === SITE_PIN) {
        $_SESSION['site_unlocked'] = $AUTH_HASH;

        setcookie('site_unlocked', $AUTH_HASH, [
            'expires'  => time() + (86400 * 365 * 10),
            'path'     => '/',
            'httponly' => false,
            'samesite' => 'Lax'
        ]);
        $_COOKIE['site_unlocked'] = $AUTH_HASH;

        header('Location: GenreGrid.php');
        exit();
    } else {
        $pin_error = "Incorrect passcode.";
    }
}

$is_authorized = !$pin_required || (
    (!empty($_SESSION['site_unlocked']) && $_SESSION['site_unlocked'] === $AUTH_HASH) ||
    (!empty($_COOKIE['site_unlocked'])  && $_COOKIE['site_unlocked']  === $AUTH_HASH)
);

$_SESSION["iconSize"] = "144"; // px
$_SESSION['unlocked_private'] = false;

// Check or generate a permanent 10-year Device ID
if (empty($_COOKIE['device_id'])) {
    $deviceId = 'dev_' . bin2hex(random_bytes(6));
    setcookie('device_id', $deviceId, time() + (86400 * 365 * 10), '/');
    $_COOKIE['device_id'] = $deviceId;
} else {
    $deviceId = $_COOKIE['device_id'];
}

// Check profile status
$rawProfile = $_COOKIE['viewer_profile'] ?? '';
$isGenericProfile = empty($rawProfile) || str_starts_with(strtolower($rawProfile), 'dev_');

if (!empty($rawProfile)) {
    $cleanProfile = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $rawProfile));
    $userIdentifier = !empty($cleanProfile) ? 'user_' . $cleanProfile : $deviceId;
} else {
    $userIdentifier = $deviceId;
}

// 2. Resolve Background Image (from .env or auto-scan media/background/)
$bgImage = '';
if (LANDING_BG !== '' && file_exists(__DIR__ . '/' . LANDING_BG)) {
    $bgImage = LANDING_BG;
} else {
    $bgCandidates = glob(__DIR__ . '/media/background/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
    if (!empty($bgCandidates)) {
        $chosenBg = $bgCandidates[array_rand($bgCandidates)];
        $bgImage = 'media/background/' . basename($chosenBg);
    }
}

// 3. Resolve Intro Video (from .env or auto-scan media/*.mp4)
$video = '';
$videoCandidates = [];
if (LANDING_VIDEOS !== '') {
    foreach (explode(',', LANDING_VIDEOS) as $v) {
        $v = trim($v);
        if ($v !== '' && file_exists(__DIR__ . '/' . $v)) {
            $videoCandidates[] = $v;
        }
    }
} else {
    $found = glob(__DIR__ . '/media/*.mp4') ?: [];
    foreach ($found as $f) {
        $videoCandidates[] = 'media/' . basename($f);
    }
}
if (!empty($videoCandidates)) {
    $video = $videoCandidates[array_rand($videoCandidates)];
}

// 4. Visit and Unique Device Counter
$filename = __DIR__ . "/visit-count.txt";
$count = file_exists($filename) ? (int)trim(@file_get_contents($filename)) : 0;
$count++;
@file_put_contents($filename, $count);

$deviceListFile = __DIR__ . "/device-list.txt";
$deviceListRaw = file_exists($deviceListFile) ? trim(@file_get_contents($deviceListFile)) : '';
$deviceArray = !empty($deviceListRaw) ? explode(",", $deviceListRaw) : [];

function CreateUserJSON($identifier) {
    $obj = [
        "FirstSeen"  => date("Y-m-d\TH:i:s"),
        "Identifier" => $identifier,
        "LastIP"     => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ];
    $dir = __DIR__ . '/IPdata/';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . "{$identifier}.json", json_encode($obj));
}

if (!in_array($userIdentifier, $deviceArray)) {
    $deviceArray[] = $userIdentifier;
    @file_put_contents($deviceListFile, implode(",", $deviceArray));
    CreateUserJSON($userIdentifier);
}

// 5. Scan IPdata ONLY for Active Viewers
$directory = __DIR__ . '/IPdata/';
$activeDevices  = [];
$activeProfiles = [];

if (is_dir($directory) && $handle = @opendir($directory)) {
    while (($file = readdir($handle)) !== false) {
        if (str_starts_with($file, 'progress_') && str_ends_with($file, '.json')) {
            $content = substr($file, 9, -5);

            if (preg_match('/^user_([a-zA-Z0-9]+)/i', $content, $m)) {
                $activeProfiles[] = strtolower($m[1]);
            } elseif (preg_match('/^(dev_[a-zA-Z0-9]+)/i', $content, $m)) {
                $activeDevices[] = strtolower($m[1]);
            } elseif (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/i', $content, $m)) {
                $activeDevices[] = $m[1];
            } elseif (preg_match('/^([a-zA-Z0-9]+)_/i', $content, $m)) {
                $activeProfiles[] = strtolower($m[1]);
            }
        }
    }
    @closedir($handle);
}

$uniqueDevicesList  = array_values(array_unique($activeDevices));
$uniqueProfilesList = array_values(array_unique($activeProfiles));

$activeDeviceCount  = count($uniqueDevicesList);
$activeProfileCount = count($uniqueProfilesList);

@file_put_contents(__DIR__ . "/unique-count.txt", $activeDeviceCount);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars(SITE_NAME); ?></title>
<style>
@keyframes rainbow {
  0% { background: linear-gradient(to right, red, orange, yellow, green, blue, indigo, violet); }
  14% { background: linear-gradient(to right, orange, yellow, green, blue, indigo, violet, red); }
  28% { background: linear-gradient(to right, yellow, green, blue, indigo, violet, red, orange); }
  42% { background: linear-gradient(to right, green, blue, indigo, violet, red, orange, yellow); }
  56% { background: linear-gradient(to right, blue, indigo, violet, red, orange, yellow, green); }
  70% { background: linear-gradient(to right, indigo, violet, red, orange, yellow, green, blue); }
  84% { background: linear-gradient(to right, violet, red, orange, yellow, green, blue, indigo); }
  100% { background: linear-gradient(to right, red, orange, yellow, green, blue, indigo, violet); }
}

@keyframes bounce {
  0% { transform: translateY(0); }
  50% { transform: translateY(-14px); }
  100% { transform: translateY(0); }
}

h1 {
  font-family: Arial, sans-serif;
  font-size: 48px;
  color: white;
  text-align: center;
  margin-top: 80px;
  margin-bottom: 30px;
  animation: bounce 1.5s infinite;
}

body {
  background-color: black;
  margin: 0;
  font-family: Arial, sans-serif;
  display: flex;
  justify-content: center;
}

video {
  display: block;
  width: 80%;
  max-width: 900px;
  margin: 35px auto 0 auto;
  border-radius: 10px;
}

.btn-enter {
  display: block;
  width: 200px;
  height: 54px;
  font-size: 32px;
  border-radius: 100px;
  border: none;
  cursor: pointer;
  animation: rainbow .8s infinite;
  font-weight: bold;
  color: white;
  text-shadow: 0 1px 4px rgba(0,0,0,0.6);
}

.grid-container {
  position: fixed;
  top: 0;
  left: 0;
  width: 100vw;
  height: 100vh;
  z-index: -1;
  opacity: 0.35;
  pointer-events: none;
  overflow: hidden;
}

.grid-container img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.container {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  padding-bottom: 50px;
}

.profile-control-wrapper {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-top: 24px;
}

.btn-profile {
  display: inline-block;
  font-size: 0.9rem;
  padding: 6px 14px;
  border-radius: 20px;
  background: #2a2a2a;
  color: #ff9900;
  border: 1px solid #ff9900;
  cursor: pointer;
  font-weight: bold;
  transition: background 0.2s, transform 0.1s;
}

.btn-profile:hover {
  background: #ff9900;
  color: #111;
  transform: translateY(-1px);
}

.profile-warning-badge {
  display: inline-flex;
  align-items: center;
  background-color: #fff3cd;
  color: #856404;
  border: 1px solid #ffeeba;
  padding: 5px 12px;
  border-radius: 20px;
  font-size: 0.8rem;
  cursor: pointer;
  white-space: nowrap;
  font-weight: 500;
  transition: background-color 0.2s, transform 0.1s;
}

.profile-warning-badge:hover {
  background-color: #ffe8a1;
  transform: translateY(-1px);
}

.profile-warning-badge strong {
  text-decoration: underline;
  margin-left: 2px;
}
</style>
</head>
<body>

<?php if ($bgImage !== ''): ?>
<div class="grid-container">
   <img src="<?php echo htmlspecialchars($bgImage); ?>" alt="Background">
</div>
<?php endif; ?>

<div class="container">
  <h1><?php echo htmlspecialchars(SITE_NAME); ?></h1>

  <?php if ($is_authorized): ?>
    <button id="Enter" class="btn-enter" onclick="goToSite()">Enter</button>
  <?php else: ?>
    <form method="POST" style="display: flex; flex-direction: column; align-items: center; gap: 10px;">
        <input type="password" name="pin_code" placeholder="Enter Passcode" autofocus
               style="padding: 10px 20px; font-size: 1.2rem; border-radius: 50px; border: 2px solid #ff9900; background: #222; color: #fff; text-align: center; width: 220px; outline: none;">
        <button type="submit" class="btn-enter" style="width: 150px; height: 42px; font-size: 1.05rem;">Unlock</button>
        <?php if (!empty($pin_error)): ?>
            <p style="color: #ff4444; font-size: 0.9rem; margin: 0;"><?php echo htmlspecialchars($pin_error); ?></p>
        <?php endif; ?>
    </form>
  <?php endif; ?>

  <!-- Connected as & Profile Switcher -->
  <div class="profile-control-wrapper">
    <span style="color: #888; font-size: 0.85rem;">Connected as:</span>

    <button type="button" class="btn-profile" onclick="changeProfile()" title="Click to change your profile name">
      👤 <?php echo !empty($rawProfile) ? htmlspecialchars(ucfirst($rawProfile)) : 'Set Name'; ?>
    </button>

    <?php if ($isGenericProfile): ?>
      <span class="profile-warning-badge" onclick="changeProfile()" title="Click to set your profile name">
        ⚠️ <strong>Set your name</strong> to save progress
      </span>
    <?php endif; ?>
  </div>

  <?php if ($video !== ''): ?>
  <video id="video" autoplay loop muted playsinline preload="auto">
    <source src="<?php echo htmlspecialchars($video); ?>" type="video/mp4">
    Your browser does not support the video tag.
  </video>
  <?php endif; ?>

  <?php if (SHOW_STATS): ?>
  <div style="color: white; text-align: center; margin-top: 28px; line-height: 1.6;">
    <div>Visits: <?php echo $count; ?></div>
    <div>Active Devices: <?php echo $activeDeviceCount; ?></div>
    <div style="color: #a8ffb2; font-weight: bold;">Active Profiles: <?php echo $activeProfileCount; ?></div>
  </div>
  <?php endif; ?>

  <?php if (FOOTER_NOTE !== ''): ?>
  <div style="color: #ccc; text-align: center; margin-top: 20px; max-width: 600px; padding: 0 16px;">
    <?php echo htmlspecialchars(FOOTER_NOTE); ?>
  </div>
  <?php endif; ?>
</div>

<script>
  function changeProfile() {
    var currentName = <?php echo json_encode($rawProfile); ?>;
    var promptMsg = currentName
      ? "Enter a new profile name (or clear to reset):"
      : "Enter a profile name to track your progress:";

    var newName = prompt(promptMsg, currentName);

    if (newName !== null) {
      newName = newName.trim();
      if (newName === "") {
        document.cookie = "viewer_profile=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
      } else {
        var clean = newName.replace(/[^a-zA-Z0-9_-]/g, "");
        if (clean === "") {
          alert("Profile name can only contain letters, numbers, hyphens, and underscores.");
          return;
        }
        var d = new Date();
        d.setTime(d.getTime() + (10 * 365 * 24 * 60 * 60 * 1000));
        document.cookie = "viewer_profile=" + encodeURIComponent(clean) + "; expires=" + d.toUTCString() + "; path=/; SameSite=Lax";
      }
      location.reload();
    }
  }

  function goToSite() {
    window.location.href = "GenreGrid.php";
  }

  var video = document.getElementById("video");
  if (video) {
    video.addEventListener("play", function() {
      video.muted = true;
    });
    window.addEventListener("click", function() {
      video.muted = false;
    });
  }
</script>
</body>
</html>