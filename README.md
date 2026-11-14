
```markdown
# ReelVault

A self-hosted media streaming web app paired with an autonomous local-AI ingestion pipeline (`media_agent/`). Built to run comfortably on modest hardware (including a single 6GB GPU), with optional support for a secondary storage/download node behind an Apache reverse proxy.

![ReelVault UI](docs/screenshot.png)

---

## Architecture Overview

```text
+-------------------------------------------------------------------+
|                        Master Web Server                          |
|   PHP 8 + SQLite (media.db) + Custom HTML5 Player                 |
|   - PIN Gate, User Profiles, Watch Progress (IPdata/)             |
|   - Adaptive 1080p / 720p switching, Live Sub Sync, Seek Cards    |
+--------------------------+----------------------------------------+
                           |
        +------------------+------------------+
        | (Local Storage)                     | (Optional: Apache Reverse Proxy)
        v                                     v
/mnt/media/{videos,shows}         +---------------------------------+
                                  |   Remote Storage Node (Optional)|
                                  |   remote_node/*.php             |
                                  |   - Serves streams, posters,    |
                                  |     .nfo, .vtt, and stills      |
                                  +---------------------------------+
                                              ^
                                              | (Ingests & prepares media)
                                  +-----------+---------------------+
                                  |   media_agent/ (Python + LLM)   |
                                  |   - LM Studio (gemma-4 / qwen3) |
                                  |   - Dynamic 6GB VRAM swapping   |
                                  |   - FFmpeg/NVENC + Whisper      |
                                  |   - tinyMediaManager CLI        |
                                  +---------------------------------+
```

---

## Features

### Web Streaming App (Root PHP App)
- **Fast Library Indexing:** Indexes `.nfo` metadata (titles, ratings, genres, tags) into SQLite (`media.db`) via `update_library.php`.
- **Browse & Search:** Genre drawer (`GenreGrid.php`), live debounced search (`GridViewSearch.php` + `api_search.php`), TV show/season grid (`showGridTwo.php`), and Recently Watched history (`RecentlyWatched.php`).
- **Custom HTML5 Player (`videoPlayer.php`):**
  - **Auto Quality Switching:** Drops from 1080p to 720p on repeated buffering and upgrades back once buffer stabilizes.
  - **Visual Timeline Scrubbing:** Hover thumbnail previews + 5-frame seek card carousel for keyboard/TV D-Pad navigation.
  - **Live Subtitle Delay Controls:** Shift subtitle timing (`±0.1s` / `±1.0s`) directly in the player.
  - **Cross-Device Resume & Profiles:** Cookie-based viewer profiles (`IPdata/`) without requiring user accounts/passwords, plus optional site-wide `SITE_PIN` lock.
  - **Apple/TV Friendly:** AirPlay button on Safari/WebKit, iOS native fullscreen support, and remote-friendly focus navigation.

### Local AI Ingestion Agent (`media_agent/`)
- **Bounded Tool-Calling Harness:** Uses a small local LLM via LM Studio (`gemma-4-e2b`, `qwen3`, etc.) to classify Movies vs. TV Shows, clean release filenames, and route files through strict pipeline stages.
- **6GB VRAM Friendly:** Automatically unloads the LLM from LM Studio VRAM before running GPU-heavy Whisper or NVENC tasks, then JIT-reloads the model for the next step.
- **Automated Subtitle Pipeline:**
  - Extracts embedded/external `.srt` tracks to `.vtt`.
  - Uses the local LLM to pick the best-matching cut/edition from the top 6 OpenSubtitles results (with automatic 1-time backup retry).
  - Syncs subtitles against audio using Whisper (`base.en`) dialogue cluster-voting (`0.5s – 30s` auto-shift; `> 30s` cut mismatches or foreign-language audio safely flagged for review).
- **Asset Generation:** Creates `720p` mobile fallbacks, muxes selectable `mov_text` subtitles into both 1080p and 720p MP4s, and extracts timeline preview stills.

---

## Folder Structure

```text
.
├── config.php              # Loads .env settings for the Master web UI
├── .env.example            # Template config for Master web UI
├── index.php               # Entry redirect
├── landingPage.php         # PIN gate, profile selector, custom background/intro video
├── GenreGrid.php           # Main hub (Latest, History, All Movies, Genres, TV Shows)
├── GridViewAdaptive.php    # Filtered movie grid (SQLite-backed)
├── GridViewSearch.php      # Live search UI
├── api_search.php          # JSON search endpoint
├── showGridTwo.php         # TV show / season / episode browser
├── RecentlyWatched.php     # Watch history + cached TV show index
├── videoPlayer.php         # Custom HTML5 player
├── update_library.php      # Scans media folders & syncs .nfo metadata into SQLite
├── get_subtitle.php        # Local/remote .vtt subtitle proxy
├── get_stills.php          # Local/remote timeline stills JSON endpoint
├── image.php               # Local/remote poster & image server with ETag caching
├── VideoProgress.php       # Saves playback timestamps to IPdata/
├── generate_poster.php     # On-demand FFmpeg poster generator
├── generate_stills.php     # CLI FFmpeg stills generator
├── media_agent/            # Python LLM automation pipeline (see media_agent/README.md)
└── remote_node/            # Optional PHP API for a secondary storage machine
```

---

## Setup Guide

### 1. Prerequisites
- **Web Server:** PHP 8.0+ (with `pdo_sqlite`, `curl`, `simplexml` extensions) and Apache or Nginx
- **Media Tools:** `ffmpeg` and `ffprobe` on system `PATH`
- **For `media_agent/`:** Python 3.10+, [LM Studio](https://lmstudio.ai/) (with JIT model loading enabled), and [tinyMediaManager](https://www.tinymediamanager.org/) (for movie `.nfo` scraping)

### 2. Configure the Master Web App
1. Clone this repository into your web server root (e.g., `/var/www/html`).
2. Copy the example config and set your paths and PIN:
   ```bash
   cp .env.example .env
   ```
3. Make sure your web server has symlinks or aliases so `videos/` and `shows/` are reachable relative to the web root (matching `WEB_VIDEOS_PATH` and `WEB_SHOWS_PATH` in `.env`), e.g.:
   ```bash
   ln -s /mnt/media/videos /var/www/html/videos
   ln -s /mnt/media/shows /var/www/html/shows
   ```
4. Ensure PHP can write to `IPdata/` and the repo root (for `media.db`).
5. Open `landingPage.php` in your browser, unlock with your `SITE_PIN`, and click **Update Library** (`update_library.php`) to build `media.db`.

### 3. Configure `media_agent/` (Optional Automation)
See [`media_agent/README.md`](media_agent/README.md) for Python dependencies, `.env` setup, and running `python3 agent.py --dry-run`.

### 4. Configure `remote_node/` (Optional Dual-Machine Setup)
If your media lives on a separate storage/VPN node:
1. Copy `remote_node/` to the storage machine's web root (e.g. serving on port `8080`).
2. Inside `remote_node/`, run `cp .env.example .env` and set `MEDIA_BASE_DIR`, `VIDEOS_DIR`, `SHOWS_DIR`, and `REMOTE_PORT`.
3. On the Master Apache VirtualHost, enable `mod_proxy` + `mod_proxy_http` and add:
   ```apache
   ProxyPass        /remote-api/    http://REMOTE_NODE_IP:8080/
   ProxyPassReverse /remote-api/    http://REMOTE_NODE_IP:8080/
   ProxyPass        /remote-videos/ http://REMOTE_NODE_IP:8080/videos/
   ProxyPassReverse /remote-videos/ http://REMOTE_NODE_IP:8080/videos/
   ```
4. In the Master `.env`, set `REMOTE_ENABLED=true`, `REMOTE_HOST=REMOTE_NODE_IP`, `REMOTE_API_PORT=8080`, `REMOTE_VIDEOS_DIR`, and `REMOTE_SHOWS_DIR`.

---

## Utilities
- **`media_agent/check_quality.py`** (or standalone): Quickly probes a folder of downloaded movies with `ffprobe` and generates a `quality_report.csv` flagging low-res, low-bitrate, or broken files.
```