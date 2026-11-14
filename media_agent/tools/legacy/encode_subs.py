import os
import subprocess
import sys
import json
from datetime import datetime
from typing import Optional, Tuple
from pathlib import Path

import config

# ==============================================================================
# CONFIGURATION
# ==============================================================================
SOURCE_DIR = str(config.VIDEOS_DIR)
LOG_DIR = str(config.LOG_DIR)

# Set to True to include 720p folders; False to skip them
PROCESS_720P = True

# Statistics counters
stats = {
    'total_files': 0,
    'has_internal_sub': 0,
    'no_vtt_found': 0,
    'embedded': 0,
    'failed': 0
}


class Logger:
    """Handles logging to both console and file."""
    
    def __init__(self, log_file: str):
        self.log_file = log_file
        os.makedirs(LOG_DIR, exist_ok=True)
        
        with open(self.log_file, 'w', encoding='utf-8') as f:
            f.write("=" * 80 + "\n")
            f.write(f"Subtitle Embedding Log - {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
            f.write(f"Source Directory: {SOURCE_DIR}\n")
            f.write(f"Include 720p: {PROCESS_720P}\n")
            f.write("=" * 80 + "\n\n")
    
    def log(self, message: str, to_console: bool = True, to_file: bool = True):
        timestamp = datetime.now().strftime('%H:%M:%S')
        formatted = f"[{timestamp}] {message}"
        
        if to_console:
            print(formatted)
        if to_file:
            with open(self.log_file, 'a', encoding='utf-8') as f:
                f.write(formatted + "\n")
    
    def log_summary(self):
        lines = [
            "",
            "=" * 80,
            "SUBTITLE EMBEDDING SUMMARY",
            "=" * 80,
            f"Total MP4 files scanned: {stats['total_files']}",
            f"Already had built-in sub (skipped): {stats['has_internal_sub']}",
            f"No matching .vtt found (skipped): {stats['no_vtt_found']}",
            f"Successfully embedded subtitles: {stats['embedded']}",
            f"Failed: {stats['failed']}",
            "=" * 80
        ]
        for line in lines:
            self.log(line)


def has_embedded_subtitles(file_path: str) -> bool:
    """Checks if the MP4 already has an embedded/internal subtitle stream."""
    try:
        command = [
            "ffprobe",
            "-v", "error",
            "-select_streams", "s",
            "-show_entries", "stream=index",
            "-of", "json",
            file_path
        ]
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=10)
        data = json.loads(result.stdout)
        return len(data.get('streams', [])) > 0
    except Exception:
        return False


def find_matching_vtt(file_path: str) -> Optional[str]:
    """
    Locates the matching .vtt file.
    1. Checks the same directory: VideoName.vtt
    2. If the video is inside a 720p subfolder, checks the parent folder for the .vtt
    """
    base_name = os.path.splitext(file_path)[0]
    direct_vtt = base_name + ".vtt"

    # 1. Exact match in the same directory
    if os.path.isfile(direct_vtt) and os.path.getsize(direct_vtt) > 0:
        return direct_vtt

    # 2. Fallback for 720p subfolders: check parent directory
    p = Path(file_path)
    if p.parent.name.lower() in ["720p", "720"]:
        parent_dir = p.parent.parent
        
        # Check parent with exact stem
        parent_exact = parent_dir / f"{p.stem}.vtt"
        if parent_exact.is_file() and parent_exact.stat().st_size > 0:
            return str(parent_exact)

        # Check parent with stripped resolution suffix (e.g. movie_720p.mp4 -> movie.vtt)
        clean_stem = p.stem.lower().replace("_720p", "").replace("-720p", "").replace(".720p", "")
        parent_clean = parent_dir / f"{clean_stem}.vtt"
        if parent_clean.is_file() and parent_clean.stat().st_size > 0:
            return str(parent_clean)

        # If parent folder has only one .vtt file, use it
        parent_vtts = list(parent_dir.glob("*.vtt"))
        if len(parent_vtts) == 1 and parent_vtts[0].stat().st_size > 0:
            return str(parent_vtts[0])

    return None


def embed_subtitle(video_path: str, vtt_path: str, output_path: str) -> Tuple[bool, str]:
    """
    Embeds the matching .vtt subtitle into the MP4 as mov_text
    using stream copy (instant, lossless, Apple TV/AirPlay compatible).
    """
    command = [
        "ffmpeg",
        "-i", video_path,
        "-i", vtt_path,
        "-map", "0:v",                  # Preserve all video streams
        "-map", "0:a?",                 # Preserve all audio streams if present
        "-map", "1:s:0",                # Map the first subtitle stream from the .vtt
        "-c:v", "copy",                 # Fast & lossless copy
        "-c:a", "copy",                 # Fast & lossless copy
        "-c:s", "mov_text",             # MP4 Timed Text (Apple standard)
        "-metadata:s:s:0", "language=eng",
        "-metadata:s:s:0", "title=English",
        "-disposition:s:0", "default",  # Marks track as default for AirPlay / Apple TV
        "-movflags", "+faststart",
        "-y", output_path
    ]
    
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if result.returncode != 0:
        return False, result.stderr.strip()
    return True, ""


def process_video(file_path: str, logger: Logger) -> bool:
    filename = os.path.basename(file_path)
    logger.log(f"\nProcessing: {filename}")
    
    # 1. Check if the video already contains an internal subtitle track
    if has_embedded_subtitles(file_path):
        logger.log("  ⊘ Already has built-in subtitle - Skipping")
        stats['has_internal_sub'] += 1
        return True

    # 2. Check for matching .vtt (direct match or parent directory fallback)
    vtt_path = find_matching_vtt(file_path)

    if not vtt_path:
        expected_name = os.path.splitext(filename)[0] + ".vtt"
        logger.log(f"  ⊘ No matching .vtt found ({expected_name}) - Skipping")
        stats['no_vtt_found'] += 1
        return True

    logger.log(f"  ✓ Found matching subtitle: {os.path.basename(vtt_path)}")
    
    temp_output = file_path + ".temp.mp4"
    
    try:
        logger.log("  Muxing subtitles into MP4 (lossless stream copy)...")
        start_time = datetime.now()
        success, error_msg = embed_subtitle(file_path, vtt_path, temp_output)
        
        # Verify result file
        if not success or not os.path.exists(temp_output) or os.path.getsize(temp_output) < 1000:
            logger.log(f"  ✗ Muxing failed: {error_msg}")
            stats['failed'] += 1
            if os.path.exists(temp_output):
                os.remove(temp_output)
            return False

        elapsed = (datetime.now() - start_time).total_seconds()
        
        # In-place atomic replacement: replaces original without taking backup storage
        os.replace(temp_output, file_path)
        logger.log(f"  ✓ Subtitle track embedded in {elapsed:.2f}s (in-place)!")
        
        stats['embedded'] += 1
        return True

    except Exception as e:
        logger.log(f"  ✗ Error: {e}")
        stats['failed'] += 1
        if os.path.exists(temp_output):
            os.remove(temp_output)
        return False


def main():
    if not os.path.exists(SOURCE_DIR):
        print(f"Error: Source directory '{SOURCE_DIR}' does not exist!")
        input("Press Enter to exit...")
        return
    
    log_filename = f"subtitle_embed_{datetime.now().strftime('%Y%m%d_%H%M%S')}.txt"
    log_path = os.path.join(LOG_DIR, log_filename)
    logger = Logger(log_path)
    
    logger.log("Starting Scan: Missing Subtitle Detection & Lossless Embedding")
    logger.log(f"Source Directory: {SOURCE_DIR}")
    logger.log(f"Process 720p Folders: {PROCESS_720P}")
    logger.log("=" * 80)
    
    logger.log("\nScanning for MP4 files...")
    mp4_files = []
    
    # Configure folders to skip
    skip_dirs = {"stills"}
    if not PROCESS_720P:
        skip_dirs.update(["720p", "720"])
    
    for root, dirs, files in os.walk(SOURCE_DIR):
        # Modify dirs in-place to prevent os.walk from entering excluded folders
        dirs[:] = [d for d in dirs if d.lower() not in skip_dirs]
        
        for file in files:
            if file.lower().endswith('.mp4'):
                mp4_files.append(os.path.join(root, file))
    
    stats['total_files'] = len(mp4_files)
    logger.log(f"Found {len(mp4_files)} MP4 files")
    
    if len(mp4_files) == 0:
        logger.log("No MP4 files found. Exiting.")
        input("Press Enter to exit...")
        return
    
    logger.log("\nProcessing files...")
    logger.log("=" * 80)
    
    for i, file_path in enumerate(mp4_files, 1):
        logger.log(f"\n[{i}/{len(mp4_files)}] {os.path.basename(file_path)}")
        process_video(file_path, logger)
    
    logger.log_summary()
    print(f"\nProcessing complete! Log saved to: {log_path}")
    input("\nPress Enter to exit...")


if __name__ == "__main__":
    main()