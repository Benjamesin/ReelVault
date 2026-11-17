# ReelVault

A lightweight, self-hosted media streaming web app paired with an optional autonomous local-AI ingestion pipeline (`media_agent/`). Built to run comfortably on modest hardware (tested with a single 6GB GTX 1060), with optional support for a secondary storage/download node behind an Apache reverse proxy.

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
                                              | (Optional: Prepares downloads)
                                  +-----------+---------------------+
                                  |   media_agent/ (Python + LLM)   |
                                  |   - LM Studio (gemma-4 / qwen3) |
                                  |   - Dynamic 6GB VRAM swapping   |
                                  |   - FFmpeg/NVENC + Whisper      |
                                  |   - tinyMediaManager CLI        |
                                  +---------------------------------+
```

---

## Two Ways to Use ReelVault

You do **not** have to use the AI ingestion agent to use the streaming platform:

1. **Manual / Bring Your Own Media (Simple):**
   If you already have a folder of movies and TV shows, you can skip `media_agent/` entirely. Just point ReelVault to your existing media directories, click **Update Library** in the web interface, and start watching immediately.
2. **Automated Pipeline (Local AI):**
   If you download raw media into an intake folder, `media_agent/` uses a small local LLM via LM Studio to automatically detect Movies vs. Shows, convert formats, fetch posters/metadata, sync subtitles using Whisper, create 720p mobile versions, and extract seek previews.

---

## Features

### Web Streaming App (Root PHP App)
- **Fast Library Indexing:** Indexes `.nfo` metadata (titles, ratings, genres, tags) into SQLite (`media.db`) via `update_library.php`.
- **Browse & Search:** Collapsible genre drawer (`GenreGrid.php`), live debounced search (`GridViewSearch.php` + `api_search.php`), TV show/season grid (`showGridTwo.php`), and Recently Watched history (`RecentlyWatched.php`).
- **Custom HTML5 Player (`videoPlayer.php`):**
  - **Auto Quality Switching:** Drops from 1080p to 720p on repeated buffering and upgrades back once buffer stabilizes.
  - **Visual Timeline Scrubbing:** Hover thumbnail previews + 5-frame seek card carousel for keyboard/TV D-Pad navigation.
  - **Live Subtitle Delay Controls:** Shift subtitle timing (`±0.1s` / `±1.0s`) directly in the player.
  - **Cross-Device Resume & Profiles:** Cookie-based viewer profiles (`IPdata/`) without requiring user accounts/passwords, plus optional site-wide `SITE_PIN` lock.
  - **Apple & TV Friendly:** AirPlay button on Safari/WebKit, iOS native fullscreen support, and remote-friendly focus navigation.

### Local AI Ingestion Agent (`media_agent/`) — *Optional*
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
├── media_agent/            # Optional Python LLM automation pipeline
└── remote_node/            # Optional PHP API for a secondary storage machine
```

---

## Setup Guide

### 1. Prerequisites
- **Web Server:** PHP 8.0+ (with `pdo_sqlite`, `curl`, and `simplexml` extensions enabled) and Apache or Nginx
- **Media Tools:** `ffmpeg` and `ffprobe` on system `PATH`
- **Optional (for `media_agent/`):** Python 3.10+, [LM Studio](https://lmstudio.ai/) (with JIT model loading enabled), and [tinyMediaManager](https://www.tinymediamanager.org/)

---

### 2. Configure the Master Web App

1. Clone this repository into your web server directory (e.g. `/var/www/html/ReelVault`):
   ```bash
   git clone https://github.com/your-username/ReelVault.git /var/www/html/ReelVault
   cd /var/www/html/ReelVault
   ```
2. Copy the example configuration:
   ```bash
   cp .env.example .env
   ```
   Open `.env` and configure your settings:
   - Set `SITE_PIN` to your desired passcode (or leave blank to disable).
   - Set `LOCAL_VIDEOS_DIR` to your movies folder (e.g. `/mnt/media/videos`).
   - Set `LOCAL_SHOWS_DIR` to your TV shows folder (e.g. `/mnt/media/shows`).

3. Create symlinks inside the web root so Apache can directly stream your media files:
   ```bash
   ln -s /mnt/media/videos /var/www/html/ReelVault/videos
   ln -s /mnt/media/shows /var/www/html/ReelVault/shows
   ```

4. **Set Web Permissions:** Ensure both your personal user account and Apache (`www-data`) have read/write access:
   ```bash
   sudo usermod -aG www-data $USER
   sudo chown -R $USER:www-data /var/www/html/ReelVault
   sudo chmod -R 775 /var/www/html/ReelVault
   sudo find /var/www/html/ReelVault -type d -exec chmod g+s {} +
   ```
   *(Run `newgrp www-data` or log out and back in for the group membership to take effect).*

5. Open `landingPage.php` in your browser, unlock with your `SITE_PIN`, and click **Update Library** (`update_library.php`) to build the SQLite index (`media.db`).

---

### 3. Adding Media (Manual Workflow)

If you are not using `media_agent/`, organize your media folders directly:

- **Movies (`videos/`):**
  Place your `.mp4` files flat inside your videos folder.
  ```text
  /mnt/media/videos/
  ├── Inception (2010).mp4
  ├── Inception (2010).nfo               # Optional: metadata scraped by tinyMediaManager
  ├── Inception (2010)-poster.jpg        # Optional: custom poster art
  ├── Inception (2010).vtt               # Optional: subtitle file
  └── 720p/
      └── Inception (2010).mp4           # Optional: lower-bitrate stream fallback
  ```

- **TV Shows (`shows/`):**
  Group shows by title and season:
  ```text
  /mnt/media/shows/
  └── Breaking Bad/
      ├── foldericon.png                 # Show poster
      ├── Season 01/
      │   ├── foldericon.png             # Season poster
      │   ├── Breaking Bad S01E01.mp4
      │   └── Breaking Bad S01E02.mp4
      └── Season 02/
          └── Breaking Bad S02E01.mp4
  ```

Whenever you add new files, visit `update_library.php` in your browser to sync changes to the database.

---

### 4. Configure `media_agent/` (Optional AI Ingestion Pipeline)

If you want automated processing for downloads:
1. See [`media_agent/README.md`](media_agent/README.md) for Python dependencies and `.env` setup.
2. Preview actions without touching any files:
   ```bash
   cd media_agent
   python3 agent.py --dry-run --limit 1
   ```
3. Run the agent to process pending downloads:
   ```bash
   python3 agent.py
   ```

---

### 5. Configure `remote_node/` (Optional Dual-Machine Setup)

If your media library is stored on a secondary machine (e.g. a download box or VPN node), you can proxy its streams and metadata through the Master web server.

#### A. Setup on the Remote (Slave) Node:
1. Ensure Apache and PHP are installed:
   ```bash
   sudo apt install apache2 php libapache2-mod-php
   ```
2. Copy the contents of `remote_node/` into the remote web root (e.g. `/var/www/html/`).
3. Copy config and set paths:
   ```bash
   cp .env.example .env
   ```
4. Create symlinks so Apache can stream raw video files:
   ```bash
   sudo ln -s /path/to/remote/videos /var/www/html/videos
   sudo ln -s /path/to/remote/shows /var/www/html/shows
   ```
5. *(Optional)* If using a custom port (e.g. `8080`), update `/etc/apache2/ports.conf` and `/etc/apache2/sites-available/000-default.conf`, then run `sudo systemctl restart apache2`.

#### B. Setup on the Master Server:
1. Enable the Apache reverse proxy modules:
   ```bash
   sudo a2enmod proxy proxy_http headers
   ```
2. Edit your virtual host configuration (`sudo nano /etc/apache2/sites-available/000-default.conf`) and add inside `<VirtualHost *:80>`:
   ```apache
   ProxyRequests Off
   ProxyPreserveHost On

   # Route remote API scripts and posters
   ProxyPass        /remote-api/    http://REMOTE_IP:PORT/
   ProxyPassReverse /remote-api/    http://REMOTE_IP:PORT/

   # Route video streams
   ProxyPass        /remote-videos/ http://REMOTE_IP:PORT/videos/
   ProxyPassReverse /remote-videos/ http://REMOTE_IP:PORT/videos/
   ```
3. Test and restart Apache:
   ```bash
   sudo apache2ctl configtest
   sudo systemctl restart apache2
   ```
4. In your Master `.env`, configure the remote section:
   ```env
   REMOTE_ENABLED=true
   REMOTE_HOST=REMOTE_IP
   REMOTE_API_PORT=PORT
   REMOTE_VIDEOS_DIR=/path/to/remote/videos
   REMOTE_SHOWS_DIR=/path/to/remote/shows
   ```