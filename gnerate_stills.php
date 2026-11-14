<?php
// generate_stills.php (on the main machine)
if (php_sapi_name() !== 'cli') {
    die("This script can only be run via command line.\n");
}

if ($argc < 2) {
    die("Usage: php generate_stills.php <video_file_path>\n");
}

// 1. Normalize path: Convert any Windows backslashes to forward slashes FIRST
// so dirname() and pathinfo() work correctly on Linux.
$videoPath = str_replace('\\', '/', $argv[1]);

if (!file_exists($videoPath)) {
    die("Error: Video file does not exist: $videoPath\n");
}

$videoDir = dirname($videoPath);
$videoFilename = pathinfo($videoPath, PATHINFO_FILENAME);

// 2. Build output directory using standard '/'
$stillsDir = $videoDir . '/stills/' . $videoFilename;

if (!is_dir($stillsDir)) {
    // 0755 is standard recommended directory permission on Linux
    if (!mkdir($stillsDir, 0755, true) && !is_dir($stillsDir)) {
        die("Error: Failed to create directory: $stillsDir\n");
    }
}

// 3. Target output pattern
$outputPattern = $stillsDir . '/still_%03d.jpg';

// Build command array
$cmd = [
    'ffmpeg',
    '-i', $videoPath,
    '-vf', 'fps=1/10,scale=160:90',
    '-q:v', '4',
    $outputPattern,
    '-y'
];

// 4. Handle OS-specific null device and temporary error log
$isWindows = (stripos(PHP_OS, 'WIN') === 0);
$nullDevice = $isWindows ? 'NUL' : '/dev/null';
$tempErrFile = tempnam(sys_get_temp_dir(), 'ffmpeg_err_');

$descriptorspec = [
    0 => ["pipe", "r"],              // stdin
    1 => ["file", $nullDevice, "w"], // stdout (discarded)
    2 => ["file", $tempErrFile, "w"] // stderr (written to disk to avoid pipe deadlock)
];

$process = proc_open($cmd, $descriptorspec, $pipes);

if (is_resource($process)) {
    // Close stdin immediately so interactive prompts don't hang execution
    fclose($pipes[0]);

    // proc_close blocks until FFmpeg completes
    $returnVar = proc_close($process);

    // Read stderr log
    $stderr = file_exists($tempErrFile) ? file_get_contents($tempErrFile) : '';
    @unlink($tempErrFile); // Clean up temp log
} else {
    $returnVar = -1;
    $stderr = "Failed to spawn process via proc_open.";
}

// 5. Check results
if ($returnVar === 0) {
    echo "Stills generated successfully in: $stillsDir\n";
} else {
    echo "Error generating stills. Exit Code: $returnVar\n";
    if ($returnVar === 127) {
        echo "Hint: Exit code 127 usually means FFmpeg is not installed or not in system PATH.\n";
    }
    echo "FFmpeg stderr output:\n$stderr\n";
    exit(1);
}