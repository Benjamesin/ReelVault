import os
import re
import shutil
import tempfile
import subprocess
import statistics
from pathlib import Path
from difflib import SequenceMatcher
import torch
import whisper

import config

# ==============================================================================
# CONFIGURATION
# ==============================================================================
VIDEOS_DIR = config.VIDEOS_DIR

# Offset threshold to be flagged as out of sync (in seconds)
DESYNC_THRESHOLD = 0.5

# AUTO_FIX:
# Set to False to just scan and display true offsets.
# Set to True to automatically shift timestamps and re-embed into the MP4!
AUTO_FIX = False

# Skip 720p folders
SKIP_720P = True

# Duration of dialogue to sample (480s = 8 minutes provides deep dialogue coverage)
SAMPLE_DURATION = 480

# Whisper model ("base.en" is fast and accurate on 1060 / 3060Ti)
WHISPER_MODEL = "base.en"
# ==============================================================================


def clean_words(text: str) -> set[str]:
    """Extracts a set of normalized words for token matching."""
    text = re.sub(r"<[^>]+>", "", text)
    words = re.findall(r"\b[a-zA-Z]{3,}\b", text.lower())
    return set(words)


def clean_text(text: str) -> str:
    text = re.sub(r"<[^>]+>", "", text)
    text = re.sub(r"[^a-zA-Z0-9\s]", "", text.lower())
    return " ".join(text.split())


def time_to_sec(t_str: str) -> float:
    t_str = t_str.replace(',', '.')
    parts = t_str.split(':')
    if len(parts) == 3:
        return float(parts[0]) * 3600 + float(parts[1]) * 60 + float(parts[2])
    elif len(parts) == 2:
        return float(parts[0]) * 60 + float(parts[1])
    return float(t_str)


def sec_to_time(sec: float, is_vtt: bool = True) -> str:
    if sec < 0:
        sec = 0.0
    total_ms = int(round(sec * 1000))
    ms = total_ms % 1000
    total_s = total_ms // 1000
    s = total_s % 60
    total_m = total_s // 60
    m = total_m % 60
    h = total_m // 60
    delim = '.' if is_vtt else ','
    return f"{h:02d}:{m:02d}:{s:02d}{delim}{ms:03d}"


def get_or_extract_subtitles(video_path: Path, temp_dir: Path) -> Path | None:
    """Finds external .vtt/.srt or extracts the embedded subtitle stream."""
    for ext in [".vtt", ".srt"]:
        ext_sub = video_path.with_suffix(ext)
        if ext_sub.is_file() and ext_sub.stat().st_size > 0:
            return ext_sub

    if video_path.parent.name.lower() in ["720p", "720"]:
        for ext in [".vtt", ".srt"]:
            parent_sub = video_path.parent.parent / f"{video_path.stem}{ext}"
            if parent_sub.is_file() and parent_sub.stat().st_size > 0:
                return parent_sub

    out_sub = temp_dir / f"{video_path.stem}_internal.vtt"
    cmd = [
        "ffmpeg", "-y", "-v", "error",
        "-i", str(video_path),
        "-map", "0:s:0",
        str(out_sub)
    ]
    res = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if res.returncode == 0 and out_sub.is_file() and out_sub.stat().st_size > 100:
        return out_sub

    return None


def parse_subtitles(sub_path: Path) -> list[dict]:
    """Robust subtitle parser handling WebVTT and SRT."""
    cues = []
    time_pattern = re.compile(r"(\d{1,2}:\d{2}:\d{2}[.,]\d{3}|\d{2}:\d{2}[.,]\d{3})\s*-->\s*(\d{1,2}:\d{2}:\d{2}[.,]\d{3}|\d{2}:\d{2}[.,]\d{3})")

    with open(sub_path, "r", encoding="utf-8-sig", errors="ignore") as f:
        content = f.read()

    blocks = content.replace("\r\n", "\n").split("\n\n")
    for block in blocks:
        lines = [line.strip() for line in block.split("\n") if line.strip()]
        for idx, line in enumerate(lines):
            match = time_pattern.search(line)
            if match:
                start = time_to_sec(match.group(1))
                end = time_to_sec(match.group(2))
                raw_text = " ".join(lines[idx + 1:])
                cleaned = clean_text(raw_text)
                words = clean_words(raw_text)
                if cleaned:
                    cues.append({
                        "start": start,
                        "end": end,
                        "text": cleaned,
                        "words": words
                    })
                break
    return cues


def find_densest_dialogue_window(sub_cues: list, window_duration: float = 480.0, min_start: float = 180.0) -> float:
    """Finds the timestamp in the movie with the heaviest dialogue concentration."""
    valid_cues = [c for c in sub_cues if c["start"] >= min_start and len(c["text"]) > 6]
    if not valid_cues:
        valid_cues = [c for c in sub_cues if len(c["text"]) > 6]
        if not valid_cues:
            return min_start

    best_start = valid_cues[0]["start"]
    max_count = 0

    for i, cue in enumerate(valid_cues):
        w_start = cue["start"]
        w_end = w_start + window_duration
        count = sum(1 for c in valid_cues[i:] if c["start"] <= w_end)
        if count > max_count:
            max_count = count
            best_start = w_start

    return best_start


def extract_audio_sample(video_path: Path, output_wav: Path, start_sec: float, duration: float) -> bool:
    """Extracts audio slice with speech volume normalization."""
    cmd = [
        "ffmpeg", "-y", "-v", "error",
        "-ss", f"{start_sec:.2f}",
        "-t", f"{duration:.2f}",
        "-i", str(video_path),
        "-map", "0:a:0?",
        "-vn", "-ar", "16000", "-ac", "1",
        "-af", "volume=1.5",
        "-f", "wav", str(output_wav)
    ]
    res = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    return res.returncode == 0 and output_wav.stat().st_size > 10000


def calculate_exact_offset(whisper_segments: list, sub_cues: list, window_start: float, window_end: float) -> tuple[float | None, int, str]:
    """Matches dialogue using token-overlap and cluster voting."""
    relevant_cues = [
        c for c in sub_cues 
        if (window_start - 75) <= c["start"] <= (window_end + 75) and len(c["words"]) >= 2
    ]

    if not relevant_cues:
        return None, 0, "No subtitle cues in this window"

    offsets = []

    for seg in whisper_segments:
        spoken_raw = seg["text"]
        spoken_words = clean_words(spoken_raw)
        spoken_clean = clean_text(spoken_raw)
        
        if len(spoken_words) < 2:
            continue
        
        spoken_time = seg["start"] + window_start

        best_score = 0.0
        best_diff = 0.0

        for cue in relevant_cues:
            if abs(spoken_time - cue["start"]) > 60.0:
                continue

            # 1. Word-set intersection (Jaccard similarity)
            common_words = spoken_words.intersection(cue["words"])
            if len(common_words) >= 2:
                word_ratio = len(common_words) / max(len(spoken_words), len(cue["words"]))
                if word_ratio >= 0.55:
                    score = word_ratio + 0.2
                    if score > best_score:
                        best_score = score
                        best_diff = spoken_time - cue["start"]
                    continue

            # 2. Fuzzy sequence match fallback
            if len(spoken_clean) >= 8 and len(cue["text"]) >= 8:
                ratio = SequenceMatcher(None, spoken_clean, cue["text"]).ratio()
                if ratio >= 0.68:
                    if ratio > best_score:
                        best_score = ratio
                        best_diff = spoken_time - cue["start"]

        if best_score > 0:
            offsets.append(best_diff)

    if len(offsets) < 4:
        return None, len(offsets), f"Only {len(offsets)} matches found (need >= 4)"

    # --- CLUSTER VOTING ALGORITHM ---
    # Find the cluster with the highest number of matches agreeing within +/- 0.35s
    best_cluster = []
    for cand in offsets:
        cluster = [x for x in offsets if abs(x - cand) <= 0.35]
        if len(cluster) > len(best_cluster):
            best_cluster = cluster

    if len(best_cluster) >= 4:
        median_offset = statistics.median(best_cluster)
        return median_offset, len(best_cluster), "Success"

    return None, len(offsets), f"Matches scattered (best cluster had {len(best_cluster)} lines, need 4)"


def apply_offset_to_vtt(input_sub: Path, output_sub: Path, offset_sec: float):
    time_pattern = re.compile(r"(\d{1,2}:\d{2}:\d{2}[.,]\d{3}|\d{2}:\d{2}[.,]\d{3})\s*-->\s*(\d{1,2}:\d{2}:\d{2}[.,]\d{3}|\d{2}:\d{2}[.,]\d{3})")
    is_vtt = input_sub.suffix.lower() == ".vtt"

    with open(input_sub, "r", encoding="utf-8", errors="ignore") as f:
        lines = f.readlines()

    with open(output_sub, "w", encoding="utf-8") as f:
        for line in lines:
            match = time_pattern.search(line)
            if match:
                s_orig = time_to_sec(match.group(1))
                e_orig = time_to_sec(match.group(2))

                s_new = max(0.0, s_orig + offset_sec)
                e_new = max(0.0, e_orig + offset_sec)

                new_line = line.replace(match.group(1), sec_to_time(s_new, is_vtt)).replace(match.group(2), sec_to_time(e_new, is_vtt))
                f.write(new_line)
            else:
                f.write(line)


def remux_sub_into_mp4(video_path: Path, fixed_sub: Path):
    temp_output = video_path.with_suffix(".temp.mp4")
    cmd = [
        "ffmpeg", "-y", "-v", "error",
        "-i", str(video_path),
        "-i", str(fixed_sub),
        "-map", "0:v",
        "-map", "0:a?",
        "-map", "1:s:0",
        "-c:v", "copy",
        "-c:a", "copy",
        "-c:s", "mov_text",
        "-metadata:s:s:0", "language=eng",
        "-metadata:s:s:0", "title=English",
        "-disposition:s:0", "default",
        "-movflags", "+faststart",
        str(temp_output)
    ]
    res = subprocess.run(cmd)
    if res.returncode == 0 and temp_output.exists() and temp_output.stat().st_size > 1000:
        os.replace(temp_output, video_path)
        return True
    if temp_output.exists():
        os.remove(temp_output)
    return False


def main():
    if not VIDEOS_DIR.exists():
        print(f"Error: {VIDEOS_DIR} does not exist.")
        return

    print("=" * 80)
    print("AI-POWERED SUBTITLE SYNCHRONIZER (Whisper GPU)")
    print(f"Loading '{WHISPER_MODEL}' onto GPU...")
    model = whisper.load_model(WHISPER_MODEL, device="cuda")
    
    use_fp16 = torch.cuda.is_available() and torch.cuda.get_device_capability()[0] >= 7
    print(f"Precision mode: {'FP16 (Tensor Core)' if use_fp16 else 'FP32 (Pascal)'}")
    print(f"Sampling Window: {int(SAMPLE_DURATION / 60)} minutes per movie")
    print("=" * 80 + "\n")

    videos = [
        v for v in VIDEOS_DIR.rglob("*.mp4") 
        if not (SKIP_720P and any(p.lower() in ["720p", "720"] for p in v.parts))
    ]

    with tempfile.TemporaryDirectory() as temp_dir:
        temp_dir_path = Path(temp_dir)
        sample_wav = temp_dir_path / "sample.wav"

        for idx, video in enumerate(videos, 1):
            sub_file = get_or_extract_subtitles(video, temp_dir_path)
            if not sub_file:
                print(f"[{idx}/{len(videos)}] {video.name[:38]:<40} -> [NO SUBTITLES FOUND]")
                continue

            sub_cues = parse_subtitles(sub_file)
            if not sub_cues:
                print(f"[{idx}/{len(videos)}] {video.name[:38]:<40} -> [EMPTY SUBTITLES]")
                continue

            # Pass 1: Primary densest dialogue window
            best_start = find_densest_dialogue_window(sub_cues, window_duration=SAMPLE_DURATION, min_start=180.0)

            offset = None
            matches = 0
            reason = ""

            # Attempt Pass 1, then fallback to Pass 2 (minute 20:00) if dialogue was insufficient
            for attempt, start_time in enumerate([best_start, 1200.0], 1):
                if not extract_audio_sample(video, sample_wav, start_time, SAMPLE_DURATION):
                    continue

                result = model.transcribe(str(sample_wav), fp16=use_fp16, language="en")
                segments = result.get("segments", [])

                if not segments:
                    reason = "Whisper detected 0 speech in audio slice"
                    continue

                offset, matches, reason = calculate_exact_offset(segments, sub_cues, start_time, start_time + SAMPLE_DURATION)
                if offset is not None:
                    best_start = start_time
                    break

            if offset is None:
                print(f"[{idx}/{len(videos)}] {video.name[:38]:<40} -> [FAILED: {reason}]")
                continue

            is_synced = abs(offset) < DESYNC_THRESHOLD

            if is_synced:
                status = "✓ OK"
                fix_status = ""
            else:
                status = f"❌ OFF BY {offset:+.2f}s"
                if AUTO_FIX:
                    fixed_sub = temp_dir_path / f"fixed_{sub_file.name}"
                    apply_offset_to_vtt(sub_file, fixed_sub, offset)
                    
                    if sub_file.suffix in [".vtt", ".srt"] and sub_file.parent != temp_dir_path:
                        shutil.copy2(fixed_sub, sub_file)

                    if remux_sub_into_mp4(video, fixed_sub):
                        fix_status = " -> [AUTO-FIXED & REMUXED!]"
                    else:
                        fix_status = " -> [FIX FAILED]"
                else:
                    fix_status = " -> [NEEDS FIX]"

            min_str = f"{int(best_start // 60)}m"
            print(f"[{idx}/{len(videos)}] {video.name[:38]:<40} -> {status:<20} ({matches} consensus lines @ {min_str}){fix_status}")


if __name__ == "__main__":
    main()