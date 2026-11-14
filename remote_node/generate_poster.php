

<?php
// remote_node/generate_poster.php
require_once __DIR__ . '/config.php';
error_reporting(E_ALL);
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['video'])) {
    header('Content-Type: application/json');
    echo json_encode(generatePosterForVideo($_GET['video']));
    exit;
}


function generatePosterForVideo($rawPath) {
    $realPath = realpath($rawPath);

    if (!$realPath || !file_exists($realPath)) {
        return ['success' => false, 'error' => 'Video file not found'];
    }
    if (!is_allowed_remote_path($realPath)) {
        return ['success' => false, 'error' => 'Unauthorized path'];
    }
    
    $directory = dirname($realPath);
    $filename = pathinfo($realPath, PATHINFO_FILENAME);
    $posterPath = $directory . DIRECTORY_SEPARATOR . $filename . "-poster.jpg";
    
    if (file_exists($posterPath) && filesize($posterPath) > 0) {
        return ['success' => true, 'message' => 'Poster already exists', 'path' => $posterPath];
    }
    
    // 2. Permissions Check
    if (!is_writable($directory)) {
        return ['success' => false, 'error' => 'Directory is not writable: ' . $directory];
    }
    
    // 3. Define Executables
    $ffmpeg = 'ffmpeg'; 
    $ffprobe = 'ffprobe';
    
    // 4. Get Duration
    $targetTime = getDurationSafely($ffprobe, $realPath);
    
    // 5. Generate Poster
    $result = generatePosterSafely($ffmpeg, $realPath, $posterPath, $targetTime);
    
    return $result;
}

function getDurationSafely($ffprobe, $videoPath) {
    $cmd = [
        $ffprobe,
        '-v', 'error',
        '-show_entries', 'format=duration',
        '-of', 'default=noprint_wrappers=1:nokey=1',
        $videoPath
    ];
    
    // Use proc_open with proper stream handling
    $process = proc_open(
        $cmd,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    
    if (!is_resource($process)) {
        return 10; // fallback
    }
    
    fclose($pipes[0]);
    
    // Set streams to non-blocking to prevent hangs
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    
    $output = '';
    $error = '';
    
    // Read with timeout
    $timeout = 5; // seconds
    $start = time();
    while (time() - $start < $timeout) {
        $output .= stream_get_contents($pipes[1]);
        $error .= stream_get_contents($pipes[2]);
        
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(100000); // 0.1 second
    }
    
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    
    $duration = trim($output);
    
    if (!is_numeric($duration)) {
        return 10; // fallback
    }
    
    return max(1, (int)((float)$duration / 3));
}

function generatePosterSafely($ffmpeg, $videoPath, $posterPath, $targetTime) {
    // Create a temporary file for stderr output to avoid pipe buffer issues
    $stderrFile = tempnam(sys_get_temp_dir(), 'ffmpeg_stderr_');
    
    $cmd = [
        $ffmpeg,
        '-y',  // Overwrite without asking - FIRST
        '-nostdin',  // No stdin interaction
        '-ss', (string)$targetTime,
        '-i', $videoPath,
        '-frames:v', '1',
        '-q:v', '2',
        '-f', 'image2',  // Force image format
        $posterPath
    ];
    
    $descriptorspec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['file', $stderrFile, 'w']  // stderr to file
    ];
    
    $process = proc_open(
        $cmd,
        $descriptorspec,
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    
    if (!is_resource($process)) {
        @unlink($stderrFile);
        return ['success' => false, 'error' => 'Failed to start ffmpeg process'];
    }
    
    // Close stdin immediately
    fclose($pipes[0]);
    
    // Set stdout to non-blocking
    stream_set_blocking($pipes[1], false);
    
    $stdout = '';
    $timeout = 30; // 30 seconds timeout
    $start = time();
    
    // Monitor process with timeout
    while (time() - $start < $timeout) {
        $stdout .= stream_get_contents($pipes[1]);
        
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(100000); // 0.1 second
    }
    
    // Check if timeout occurred
    $status = proc_get_status($process);
    if ($status['running']) {
        // Process still running - kill it
        proc_terminate($process, 9);
        fclose($pipes[1]);
        @unlink($stderrFile);
        return ['success' => false, 'error' => 'ffmpeg process timed out after 30 seconds'];
    }
    
    fclose($pipes[1]);
    $returnCode = proc_close($process);
    
    // Read stderr from file
    $stderr = file_get_contents($stderrFile);
    @unlink($stderrFile);
    
    // Verify Result
    if (file_exists($posterPath) && filesize($posterPath) > 0) {
        return ['success' => true, 'path' => $posterPath];
    } else {
        return [
            'success' => false, 
            'error' => 'ffmpeg failed to generate poster',
            'return_code' => $returnCode,
            'stderr' => $stderr,
            'command' => implode(' ', array_map('escapeshellarg', $cmd))
        ];
    }
}
?>