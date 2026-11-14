import gc
import os
import re
import shutil
import subprocess
import tempfile
from pathlib import Path
from typing import Optional, Dict, Any

import torch
import whisper

import config
import vram
from state import load_state, save_state, notify_manual_review

# Import your untouched helpers from tools/legacy/
from tools.legacy.title_extractor import TitleExtractor
from tools.legacy import convert_media, show_posters, make_720p, sub_sync, encode_subs

VIDEO_EXTS = {".mp4", ".mkv", ".avi"}
_extractor = TitleExtractor()


def _assert_inside_media_root(path: Path) -> Path:
    resolved = path.resolve()
    allowed_roots = [
        config.BASE_MEDIA_DIR.resolve(),
        config.DOWNLOADS_DIR.resolve(),
        config.CONVERTED_DIR.resolve(),
        config.VIDEOS_DIR.resolve(),
        config.SHOWS_DIR.resolve(),
    ]
    if not any(root == resolved or root in resolved.parents for root in allowed_roots):
        raise ValueError(f"Blocked unsafe path outside configured media folders: {resolved}")
    return resolved


def inspect_pending_downloads() -> Dict[str, Any]:
    """Scan /downloads and /downloads/converted and sync with state.json."""
    state = load_state()
    items = state["items"]
    pending = []

# 1. Raw files in /downloads (excluding converted/, Featurettes/, Extras/, Samples/)
    skip_dirs = {"converted", "featurettes", "extras", "sample", "samples", "trailers", "bonus"}
    for root, dirs, files in os.walk(config.DOWNLOADS_DIR):
        dirs[:] = [d for d in dirs if d.lower() not in skip_dirs]
        for f in files:
            p = Path(root) / f
            if p.suffix.lower() in VIDEO_EXTS:
                item_id = f"raw::{p.name}"
                # Check if already converted
                rel = p.parent.relative_to(config.DOWNLOADS_DIR)
                clean = convert_media.sanitize_filename(_extractor.extract_title(f)) or p.stem
                expected_mp4 = config.CONVERTED_DIR / rel / f"{clean}.mp4"
                if not expected_mp4.exists():
                    items.setdefault(item_id, {
                        "stage": "DISCOVERED",
                        "source_path": str(p),
                        "suggested_title": clean,
                    })

# 2. Converted files in /downloads/converted
    known_converted = {v.get("converted_path") for v in items.values()}
    if config.CONVERTED_DIR.exists():
        for p in config.CONVERTED_DIR.rglob("*.mp4"):
            if str(p) in known_converted:
                continue
            item_id = p.stem
            if item_id not in items:
                items[item_id] = {
                    "stage": "CONVERTED",
                    "converted_path": str(p),
                    "has_vtt": p.with_suffix(".vtt").exists(),
                    "suggested_title": _extractor.extract_title(p.name),
                }

    save_state(state)

    for k, v in items.items():
        if v["stage"] not in ("DONE", "NEEDS_MANUAL_SUBS", "ERROR"):
            pending.append({"item_id": k, **v})

    return {"pending_count": len(pending), "items": pending[:10]}


def convert_downloaded_media(item_id: str) -> Dict[str, Any]:
    """Convert a single raw download in state.json to MP4 + extract subs to .vtt."""
    state = load_state()
    item = state["items"].get(item_id)
    if not item or not item.get("source_path"):
        return {"ok": False, "error": f"No source_path in state.json for {item_id}"}

    src = _assert_inside_media_root(Path(item["source_path"]))
    if not src.exists():
        return {"ok": False, "error": f"File not found: {src}"}

    rel = src.parent.relative_to(config.DOWNLOADS_DIR)
    out_dir = config.CONVERTED_DIR / rel
    out_dir.mkdir(parents=True, exist_ok=True)

    clean = convert_media.sanitize_filename(_extractor.extract_title(src.name)) or src.stem
    out_mp4 = out_dir / f"{clean}.mp4"
    out_vtt = out_dir / f"{clean}.vtt"

    # Extract external SRT or embedded text subtitle -> .vtt
    if not out_vtt.exists():
        srt = convert_media.find_srt_for_video(str(src.parent), src.name)
        if srt:
            convert_media.convert_srt_to_vtt(srt, str(out_vtt))
        else:
            sub_idx = convert_media.find_embedded_english_subtitle(str(src))
            if sub_idx is not None:
                tmp_srt = out_dir / f".tmp_{clean}.srt"
                if convert_media.extract_embedded_subtitle(str(src), str(tmp_srt), sub_idx):
                    convert_media.convert_srt_to_vtt(str(tmp_srt), str(out_vtt))
                if tmp_srt.exists():
                    tmp_srt.unlink()

    if not out_mp4.exists():
        vram.unload_lm_studio_models()  # Free GPU in case NVENC runs
        duration = convert_media.get_video_duration(str(src))
        audio_idx = convert_media.get_best_audio_stream_index(str(src))
        success = False

        if src.suffix.lower() == ".mkv":
            h = convert_media.get_video_resolution(str(src))
            vf = f"scale=-1:{h},format=yuv420p" if (h and h < 1080) else "scale=1920:1080,format=yuv420p"
            cmd = [
                "ffmpeg", "-i", str(src), "-map", "0:v:0", "-map", f"0:{audio_idx}", "-map", "-0:t",
                "-vf", vf, "-c:v", "libx264", "-preset", "slow", "-crf", "18",
                "-c:a", "aac", "-b:a", "192k", "-movflags", "faststart", "-y", str(out_mp4)
            ]
            success = (convert_media.run_ffmpeg_with_progress(cmd, duration) == 0)

        if not success:
            cmd_cuda = [
                "ffmpeg", "-hwaccel", "cuda", "-hwaccel_output_format", "cuda",
                "-i", str(src), "-map", "0:v:0", "-map", f"0:{audio_idx}",
                "-c:v", "h264_nvenc", "-preset", "p4", "-cq", "23", "-ac", "2",
                "-c:a", "aac", "-b:a", "192k", "-movflags", "faststart", "-y", str(out_mp4)
            ]
            success = (convert_media.run_ffmpeg_with_progress(cmd_cuda, duration) == 0)

        if not success:
            cmd_cpu = [
                "ffmpeg", "-i", str(src), "-map", "0:v:0", "-map", f"0:{audio_idx}",
                "-c:v", "libx264", "-preset", "medium", "-crf", "23", "-ac", "2",
                "-c:a", "aac", "-b:a", "192k", "-movflags", "faststart", "-y", str(out_mp4)
            ]
            success = (convert_media.run_ffmpeg_with_progress(cmd_cpu, duration) == 0)

        if not success:
            return {"ok": False, "error": "FFmpeg conversion failed on all paths"}

    state = load_state()
    if item_id in state["items"]:
        state["items"][item_id].update({
            "stage": "CONVERTED",
            "converted_path": str(out_mp4),
            "has_vtt": out_vtt.exists(),
        })
        save_state(state)

    return {
        "ok": True,
        "converted_mp4": str(out_mp4),
        "has_vtt": out_vtt.exists(),
        "vtt_path": str(out_vtt) if out_vtt.exists() else None,
    }


def move_media_to_library(
    item_id: str,
    media_type: str,
    clean_title: str,
    show_name: Optional[str] = None,
    season_number: Optional[int] = None,
) -> Dict[str, Any]:
    """Move converted MP4 (and matching .vtt) to /videos or /shows/{Show}/Season XX/."""
    state = load_state()
    item = state["items"].get(item_id)
    if not item or not item.get("converted_path"):
        return {"ok": False, "error": f"No converted_path in state.json for {item_id}"}

    src_mp4 = _assert_inside_media_root(Path(item["converted_path"]))
    if not src_mp4.exists():
        return {"ok": False, "error": f"Converted file missing: {src_mp4}"}

    safe_title = convert_media.sanitize_filename(clean_title)
    if media_type == "movie":
        dest_dir = config.VIDEOS_DIR
    elif media_type == "show":
        if not show_name or season_number is None:
            return {"ok": False, "error": "show_name and season_number required for shows"}
        safe_show = convert_media.sanitize_filename(show_name)
        dest_dir = config.SHOWS_DIR / safe_show / f"Season {int(season_number):02d}"
    else:
        return {"ok": False, "error": "media_type must be 'movie' or 'show'"}

    dest_dir.mkdir(parents=True, exist_ok=True)
    dest_mp4 = dest_dir / f"{safe_title}.mp4"
    if dest_mp4.exists():
        return {"ok": False, "error": f"Destination already exists: {dest_mp4}"}

    shutil.move(str(src_mp4), str(dest_mp4))

    src_vtt = src_mp4.with_suffix(".vtt")
    dest_vtt = dest_mp4.with_suffix(".vtt")
    if src_vtt.exists():
        shutil.move(str(src_vtt), str(dest_vtt))

    state = load_state()
    if item_id in state["items"]:
        state["items"][item_id].update({
            "stage": "MOVED",
            "media_type": media_type,
            "library_path": str(dest_mp4),
            "show_dir": str(dest_dir.parent) if media_type == "show" else None,
        })
        save_state(state)

    return {
        "ok": True,
        "media_type": media_type,
        "library_path": str(dest_mp4),
        "has_vtt": dest_vtt.exists(),
    }


def fetch_metadata(item_id: str) -> Dict[str, Any]:
    """Movies: run TMM CLI (-u -n) for .nfo + poster. Shows: fetch DuckDuckGo posters."""
    state = load_state()
    item = state["items"].get(item_id)
    if not item or not item.get("library_path"):
        return {"ok": False, "error": f"No library_path in state.json for {item_id}"}

    vid = _assert_inside_media_root(Path(item["library_path"]))
    media_type = item.get("media_type")

    if media_type == "movie":
        if not config.TMM_BIN.exists():
            return {"ok": False, "error": f"tinyMediaManager not found at {config.TMM_BIN}"}
        res = subprocess.run(
            [str(config.TMM_BIN), "movie", "-u", "-n"],
            capture_output=True, text=True, timeout=300
        )
        nfo_path = vid.with_suffix(".nfo")
        if not nfo_path.exists():
            return {
                "ok": False,
                "nfo_created": False,
                "error": f"TMM finished (code {res.returncode}) but {nfo_path.name} was not created. Check TMM title match.",
            }
        state["items"][item_id]["stage"] = "METADATA_OK"
        save_state(state)
        return {"ok": True, "nfo_path": str(nfo_path)}

    elif media_type == "show":
        show_dir = vid.parent.parent
        show_posters.process_shows(str(show_dir.parent))
        state["items"][item_id]["stage"] = "METADATA_OK"
        save_state(state)
        return {"ok": True, "show_dir": str(show_dir)}

    return {"ok": False, "error": f"Invalid media_type: {media_type}"}

def _get_audio_language(vid: Path) -> str:
    """Return the first audio stream's ISO language tag (lowercased), or '' if untagged."""
    try:
        res = subprocess.run(
            [
                "ffprobe", "-v", "error", "-select_streams", "a:0",
                "-show_entries", "stream_tags=language",
                "-of", "default=noprint_wrappers=1:nokey=1", str(vid),
            ],
            capture_output=True, text=True, timeout=10,
        )
        return res.stdout.strip().lower()
    except Exception:
        return ""


def _run_whisper_sync_once(vid: Path, vtt_path: Path, has_internal: bool) -> Dict[str, Any]:
    """Load Whisper onto CUDA, check/shift subtitles for one video, and free VRAM."""
    vram.unload_lm_studio_models()
    model = whisper.load_model(sub_sync.WHISPER_MODEL, device="cuda")
    use_fp16 = torch.cuda.is_available() and torch.cuda.get_device_capability()[0] >= 7

    try:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_dir = Path(tmp)
            sub_file = sub_sync.get_or_extract_subtitles(vid, tmp_dir)
            if not sub_file:
                return {"ok": False, "needs_manual": True, "reason": "No readable subtitle track"}

            cues = sub_sync.parse_subtitles(sub_file)
            if not cues:
                return {"ok": False, "needs_manual": True, "reason": "Subtitle file is empty"}

            best_start = sub_sync.find_densest_dialogue_window(cues, sub_sync.SAMPLE_DURATION, 180.0)
            sample_wav = tmp_dir / "sample.wav"

            offset, matches, reason = None, 0, ""
            for start_t in (best_start, 1200.0):
                if not sub_sync.extract_audio_sample(vid, sample_wav, start_t, sub_sync.SAMPLE_DURATION):
                    continue
                res = model.transcribe(str(sample_wav), fp16=use_fp16, language="en")
                segs = res.get("segments", [])
                if not segs:
                    reason = "Whisper detected 0 speech"
                    continue
                offset, matches, reason = sub_sync.calculate_exact_offset(
                    segs, cues, start_t, start_t + sub_sync.SAMPLE_DURATION
                )
                if offset is not None:
                    break

            if offset is None:
                return {"ok": False, "needs_manual": True, "reason": f"Sync match failed: {reason}"}

            if abs(offset) > config.MAX_AUTO_OFFSET_SEC:
                return {
                    "ok": False,
                    "needs_manual": True,
                    "offset_seconds": round(offset, 2),
                    "consensus_lines": matches,
                    "reason": f"Offset {offset:+.1f}s exceeds 30s threshold (likely wrong movie cut)",
                }

            status = "already_synced"
            if abs(offset) >= config.DESYNC_THRESHOLD_SEC:
                shifted_vtt = tmp_dir / "shifted.vtt"
                sub_sync.apply_offset_to_vtt(sub_file, shifted_vtt, offset)
                shutil.copy2(shifted_vtt, vtt_path)
                if has_internal:
                    sub_sync.remux_sub_into_mp4(vid, shifted_vtt)
                status = "shifted_and_saved"

            return {
                "ok": True,
                "status": status,
                "offset_seconds": round(offset, 2),
                "consensus_lines": matches,
            }
    finally:
        del model
        gc.collect()
        if torch.cuda.is_available():
            torch.cuda.empty_cache()


def fetch_and_sync_subtitles(item_id: str) -> Dict[str, Any]:
    """
    1. Ensure .vtt exists (for movies, LLM picks best OpenSubtitles match from top 6).
    2. Run Whisper sub_check; if >30s off and LLM gave a backup, retry backup once.
    """
    state = load_state()
    item = state["items"].get(item_id)
    if not item or not item.get("library_path"):
        return {"ok": False, "error": f"No library_path in state.json for {item_id}"}

    vid = _assert_inside_media_root(Path(item["library_path"]))
    media_type = item.get("media_type")
    vtt_path = vid.with_suffix(".vtt")
    has_internal = encode_subs.has_embedded_subtitles(str(vid))
    if item.get("skip_sub_sync"):
        state["items"][item_id]["stage"] = "SUBS_OK"
        save_state(state)
        return {"ok": True, "status": "skipped_sub_sync_by_flag"}

    backup_idx = None
    raw_filename = Path(item.get("source_path") or item.get("converted_path") or str(vid)).name
    dur_sec = convert_media.get_video_duration(str(vid))
    duration_mins = round(dur_sec / 60, 1) if dur_sec else None

    if media_type == "movie" and not vtt_path.exists() and not has_internal:
        nfo_path = vid.with_suffix(".nfo")
        if not nfo_path.exists():
            return {"ok": False, "needs_manual": True, "reason": "Missing .nfo needed for OpenSubtitles ID"}

        from tools.wrappers_opensubs import download_vtt_for_movie
        scraped, _, backup_idx, pick_reason = download_vtt_for_movie(
            vid, raw_filename=raw_filename, duration_mins=duration_mins
        )
        if not scraped:
            return {"ok": False, "needs_manual": True, "reason": f"OpenSubtitles failed: {pick_reason}"}

    if not vtt_path.exists() and not has_internal:
        state["items"][item_id]["stage"] = "SUBS_OK"
        save_state(state)
        return {"ok": True, "status": "no_subtitles_skipped"}

    audio_lang = _get_audio_language(vid)
    if audio_lang and audio_lang not in ("und", "eng", "en", "english"):
        return {
            "ok": False,
            "needs_manual": True,
            "audio_language": audio_lang,
            "reason": f"Non-English audio ({audio_lang}) detected; cannot auto-sync English subs. Run with --skip-sync if subs look good.",
        }

    result = _run_whisper_sync_once(vid, vtt_path, has_internal)

    # Automatic 1-time fallback to the LLM's backup subtitle if choice #1 failed sync
    if not result.get("ok") and backup_idx is not None and media_type == "movie":
        print(f"  [SUBS] First subtitle failed ({result.get('reason')}); retrying backup [{backup_idx}]...")
        if vtt_path.exists():
            vtt_path.unlink()
        from tools.wrappers_opensubs import download_vtt_for_movie
        scraped_b, _, _, _ = download_vtt_for_movie(
            vid, raw_filename=raw_filename, duration_mins=duration_mins, force_index=backup_idx
        )
        if scraped_b:
            result = _run_whisper_sync_once(vid, vtt_path, has_internal)

    if result.get("ok"):
        state = load_state()
        if item_id in state["items"]:
            state["items"][item_id]["stage"] = "SUBS_OK"
            save_state(state)

    return result

def finalize_video_assets(item_id: str) -> Dict[str, Any]:
    """Run 720p creation, subtitle muxing on main + 720p, and timeline stills."""
    state = load_state()
    item = state["items"].get(item_id)
    if not item or not item.get("library_path"):
        return {"ok": False, "error": f"No library_path in state.json for {item_id}"}

    vid = _assert_inside_media_root(Path(item["library_path"]))
    vram.unload_lm_studio_models()

    class _QuietLogger:
        def log(self, msg, **kw): print(msg)
        def log_file_info(self, info): pass

    qlog = _QuietLogger()
    ok_720 = make_720p.process_video(str(vid), qlog)

    encode_subs.process_video(str(vid), qlog)
    path_720 = make_720p.find_existing_720p(str(vid))
    if path_720:
        encode_subs.process_video(path_720, qlog)

    stills_dir = vid.parent / "stills" / vid.stem
    stills_dir.mkdir(parents=True, exist_ok=True)
    has_stills = any(p.suffix.lower() in (".jpg", ".jpeg", ".png") for p in stills_dir.iterdir())
    if not has_stills:
        subprocess.run(
            ["ffmpeg", "-i", str(vid), "-vf", "fps=1/10,scale=160:90", "-q:v", "4", str(stills_dir / "still_%03d.jpg"), "-y"],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True,
        )

    state = load_state()
    if item_id in state["items"]:
        state["items"][item_id]["stage"] = "DONE"
        save_state(state)

    return {
        "ok": ok_720,
        "main_path": str(vid),
        "path_720p": path_720,
        "stills_dir": str(stills_dir),
    }


def flag_for_manual_review(item_id: str, reason: str) -> Dict[str, Any]:
    state = load_state()
    item = state["items"].get(item_id, {})
    file_path = item.get("library_path") or item.get("converted_path") or item.get("source_path") or ""
    if item_id in state["items"]:
        state["items"][item_id]["stage"] = "NEEDS_MANUAL_SUBS"
        state["items"][item_id]["review_reason"] = reason
        save_state(state)
    notify_manual_review(item_id, reason, file_path)
    return {"ok": True, "flagged": item_id, "reason": reason}