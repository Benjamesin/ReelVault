import json
import subprocess
from datetime import datetime
from pathlib import Path
from typing import Dict, Any
import requests
import config

# Valid stages in strict pipeline order
STAGES = [
    "DISCOVERED",          # Raw file in /downloads
    "CONVERTED",           # MP4 (and optional .vtt) in /downloads/converted
    "MOVED",               # In /videos or /shows/{Show}/Season XX/
    "METADATA_OK",         # TMM scraped (.nfo exists) or Show Posters fetched
    "SUBS_OK",             # Subs synced (<= 30s offset) or not applicable
    "DONE",                # 720p + sub mux + stills complete
    "NEEDS_MANUAL_SUBS",   # Parked for human intervention
    "ERROR",
]


def load_state() -> Dict[str, Any]:
    if config.STATE_FILE.exists():
        return json.loads(config.STATE_FILE.read_text(encoding="utf-8"))
    return {"items": {}}


def save_state(state: Dict[str, Any]) -> None:
    tmp = config.STATE_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(state, indent=2), encoding="utf-8")
    tmp.replace(config.STATE_FILE)


def notify_manual_review(item_id: str, reason: str, file_path: str) -> None:
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{timestamp}] {item_id} | {reason} | {file_path}\n"

    with open(config.MANUAL_REVIEW_FILE, "a", encoding="utf-8") as f:
        f.write(line)

    # Linux Mint desktop notification (fails silently if headless/SSH)
    try:
        subprocess.run(
            ["notify-send", "Media Agent: Manual Review Needed", f"{item_id}\n{reason}"],
            timeout=3,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    except Exception:
        pass

    # Optional Discord webhook
    if config.DISCORD_WEBHOOK_URL:
        try:
            requests.post(
                config.DISCORD_WEBHOOK_URL,
                json={"content": f"**Media Agent Needs Help**\n**Item:** `{item_id}`\n**Reason:** {reason}\n**File:** `{file_path}`"},
                timeout=5,
            )
        except Exception:
            pass