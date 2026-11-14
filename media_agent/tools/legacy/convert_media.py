import os
import subprocess
import re
import sys
import json
from typing import Optional

import config

# Import TitleExtractor from the companion module
from .title_extractor import TitleExtractor

# add this import for webvtt usage (check handled in check_dependencies)
try:
    from webvtt import WebVTT  # type: ignore
except Exception:
    WebVTT = None

# --- CONFIGURATION ---
# Define the path of the folder with subfolders
folder_path = str(config.DOWNLOADS_DIR)

# Enable or disable file renaming during conversion
ENABLE_FILE_RENAMING = True
# --------------------


def get_video_duration(file_path):
    """Get the duration of a video file in seconds."""
    try:
        command =[
            "ffprobe",
            "-v", "error",
            "-show_entries", "format=duration",
            "-of", "default=noprint_wrappers=1:nokey=1",
            file_path
        ]
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=10)
        duration = float(result.stdout.strip())
        return duration
    except Exception as e:
        print(f"  Warning: Could not get duration: {e}")
        return None


def get_video_resolution(file_path):
    """Get the resolution (height) of a video file."""
    try:
        command =[
            "ffprobe",
            "-v", "error",
            "-select_streams", "v:0",
            "-show_entries", "stream=height",
            "-of", "default=noprint_wrappers=1:nokey=1",
            file_path
        ]
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=10)
        height = int(result.stdout.strip())
        return height
    except Exception as e:
        print(f"  Warning: Could not get resolution: {e}")
        return None


def run_ffmpeg_with_progress(command, duration=None):
    """Run FFmpeg command and display progress bar."""
    process = subprocess.Popen(
        command,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        universal_newlines=True,
        bufsize=1
    )

    time_pattern = re.compile(r'time=(\d+):(\d+):(\d+\.\d+)')

    last_progress = 0
    for line in process.stdout:
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

                if progress > 0 and progress < 100:
                    elapsed = current_time
                    total_estimated = (elapsed / progress) * 100
                    eta_seconds = total_estimated - elapsed
                    eta_mins = int(eta_seconds / 60)
                    eta_secs = int(eta_seconds % 60)
                    eta_str = f"ETA: {eta_mins}m {eta_secs}s"
                else:
                    eta_str = ""

                sys.stdout.write(f'\r  Progress:[{bar}] {progress:.1f}% {eta_str}')
                sys.stdout.flush()
                last_progress = progress

    process.wait()

    if duration:
        sys.stdout.write('\n')
        sys.stdout.flush()

    return process.returncode


def check_dependencies():
    """Check if required tools are available."""
    print("Checking dependencies...")

    try:
        subprocess.run(["ffmpeg", "-version"], capture_output=True, text=True, timeout=5)
        print("✓ FFmpeg found")
    except FileNotFoundError:
        print("✗ FFmpeg not found!")
        print("  Please install FFmpeg and add it to your system PATH")
        print("  Download from: https://ffmpeg.org/download.html")
        return False
    except Exception as e:
        print(f"✗ Error checking FFmpeg: {e}")
        return False

    try:
        subprocess.run(["ffprobe", "-version"], capture_output=True, text=True, timeout=5)
        print("✓ FFprobe found")
    except FileNotFoundError:
        print("✗ FFprobe not found!")
        return False
    except Exception as e:
        print(f"✗ Error checking FFprobe: {e}")
        return False

    try:
        if WebVTT is not None:
            print("✓ webvtt-py found")
        else:
            raise ImportError
    except Exception:
        print("✗ webvtt-py not found (subtitle conversion will use a minimal fallback).")
        print("  Install with: pip install webvtt-py")

    print()
    return True


def sanitize_filename(filename):
    """Remove invalid characters from filename for Windows compatibility."""
    invalid_chars = r'[<>:"/\\|?*]'
    sanitized = re.sub(invalid_chars, '', filename)
    sanitized = sanitized.strip('. ')
    return sanitized


def find_srt_for_video(root: str, video_filename: str) -> Optional[str]:
    """Find a likely .srt file in the same folder for the given video file."""
    basename = os.path.splitext(video_filename)[0].lower()
    try:
        all_files = os.listdir(root)
    except Exception:
        return None
    srt_candidates = [f for f in all_files if f.lower().endswith(".srt")]
    if not srt_candidates:
        return None

    for s in srt_candidates:
        if os.path.splitext(s)[0].lower() == basename:
            return os.path.join(root, s)

    for prefer in ('eng', 'english', '.en', '_en'):
        for s in srt_candidates:
            if prefer in s.lower():
                return os.path.join(root, s)

    if len(srt_candidates) == 1:
        return os.path.join(root, srt_candidates[0])

    return None


def convert_srt_to_vtt(srt_path: str, vtt_path: str) -> bool:
    """Convert an SRT file to WebVTT."""
    if WebVTT is not None:
        try:
            w = WebVTT().from_srt(srt_path)
            w.save(vtt_path)
            return True
        except Exception:
            pass

    try:
        with open(srt_path, 'r', encoding='utf-8', errors='replace') as sf, \
             open(vtt_path, 'w', encoding='utf-8') as vf:
            vf.write("WEBVTT\n\n")
            for line in sf:
                strip = line.strip()
                if strip.isdigit():
                    continue
                if '-->' in line:
                    line = line.replace(',', '.')
                vf.write(line)
        return True
    except Exception:
        return False


def find_embedded_english_subtitle(file_path):
    """Finds the absolute stream index of a compatible English subtitle stream."""
    try:
        command =[
            "ffprobe", "-v", "error",
            "-select_streams", "s",
            "-show_streams",  # Captures complete stream dictionary
            "-of", "json", file_path
        ]
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=15)
        if not result.stdout.strip():
            return None
            
        data = json.loads(result.stdout)
        streams = data.get('streams',[])
        
        # Format filters to block image-based subs that cannot be easily exported to .srt
        image_codecs =['hdmv_pgs_subtitle', 'dvd_subtitle', 'vobsub']
        def is_text_subs(s): return s.get('codec_name', '') not in image_codecs

        # 1. Priority: English + Default + Text-based
        for stream in streams:
            tags = stream.get('tags', {})
            lang = tags.get('language', '').lower()
            title = tags.get('title', '').lower()
            is_default = stream.get('disposition', {}).get('default', 0)
            if (lang in ['eng', 'en', 'english'] or 'english' in title) and is_default:
                if is_text_subs(stream): return stream.get('index')

        # 2. Priority: English + Text-based
        for stream in streams:
            tags = stream.get('tags', {})
            lang = tags.get('language', '').lower()
            title = tags.get('title', '').lower()
            if lang in['eng', 'en', 'english'] or 'english' in title:
                if is_text_subs(stream): return stream.get('index')

        # 3. Priority: Default + Text-based
        for stream in streams:
            is_default = stream.get('disposition', {}).get('default', 0)
            if is_default and is_text_subs(stream): return stream.get('index')

        # 4. Priority: Any valid text-based sub
        for stream in streams:
            if is_text_subs(stream): return stream.get('index')

        return None
    except Exception as e:
        print(f"  [SUBS] Error probing for subs: {e}")
        return None


def extract_embedded_subtitle(input_path, output_srt, stream_index):
    """Extracts a specific subtitle stream to an SRT file."""
    try:
        # We explicitly use '0:{stream_index}' which maps correctly to the absolute index
        command =[
            "ffmpeg", "-y", "-i", input_path,
            "-map", f"0:{stream_index}",
            output_srt
        ]
        subprocess.run(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)
        return True
    except Exception as e:
        print(f"  [SUBS] Extraction failed: {e}")
        return False


def get_best_audio_stream_index(file_path):
    """Finds the absolute stream index of the best matching audio."""
    try:
        command =[
            "ffprobe",
            "-v", "error",
            "-select_streams", "a",
            "-show_streams", # Use show_streams to reliably grab dispositions & tags
            "-of", "json",
            file_path
        ]
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=15)
        if not result.stdout.strip():
            return "a:0"
            
        data = json.loads(result.stdout)
        streams = data.get('streams',[])
        
        if not streams:
            return "a:0"

        # 1. Look for English + Default
        for stream in streams:
            lang = stream.get('tags', {}).get('language', '').lower()
            is_default = stream.get('disposition', {}).get('default', 0)
            if lang in ['eng', 'en', 'english'] and is_default:
                return stream.get('index', 'a:0')

        # 2. Look for English
        for stream in streams:
            lang = stream.get('tags', {}).get('language', '').lower()
            if lang in ['eng', 'en', 'english']:
                return stream.get('index', 'a:0')

        # 3. Look for Default
        for stream in streams:
            if stream.get('disposition', {}).get('default', 0):
                return stream.get('index', 'a:0')

        # 4. Fallback to first audio stream
        return streams[0].get('index', 'a:0')
        
    except Exception as e:
        print(f"  Warning: Could not analyze audio streams: {e}")
        return "a:0"


def main():
    """Main function to process and convert video files."""
    #if not check_dependencies():
     #   print("\nPlease install required dependencies and try again.")
     #   input("Press Enter to exit...")
     #   return

    # Initialize title extractor (imported from title_extractor.py)
    extractor = TitleExtractor()

    converted_root = os.path.join(folder_path, "converted")

    if not os.path.exists(folder_path):
        print(f"Error: Folder path '{folder_path}' does not exist!")
        input("Press Enter to exit...")
        return

    print(f"Starting conversion process in: {folder_path}")
    print(f"Output directory: {converted_root}")
    print("=" * 70)

    processed = 0
    skipped = 0
    converted = 0
    errors = 0

    for root, dirs, files in os.walk(folder_path):
        if os.path.abspath(root).startswith(os.path.abspath(converted_root)):
            continue

        for file in files:
            if not (file.lower().endswith(".mp4") or file.lower().endswith(".mkv") or file.lower().endswith(".avi")):
                continue

            input_path = os.path.join(root, file)
            processed += 1

            rel_dir = os.path.relpath(root, folder_path)
            if rel_dir == ".":
                rel_dir = ""
            output_dir = os.path.join(converted_root, rel_dir)
            os.makedirs(output_dir, exist_ok=True)

            if ENABLE_FILE_RENAMING:
                title = extractor.extract_title(file)
                title = sanitize_filename(title)
                if not title:
                    title = os.path.splitext(file)[0]
                    title = sanitize_filename(title)
                output_name = title + ".mp4"
            else:
                output_name = os.path.splitext(file)[0] + ".mp4"
                title = os.path.splitext(file)[0]

            output_path = os.path.join(output_dir, output_name)

            # ── Subtitle handling ────────────────────────────────────────────
            """
            try:
                # Placed name formulation outside the conditional to fix UnboundLocalError bug
                vtt_name = sanitize_filename(title) + ".vtt"
                vtt_output_path = os.path.join(output_dir, vtt_name)

                if not os.path.exists(vtt_output_path):
                    srt_path = find_srt_for_video(root, file)

                    if srt_path:
                        print(f"  [SUBS] Found External SRT: {os.path.basename(srt_path)}")
                        convert_srt_to_vtt(srt_path, vtt_output_path)
                    else:
                        sub_index = find_embedded_english_subtitle(input_path)
                        if sub_index is not None:
                            print(f"  [SUBS] Found Embedded English Sub (Index {sub_index}). Extracting...")
                            temp_srt = os.path.join(output_dir, "temp_extract.srt")
                            if extract_embedded_subtitle(input_path, temp_srt, sub_index):
                                if convert_srt_to_vtt(temp_srt, vtt_output_path):
                                    print(f"  [SUBS] Successfully extracted and converted embedded subs.")
                                if os.path.exists(temp_srt):
                                    os.remove(temp_srt)
                        else:
                            print(f"  [SUBS] No English subtitles found (external or embedded).")
                else:
                    print(f"  [SUBS] VTT already exists: {vtt_name}")
            except Exception as e:
                print(f"  [SUBS] Subtitle handling error: {e}")
            """

            # ── Skip if already converted ────────────────────────────────────
            if os.path.exists(output_path):
                print(f"[SKIP] Already exists: {output_name}")
                skipped += 1
                continue

            print(f"\n[PROCESSING] {file}")
            print(f"  Input:  {input_path}")
            print(f"  Output: {output_path}")
            print(f"  Title:  {title}")

            duration = get_video_duration(input_path)
            if duration:
                mins = int(duration / 60)
                secs = int(duration % 60)
                print(f"  Duration: {mins}m {secs}s")

            success = False

            # Retrieve Audio mapping (Handles absolute indices securely)
            audio_index = get_best_audio_stream_index(input_path)
            print(f"  Selected audio stream index: {audio_index}")

            # ── MKV optimised path ───────────────────────────────────────────
            if file.lower().endswith(".mkv"):
                print(f"  Attempting optimized MKV conversion...")
                source_height = get_video_resolution(input_path)
                if source_height:
                    print(f"  Source resolution: {source_height}p")
                    if source_height < 1080:
                        scale_filter = f"scale=-1:{source_height},format=yuv420p"
                        print(f"  Keeping original {source_height}p resolution")
                    else:
                        scale_filter = "scale=1920:1080,format=yuv420p"
                        print(f"  Downscaling to 1080p")
                else:
                    scale_filter = "scale=1920:1080,format=yuv420p"
                    print(f"  Could not detect resolution, defaulting to 1080p")

                command_mkv =[
                    "ffmpeg",
                    "-i", input_path,
                    "-map", "0:v:0",
                    "-map", f"0:{audio_index}",
                    "-map", "-0:t",
                    "-vf", scale_filter,
                    "-c:v", "libx264",
                    "-preset", "slow",
                    "-crf", "18",
                    "-c:a", "aac",
                    "-b:a", "192k",
                    "-movflags", "faststart",
                    "-y",
                    output_path
                ]

                try:
                    returncode = run_ffmpeg_with_progress(command_mkv, duration)
                    if returncode == 0:
                        print(f"[SUCCESS] Converted with optimized MKV settings: {output_name}")
                        converted += 1
                        success = True
                    else:
                        print(f"  Optimized MKV conversion failed, trying standard encoding...")
                except Exception as e:
                    print(f"  Optimized MKV conversion error: {e}, trying standard encoding...")

            # ── CUDA path ────────────────────────────────────────────────────
            if not success:
                command_cuda =[
                    "ffmpeg",
                    "-hwaccel", "cuda",
                    "-hwaccel_output_format", "cuda",
                    "-i", input_path,
                    "-map", "0:v:0",
                    "-map", f"0:{audio_index}",
                    "-c:v", "h264_nvenc",
                    "-preset", "p4",
                    "-cq", "23",
                    "-ac", "2",
                    "-c:a", "aac",
                    "-b:a", "192k",
                    "-movflags", "faststart",
                    "-y",
                    output_path
                ]

                try:
                    print(f"  Attempting CUDA encoding...")
                    returncode = run_ffmpeg_with_progress(command_cuda, duration)
                    if returncode == 0:
                        print(f"[SUCCESS] Converted with CUDA: {output_name}")
                        converted += 1
                        success = True
                    else:
                        raise subprocess.CalledProcessError(returncode, command_cuda)
                except subprocess.CalledProcessError:
                    print(f"  CUDA encoding failed, trying CPU fallback...")
                    command_cpu =[
                        "ffmpeg",
                        "-i", input_path,
                        "-map", "0:v:0",
                        "-map", f"0:{audio_index}",
                        "-c:v", "libx264",
                        "-preset", "medium",
                        "-crf", "23",
                        "-ac", "2",
                        "-c:a", "aac",
                        "-b:a", "192k",
                        "-movflags", "faststart",
                        "-y",
                        output_path
                    ]

                    try:
                        returncode = run_ffmpeg_with_progress(command_cpu, duration)
                        if returncode == 0:
                            print(f"[SUCCESS] Converted with CPU: {output_name}")
                            converted += 1
                            success = True
                        else:
                            print(f"[ERROR] CPU encoding failed for {file}")
                            errors += 1
                    except Exception as e2:
                        print(f"[ERROR] Unexpected error during CPU encoding: {e2}")
                        errors += 1
                except Exception as e:
                    print(f"[ERROR] Unexpected error during CUDA encoding: {e}")
                    errors += 1

    # ── Summary ──────────────────────────────────────────────────────────────
    print("\n" + "=" * 70)
    print("CONVERSION SUMMARY")
    print("=" * 70)
    print(f"Files processed:  {processed}")
    print(f"Files converted:  {converted}")
    print(f"Files skipped:    {skipped}")
    print(f"Errors:           {errors}")
    print("=" * 70)


if __name__ == "__main__":
    main()