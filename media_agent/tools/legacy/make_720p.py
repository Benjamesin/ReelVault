import os
import subprocess
import re
import sys
import json
import shutil
import unicodedata
from datetime import datetime
from typing import Optional, Tuple, Dict
from pathlib import Path

import config

# Configuration
SOURCE_DIR = str(config.VIDEOS_DIR)
LOG_DIR = str(config.LOG_DIR)
LOW_RES_THRESHOLD = 520  # Flag videos below this resolution
HIGH_RES_THRESHOLD = 720  # Re-encode anything above this for consistent streaming
TARGET_720P_BITRATE = "2M"  # Low bitrate for 720p adaptive streaming
TARGET_1080P_BITRATE = "6M"  # Bitrate for 1080p streaming
MAIN_CRF = 23  # Quality for main conversions (18-28, lower = better)

# Statistics counters
stats = {
    'total_files': 0,
    'already_optimized': 0,
    'converted': 0,
    'failed': 0,
    'low_res': 0,
    'created_720p': 0,
    'total_size_before': 0,
    'total_size_after': 0
}


class Logger:
    """Handles logging to both console and file."""
    
    def __init__(self, log_file: str):
        self.log_file = log_file
        self.ensure_log_dir()
        
        with open(self.log_file, 'w', encoding='utf-8') as f:
            f.write("=" * 80 + "\n")
            f.write(f"Video Conversion Log - {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
            f.write(f"Source Directory: {SOURCE_DIR}\n")
            f.write("=" * 80 + "\n\n")
    
    def ensure_log_dir(self):
        """Create log directory if it doesn't exist."""
        os.makedirs(LOG_DIR, exist_ok=True)
    
    def log(self, message: str, to_console: bool = True, to_file: bool = True):
        """Log message to console and/or file."""
        timestamp = datetime.now().strftime('%H:%M:%S')
        formatted = f"[{timestamp}] {message}"
        
        if to_console:
            print(formatted)
        
        if to_file:
            with open(self.log_file, 'a', encoding='utf-8') as f:
                f.write(formatted + "\n")
    
    def log_file_info(self, info: Dict):
        """Log detailed file information."""
        lines = [
            "",
            "-" * 80,
            f"File: {info['filename']}",
            f"Path: {info['path']}",
            f"Resolution: {info['width']}x{info['height']} ({info['height']}p)",
            f"Original Codec: {info['codec']}",
            f"Original Size: {info['size_before_mb']:.2f} MB",
        ]
        
        if info.get('status') == 'converted':
            lines.extend([
                f"New Size: {info['size_after_mb']:.2f} MB",
                f"Size Change: {info['size_change']:+.2f} MB ({info['size_change_percent']:+.1f}%)",
                f"Conversion Time: {info['conversion_time']:.1f}s",
                f"720p Created: {'Yes' if info.get('created_720p') else 'No'}",
                f"Status: ✓ CONVERTED (In-place)"
            ])
        elif info.get('status') == 'optimized':
            lines.append(f"Status: ⊘ ALREADY OPTIMIZED (skipped)")
        elif info.get('status') == 'failed':
            lines.extend([
                f"Error: {info.get('error', 'Unknown error')}",
                f"Status: ✗ FAILED"
            ])

        if info.get('height') is None or info['height'] < LOW_RES_THRESHOLD:
            lines.append(f"⚠ WARNING: Low resolution (<{LOW_RES_THRESHOLD}p) - Consider for deletion")
        
        lines.append("-" * 80)
        
        for line in lines:
            self.log(line, to_console=False, to_file=True)
    
    def log_summary(self):
        """Log final summary statistics."""
        lines = [
            "",
            "=" * 80,
            "CONVERSION SUMMARY",
            "=" * 80,
            f"Total MP4 files found: {stats['total_files']}",
            f"Already optimized (skipped): {stats['already_optimized']}",
            f"Successfully converted: {stats['converted']}",
            f"Failed conversions: {stats['failed']}",
            f"Low resolution files (<{LOW_RES_THRESHOLD}p): {stats['low_res']}",
            f"720p versions created: {stats['created_720p']}",
            "",
            f"Total size before: {stats['total_size_before'] / 1024:.2f} GB",
            f"Total size after: {stats['total_size_after'] / 1024:.2f} GB",
        ]
        
        if stats['converted'] > 0:
            size_diff = stats['total_size_after'] - stats['total_size_before']
            size_diff_gb = size_diff / 1024
            percent_change = (size_diff / stats['total_size_before']) * 100 if stats['total_size_before'] > 0 else 0
            lines.extend([
                f"Size change: {size_diff_gb:+.2f} GB ({percent_change:+.1f}%)",
            ])
        
        lines.append("=" * 80)
        
        for line in lines:
            self.log(line)


def get_video_info(file_path: str) -> Optional[Dict]:
    """Get comprehensive video information."""
    try:
        command = [
            "ffprobe",
            "-v", "error",
            "-select_streams", "v:0",
            "-show_entries", 
            "stream=width,height,codec_name,bit_rate:format=duration,size",
            "-show_entries", "format_tags=major_brand",
            "-of", "json",
            file_path
        ]
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, 
                              text=True, timeout=10)
        
        data = json.loads(result.stdout)
        
        info = {
            'duration': None,
            'width': None,
            'height': None,
            'codec': None,
            'bitrate': None,
            'size': None,
            'has_faststart': False
        }
        
        if 'format' in data:
            fmt = data['format']
            info['duration'] = float(fmt.get('duration', 0))
            info['size'] = int(fmt.get('size', 0))
            
            tags = fmt.get('tags', {})
            major_brand = tags.get('major_brand', '')
            info['has_faststart'] = major_brand.lower() in ['isom', 'mp42', 'mp41']
        
        if 'streams' in data and len(data['streams']) > 0:
            stream = data['streams'][0]
            info['width'] = stream.get('width')
            info['height'] = stream.get('height')
            info['codec'] = stream.get('codec_name')
            info['bitrate'] = stream.get('bit_rate')
        
        return info
    except Exception as e:
        print(f"  Warning: Could not get video info: {e}")
        return None


def needs_conversion(video_info: Dict) -> Tuple[bool, str]:
    """Determine if video needs conversion."""
    if not video_info:
        return True, "Unable to read video info"
    
    codec = video_info.get('codec', '')
    
    if codec not in ['h264', 'avc']:
        return True, f"Non-H.264 codec ({codec})"
    
    if not video_info.get('has_faststart'):
        return True, "Missing streaming optimization (faststart)"
    
    return False, "Already optimized"


def normalize_str(s: str) -> str:
    """Normalize unicode characters (accents, curly quotes, dashes, spaces) for safe comparison."""
    norm = unicodedata.normalize('NFKD', s)
    norm = re.sub(r'[\u2018\u2019\u201A\u201B\u0060\u00B4]', "'", norm)
    norm = re.sub(r'[\u201C\u201D\u201E\u201F]', '"', norm)
    norm = re.sub(r'[\u2013\u2014\u2015]', '-', norm)
    norm = re.sub(r'\s+', ' ', norm)
    return norm.strip().casefold()


def find_existing_720p(file_path: str) -> Optional[str]:
    """
    Checks if a 720p version exists, bypassing Unicode normalization
    and case-sensitivity bugs on Linux/NTFS mounts.
    """
    video_dir = os.path.dirname(file_path)
    filename = os.path.basename(file_path)
    base_stem = Path(filename).stem
    
    dir_720p = None
    try:
        for entry in os.scandir(video_dir):
            if entry.is_dir() and entry.name.lower() in ["720p", "720"]:
                dir_720p = entry.path
                break
    except Exception:
        dir_720p = os.path.join(video_dir, "720p")

    if not dir_720p or not os.path.isdir(dir_720p):
        return None

    direct_check = os.path.join(dir_720p, filename)
    if os.path.exists(direct_check) and os.path.getsize(direct_check) > 1024:
        return direct_check

    target_norm_name = normalize_str(filename)
    target_norm_stem = normalize_str(base_stem)

    try:
        for entry in os.scandir(dir_720p):
            if entry.is_file() and entry.stat().st_size > 1024:
                entry_norm = normalize_str(entry.name)
                entry_stem = normalize_str(Path(entry.name).stem)
                
                if (entry_norm == target_norm_name or 
                    entry_stem == target_norm_stem or 
                    entry_stem == f"{target_norm_stem}_720p"):
                    return entry.path
    except Exception:
        pass

    return None


def run_ffmpeg_with_progress(command, duration: float, logger: Logger) -> Tuple[int, str]:
    """Run FFmpeg command, display progress bar, and capture error logs on failure."""
    process = subprocess.Popen(
        command,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        universal_newlines=True,
        bufsize=1
    )
    
    time_pattern = re.compile(r'time=(\d+):(\d+):(\d+\.\d+)')
    last_progress = 0
    captured_output = []
    
    for line in process.stdout:
        captured_output.append(line)
        # Keep only the last 30 lines of FFmpeg output in memory
        if len(captured_output) > 30:
            captured_output.pop(0)

        match = time_pattern.search(line)
        if match and duration:
            hours = int(match.group(1))
            minutes = int(match.group(2))
            seconds = float(match.group(3))
            current_time = hours * 3600 + minutes * 60 + seconds
            
            progress = min((current_time / duration) * 100, 100)
            
            if progress - last_progress >= 1 or progress >= 100:
                bar_length = 40
                filled = int(bar_length * progress / 100)
                bar = '█' * filled + '░' * (bar_length - filled)
                
                if 0 < progress < 100:
                    elapsed = current_time
                    total_estimated = (elapsed / progress) * 100
                    eta_seconds = total_estimated - elapsed
                    eta_mins = int(eta_seconds / 60)
                    eta_secs = int(eta_seconds % 60)
                    eta_str = f"ETA: {eta_mins}m {eta_secs}s"
                else:
                    eta_str = ""
                
                sys.stdout.write(f'\r  Progress: [{bar}] {progress:.1f}% {eta_str}')
                sys.stdout.flush()
                last_progress = progress
    
    process.wait()
    
    if duration:
        sys.stdout.write('\n')
        sys.stdout.flush()
    
    error_log = "".join(captured_output)
    return process.returncode, error_log


def convert_video(input_path: str, output_path: str, duration: float, 
                 logger: Logger, target_height: Optional[int] = None) -> bool:
    """
    Convert video to H.264 MP4 with optimal streaming settings.
    Includes 8-bit normalization, stream filtering, and software-decode fallback.
    """
    def build_command(use_cuda_hwaccel: bool):
        cmd = ["ffmpeg"]
        if use_cuda_hwaccel:
            cmd.extend(["-hwaccel", "cuda"])
        
        cmd.extend([
            "-i", input_path,
            # Explicitly map primary video and audio; drop rogue subtitle/data streams
            "-map", "0:v:0",
            "-map", "0:a?",
            "-sn",
            "-dn",
            "-c:v", "h264_nvenc",
            "-pix_fmt", "yuv420p",  # Enforces 8-bit compatibility for 10-bit/HDR inputs
            "-preset", "default",
            "-rc:v", "vbr",
            "-b:v", "2500k",
            "-profile:v", "high",
            "-level", "4.1",
            "-movflags", "+faststart",
            "-c:a", "aac",
            "-b:a", "128k",
            "-ac", "2",
        ])

        if target_height:
            cmd.extend([
                "-vf", f"scale=-2:{target_height}",
                "-maxrate", TARGET_720P_BITRATE,
                "-bufsize", "4M"
            ])
        else:
            cmd.extend([
                "-maxrate", "3000k",
                "-bufsize", "6000k"
            ])

        cmd.extend(["-y", output_path])
        return cmd

    # Attempt 1: Full hardware decode + encode
    cmd = build_command(use_cuda_hwaccel=True)
    returncode, error_log = run_ffmpeg_with_progress(cmd, duration, logger)
    
    if returncode == 0:
        return True

    # Attempt 2: Fallback to CPU decode + NVENC encode (fixes Hi10P, AV1 on Pascal, or NVDEC issues)
    logger.log("  ⚠ Hardware decode failed, retrying with CPU decode + NVENC...")
    cmd_fallback = build_command(use_cuda_hwaccel=False)
    returncode, error_log = run_ffmpeg_with_progress(cmd_fallback, duration, logger)
    
    if returncode == 0:
        return True

    # Log the exact FFmpeg error if both attempts fail
    logger.log(f"  ✗ FFmpeg error output:\n{error_log.strip()}")
    return False

def get_file_size_mb(file_path: str) -> float:
    """Get file size in MB."""
    try:
        return os.path.getsize(file_path) / (1024 * 1024)
    except Exception:
        return 0.0


def process_video(file_path: str, logger: Logger) -> bool:
    """Process a single video file."""
    filename = os.path.basename(file_path)
    logger.log(f"\nProcessing: {filename}")
    
    video_info = get_video_info(file_path)
    
    if not video_info:
        logger.log(f"  ✗ Failed to read video info", to_file=True)
        stats['failed'] += 1
        return False
    
    file_info = {
        'filename': filename,
        'path': file_path,
        'width': video_info['width'],
        'height': video_info['height'],
        'codec': video_info['codec'],
        'size_before_mb': get_file_size_mb(file_path)
    }
    
    stats['total_size_before'] += file_info['size_before_mb']
    
    if video_info['height'] and video_info['height'] < LOW_RES_THRESHOLD:
        stats['low_res'] += 1
        logger.log(f"  ⚠ Low resolution: {video_info['height']}p")
    
    needs_conv, reason = needs_conversion(video_info)
    
    if not needs_conv:
        logger.log(f"  ⊘ Already optimized - skipping main conversion")
        file_info['status'] = 'optimized'
        stats['already_optimized'] += 1
        stats['total_size_after'] += file_info['size_before_mb']
    else:
        logger.log(f"  Reason: {reason}")
        logger.log(f"  Resolution: {video_info['width']}x{video_info['height']}")
    
    temp_output = file_path + ".temp.mp4"
    start_time = datetime.now()
    
    try:
        if needs_conv:
            logger.log(f"  Converting to H.264...")
            if not convert_video(file_path, temp_output, video_info['duration'], logger):
                logger.log(f"  ✗ Conversion failed!")
                file_info['status'] = 'failed'
                file_info['error'] = 'FFmpeg conversion failed'
                logger.log_file_info(file_info)
                stats['failed'] += 1
                if os.path.exists(temp_output):
                    os.remove(temp_output)
                return False
            
            conversion_time = (datetime.now() - start_time).total_seconds()
            
            if not os.path.exists(temp_output) or os.path.getsize(temp_output) < 1000:
                logger.log(f"  ✗ Output file invalid!")
                file_info['status'] = 'failed'
                file_info['error'] = 'Output file invalid or empty'
                logger.log_file_info(file_info)
                stats['failed'] += 1
                if os.path.exists(temp_output):
                    os.remove(temp_output)
                return False
            
            # Atomic in-place replacement (No backup directory)
            os.replace(temp_output, file_path)
            logger.log(f"  ✓ Conversion successful (replaced in-place)!")
            
            file_info['size_after_mb'] = get_file_size_mb(file_path)
            file_info['size_change'] = file_info['size_after_mb'] - file_info['size_before_mb']
            file_info['size_change_percent'] = (file_info['size_change'] / file_info['size_before_mb']) * 100
            file_info['conversion_time'] = conversion_time
            file_info['status'] = 'converted'
            
            stats['total_size_after'] += file_info['size_after_mb']
            stats['converted'] += 1

        # -------------------------------------------------------------
        # 720p Version Handling with Normalized Unicode Detection
        # -------------------------------------------------------------
        existing_720p = find_existing_720p(file_path)
        file_info['created_720p'] = False
        
        if existing_720p:
            logger.log(f"  ✓ 720p already exists ({os.path.basename(existing_720p)}) - skipping")
        elif video_info['height'] and video_info['height'] > 720:
            logger.log(f"  Creating 720p version...")
            
            video_dir = os.path.dirname(file_path)
            dir_720p = os.path.join(video_dir, "720p")
            os.makedirs(dir_720p, exist_ok=True)
            output_720p = os.path.join(dir_720p, filename)
            
            if convert_video(file_path, output_720p, video_info['duration'], logger, target_height=720):
                logger.log(f"  ✓ 720p version created ({get_file_size_mb(output_720p):.2f} MB)")
                file_info['created_720p'] = True
                stats['created_720p'] += 1
            else:
                logger.log(f"  ⚠ 720p conversion failed (continuing)")
        
        logger.log_file_info(file_info)
        return True
        
    except Exception as e:
        logger.log(f"  ✗ Error during conversion: {e}")
        file_info['status'] = 'failed'
        file_info['error'] = str(e)
        logger.log_file_info(file_info)
        stats['failed'] += 1
        
        if os.path.exists(temp_output):
            os.remove(temp_output)
        
        return False


def main():
    """Main function to process all videos."""
    if not os.path.exists(SOURCE_DIR):
        print(f"Error: Source directory '{SOURCE_DIR}' does not exist!")
        input("Press Enter to exit...")
        return
    
    log_filename = f"conversion_log_{datetime.now().strftime('%Y%m%d_%H%M%S')}.txt"
    log_path = os.path.join(LOG_DIR, log_filename)
    logger = Logger(log_path)
    
    logger.log(f"Starting video optimization process (In-Place Mode)")
    logger.log(f"Source: {SOURCE_DIR}")
    logger.log(f"Log: {log_path}")
    logger.log("=" * 80)
    
    logger.log("\nScanning for MP4 files...")
    mp4_files = []
    
    for root, dirs, files in os.walk(SOURCE_DIR):
        dirs[:] = [d for d in dirs if d.lower() not in ["720p", "720"]]
        
        for file in files:
            if file.lower().endswith('.mp4'):
                mp4_files.append(os.path.join(root, file))
    
    stats['total_files'] = len(mp4_files)
    logger.log(f"Found {len(mp4_files)} MP4 files")
    
    if len(mp4_files) == 0:
        logger.log("No MP4 files found. Exiting.")
        input("Press Enter to exit...")
        return
    
    logger.log("\nStarting conversions...")
    logger.log("=" * 80)
    
    for i, file_path in enumerate(mp4_files, 1):
        logger.log(f"\n[{i}/{len(mp4_files)}] {os.path.basename(file_path)}")
        process_video(file_path, logger)
    
    logger.log_summary()
    
    print(f"\nProcessing complete! Log saved to: {log_path}")
    input("\nPress Enter to exit...")


if __name__ == "__main__":
    main()