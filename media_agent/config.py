import os
from pathlib import Path

_env_file = Path(__file__).parent / ".env"
if _env_file.exists():
    for _line in _env_file.read_text(encoding="utf-8").splitlines():
        _line = _line.strip()
        if _line and not _line.startswith("#") and "=" in _line:
            _k, _v = _line.split("=", 1)
            os.environ.setdefault(_k.strip(), _v.strip().strip('"').strip("'"))

# Media directories (can share BASE_MEDIA_DIR or be set independently)
BASE_MEDIA_DIR = Path(os.getenv("BASE_MEDIA_DIR", "."))
DOWNLOADS_DIR = Path(os.getenv("DOWNLOADS_DIR", str(BASE_MEDIA_DIR / "downloads")))
CONVERTED_DIR = Path(os.getenv("CONVERTED_DIR", str(DOWNLOADS_DIR / "converted")))
VIDEOS_DIR = Path(os.getenv("VIDEOS_DIR", str(BASE_MEDIA_DIR / "videos")))
SHOWS_DIR = Path(os.getenv("SHOWS_DIR", str(BASE_MEDIA_DIR / "shows")))
LOG_DIR = Path(os.getenv("LOG_DIR", "./logs"))

# External tools
TMM_BIN = Path(os.getenv("TMM_BIN", "/opt/tinyMediaManager/tinyMediaManager"))

# LM Studio settings
LM_STUDIO_BASE_URL = os.getenv("LM_STUDIO_BASE_URL", "http://localhost:1234")
LM_STUDIO_MODEL = os.getenv("LM_STUDIO_MODEL", "gemma-4-e2b")

# Subtitle thresholds
DESYNC_THRESHOLD_SEC = float(os.getenv("DESYNC_THRESHOLD_SEC", "0.5"))
MAX_AUTO_OFFSET_SEC = float(os.getenv("MAX_AUTO_OFFSET_SEC", "30.0"))

# Agent state & alerts
STATE_FILE = Path(__file__).parent / "state.json"
MANUAL_REVIEW_FILE = Path(__file__).parent / "MANUAL_REVIEW_NEEDED.txt"
DISCORD_WEBHOOK_URL = os.getenv("DISCORD_WEBHOOK_URL", "")