# Media Agent

An automated media-ingestion agent powered by a local LLM (via LM Studio), FFmpeg/NVENC, Whisper, and tinyMediaManager.

Instead of letting a small local model run raw shell commands, this harness wraps media scripts into **strict, stateful tools**—letting the LLM handle messy filename classification, Movie vs. TV Show routing, and subtitle candidate selection while Python enforces file safety and stage order.

## Key Features
- **Runs on a 6GB GPU (GTX 1060):** Automatically unloads the LLM from LM Studio VRAM before running Whisper or NVENC `ffmpeg` jobs, then JIT-reloads the LLM for the next decision.
- **Format & Subtitle Extraction:** Converts `.mkv`/`.mp4`/`.avi` to streaming-friendly H.264 MP4 (`faststart`) and extracts embedded/external `.srt` tracks to `.vtt`.
- **Metadata Automation:** Uses `tinyMediaManager` CLI for movies (`.nfo` + artwork) and DuckDuckGo poster scraping for TV shows/seasons.
- **Smart Subtitle Matching & Sync:**
  - Uses the local LLM to pick the best-matching cut/edition from the top 6 OpenSubtitles results (with automatic 1-time fallback).
  - Runs Whisper (`base.en`) dialogue cluster-voting to auto-shift minor desyncs (`0.5s – 30s`).
  - Automatically flags cut mismatches (`> 30s` offset) or foreign-language audio for quick manual review without ruining the video.
- **Adaptive Assets:** Generates `720p` mobile versions, muxes selectable `mov_text` subtitles into both 1080p and 720p files, and generates timeline preview stills.

## Requirements
- Python 3.10+
- `ffmpeg` and `ffprobe` on `PATH`
- [LM Studio](https://lmstudio.ai/) with Local Server running (`localhost:1234`) and **JIT Model Loading enabled**
- [tinyMediaManager](https://www.tinymediamanager.org/) (v4/v5 CLI)
- Google Chrome (for OpenSubtitles scraper)

## Quick Start

1. Install dependencies:
   ```bash
   pip install -r requirements.txt