# Media Agent & Video Processing Toolkit

An automated media ingestion pipeline and collection of standalone video preparation utilities for ReelVault. 

This folder can be used in two ways:
1. **Fully Automated Agent (Default):** A local LLM (via LM Studio) acts as an orchestration brain that inspects messy downloads, classifies Movies vs. TV Shows, runs conversions, picks subtitles, and builds adaptive streaming assets.
2. **Manual / Standalone Toolkit (No LLM Required):** You can skip LM Studio entirely and run the individual Python scripts in `tools/legacy/` directly (or via your own cron jobs / bash scripts) to prepare your media library.

---

## Why tinyMediaManager (TMM) is Necessary

ReelVault's web interface is designed to be lightweight and fast: it does not query slow external movie APIs on page load. Instead, `update_library.php` parses local `.nfo` files (standard XML format containing title, rating, genres, and tags) and looks for `{Movie}-poster.jpg` files on disk.

Whether you use the automated agent or process media by hand:
- **For Movies:** Running [tinyMediaManager](https://www.tinymediamanager.org/) (either via the desktop GUI or headless CLI `tinyMediaManager movie -u -n`) is **required** to generate the `.nfo` and poster art.
- **For TV Shows:** Show posters (`foldericon.png`) can be fetched automatically via `tools/legacy/show_posters.py` or generated through TMM.

---

## Using the Standalone Scripts (Without LM Studio)

If you do not want to run an LLM, all the core processing scripts live in `tools/legacy/` and can be run independently in sequence:

### 1. Initial Transcode & Subtitle Extraction
```bash
python3 tools/legacy/convert_media.py
```
- Scans your raw intake folder for `.mkv`, `.avi`, or unoptimized `.mp4` files.
- Converts video to web-streamable H.264 + AAC with the `+faststart` flag (enabling instant streaming without downloading the whole file first).
- Uses NVENC hardware acceleration when available with automatic CPU fallback.
- Extracts internal or external `.srt` English subtitles and converts them to `.vtt`.

### 2. Move to Media Library
Move the converted `.mp4` and `.vtt` files from `downloads/converted/` to their final destination:
- **Movies:** Move to your `videos/` folder (e.g. `/mnt/media/videos/Movie Title (Year).mp4`).
- **TV Shows:** Move to your `shows/` folder (e.g. `/mnt/media/shows/Show Name/Season 01/Episode.mp4`).

### 3. Fetch Metadata & Posters
- **Movies:** Open tinyMediaManager (or run the CLI) to scrape metadata and artwork so that `{Movie}.nfo` and `{Movie}-poster.jpg` exist next to the `.mp4`.
- **TV Shows:** Run the headless DuckDuckGo poster scraper to automatically download missing show and season artwork:
  ```bash
  python3 tools/legacy/show_posters.py
  ```

### 4. Audio Subtitle Sync Check (Whisper GPU)
```bash
python3 tools/legacy/sub_sync.py
```
- Uses OpenAI's Whisper model (`base.en`) on CUDA to transcribe dialogue slices from the audio track.
- Uses dialogue cluster-voting to measure timing offset against the `.vtt` file.
- Automatically adjusts timestamp offsets (`0.5s – 30s`) and saves the corrected `.vtt` file.

### 5. Generate 720p Adaptive Fallback
```bash
python3 tools/legacy/make_720p.py
```
- Creates a lightweight 720p version of each video inside a `720p/` subfolder.
- The ReelVault web player will automatically drop to this version if network speeds dip or buffering occurs.

### 6. Mux Selectable Subtitles
```bash
python3 tools/legacy/encode_subs.py
```
- Performs a lossless stream-copy muxing the matching `.vtt` file directly into the `.mp4` container as an Apple-standard `mov_text` subtitle track.
- Runs on both the primary 1080p file and the 720p fallback.

### 7. Generate Timeline Seek Previews
```bash
python3 tools/legacy/gen_stills.py "/path/to/videos"
```
- Extracts timeline preview images into `stills/{VideoName}/still_%03d.jpg` at 10-second intervals for the video player's hover scrubber and D-Pad seek carousel.

---

## Using the Automated Agent (`agent.py`)

If you want the entire workflow automated, `agent.py` uses a small local LLM through LM Studio to handle file classification, name cleanup, and step-by-step tool execution.

### Key Features
- **6GB VRAM Friendly:** Automatically unloads the LLM from LM Studio VRAM before launching GPU-heavy tasks (Whisper sync, NVENC encodes), then reloads the model when the next decision is needed.
- **Smart Edition Matching:** When fetching missing subtitles from OpenSubtitles, the LLM reads the top 6 download candidate titles and matches them against your file's cut (e.g. *Special Edition*, *Director's Cut*, *Extended*), with an automatic 1-time fallback.
- **Foreign Language Detection:** Skips Whisper sync for non-English audio tracks to avoid incorrect offsets.
- **Safety Net:** If an offset is greater than 30 seconds or an `.nfo` fails to scrape, the agent halts, flags the file in `MANUAL_REVIEW_NEEDED.txt`, and notifies you instead of burning bad subtitles into your media.

### Requirements
- Python 3.10+
- `ffmpeg` and `ffprobe` installed on system `PATH`
- [LM Studio](https://lmstudio.ai/) running locally on port `1234` with **Just-In-Time (JIT) Model Loading** enabled in settings
- Google Chrome (required for the automated OpenSubtitles scraper)
- tinyMediaManager (v4 or v5)

### Installation

1. Install Python dependencies:
   ```bash
   pip install -r requirements.txt
   ```

2. Copy and configure the environment file:
   ```bash
   cp .env.example .env
   ```
   Configure your media folders, LM Studio model ID, and credentials inside `.env`:
   ```env
   BASE_MEDIA_DIR=/mnt/media
   DOWNLOADS_DIR=/mnt/media/downloads
   CONVERTED_DIR=/mnt/media/downloads/converted
   VIDEOS_DIR=/mnt/media/videos
   SHOWS_DIR=/mnt/media/shows

   TMM_BIN=/opt/tinyMediaManager/tinyMediaManager

   LM_STUDIO_BASE_URL=http://localhost:1234
   LM_STUDIO_MODEL=gemma-4-e2b

   OPENSUBTITLES_USER=your_username
   OPENSUBTITLES_PASS=your_password
   ```

### Running the Agent

1. **Dry-Run Mode (Simulation):**
   Preview how the LLM classifies pending downloads and plans tool execution without modifying files:
   ```bash
   python3 agent.py --dry-run --limit 1
   ```

2. **Process Pending Downloads:**
   Process all pending items in your downloads directory:
   ```bash
   python3 agent.py
   ```
   *(Or process a specific number of items using `--limit 3`)*.

3. **Resume Flagged Items After Manual Fixes:**
   If a file was parked in `NEEDS_MANUAL_SUBS` and you manually supplied the `.nfo` or corrected `.vtt`:
   ```bash
   python3 agent.py --retry-manual
   ```

4. **Bypass Subtitle Sync for Foreign Films:**
   If you have a foreign-language film with valid English subtitles that cannot be verified by English Whisper:
   ```bash
   python3 agent.py --skip-sync "Movie Title"
   ```