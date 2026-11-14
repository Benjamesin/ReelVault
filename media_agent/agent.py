import argparse
import json
import requests
import config
from tools import wrappers

TOOLS = [
    {
        "type": "function",
        "function": {
            "name": "convert_downloaded_media",
            "description": "Convert a raw download in /downloads to MP4 in /downloads/converted and extract subtitles to .vtt.",
            "parameters": {
                "type": "object",
                "properties": {
                    "item_id": {"type": "string"},
                },
                "required": ["item_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "move_media_to_library",
            "description": "Move converted MP4 (+ .vtt) to /videos (movie) or /shows/{Show}/Season XX/ (show).",
            "parameters": {
                "type": "object",
                "properties": {
                    "item_id": {"type": "string"},
                    "media_type": {"type": "string", "enum": ["movie", "show"]},
                    "clean_title": {"type": "string", "description": "For movies: 'Title (YYYY)' if year is in the filename (e.g. 'Beauty and the Beast (1991)'). For shows: 'Show Name S01E02'."},
                    "show_name": {"type": "string", "description": "Required if media_type is show"},
                    "season_number": {"type": "integer", "description": "Required if media_type is show"},
                },
                "required": ["item_id", "media_type", "clean_title"],
            },
        },
    },
{
        "type": "function",
        "function": {
            "name": "fetch_metadata",
            "description": "Movie: run tinyMediaManager to create .nfo + poster. Show: fetch show/season posters.",
            "parameters": {
                "type": "object",
                "properties": {"item_id": {"type": "string"}},
                "required": ["item_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "fetch_and_sync_subtitles",
            "description": "Fetch missing movie subs from OpenSubtitles and verify sync with Whisper. Flags >30s offset.",
            "parameters": {
                "type": "object",
                "properties": {"item_id": {"type": "string"}},
                "required": ["item_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "finalize_video_assets",
            "description": "Create 720p version, mux .vtt into main & 720p MP4s, and generate timeline stills.",
            "parameters": {
                "type": "object",
                "properties": {"item_id": {"type": "string"}},
                "required": ["item_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "flag_for_manual_review",
            "description": "Stop processing this item and alert the user if metadata fails or subtitle offset > 30s.",
            "parameters": {
                "type": "object",
                "properties": {
                    "item_id": {"type": "string"},
                    "reason": {"type": "string"},
                },
                "required": ["item_id", "reason"],
            },
        },
    },
]

DISPATCH = {
    "convert_downloaded_media": wrappers.convert_downloaded_media,
    "move_media_to_library": wrappers.move_media_to_library,
    "fetch_metadata": wrappers.fetch_metadata,
    "fetch_and_sync_subtitles": wrappers.fetch_and_sync_subtitles,
    "finalize_video_assets": wrappers.finalize_video_assets,
    "flag_for_manual_review": wrappers.flag_for_manual_review,
}

SYSTEM_PROMPT = """You are a media-server automation agent. Process ONE media item at a time by calling tools in strict order:
1. If stage == DISCOVERED: call convert_downloaded_media.
2. If stage == CONVERTED: decide if it is a 'movie' or 'show' from its path/filename, clean the title (for movies, include '(YYYY)' if the year appears in item_id/source_path so tinyMediaManager matches remakes), and call move_media_to_library.
3. If stage == MOVED: call fetch_metadata. (If TMM fails to create .nfo, call flag_for_manual_review.)
4. If stage == METADATA_OK: call fetch_and_sync_subtitles. (If needs_manual == true, call flag_for_manual_review and STOP—do NOT call finalize_video_assets!)
5. If stage == SUBS_OK: call finalize_video_assets.
Reply with a short summary once the item reaches DONE or NEEDS_MANUAL_SUBS."""


def call_lm_studio(messages):
    resp = requests.post(
        f"{config.LM_STUDIO_BASE_URL}/v1/chat/completions",
        json={
            "model": config.LM_STUDIO_MODEL,
            "messages": messages,
            "tools": TOOLS,
            "temperature": 0.1,
        },
        timeout=120,
    )
    resp.raise_for_status()
    return resp.json()["choices"][0]["message"]


STAGE_ON_TOOL_OK = {
    "convert_downloaded_media": "CONVERTED",
    "move_media_to_library": "MOVED",
    "fetch_metadata": "METADATA_OK",
    "fetch_and_sync_subtitles": "SUBS_OK",
    "finalize_video_assets": "DONE",
    "flag_for_manual_review": "NEEDS_MANUAL_SUBS",
}


def process_one_item(item: dict, dry_run: bool = False):
    current_stage = item.get("stage", "DISCOVERED")
    messages = [
        {"role": "system", "content": SYSTEM_PROMPT},
        {"role": "user", "content": f"Process this item:\n{json.dumps(item, indent=2)}"},
    ]

    for step in range(10):
        msg = call_lm_studio(messages)
        messages.append(msg)

        tool_calls = msg.get("tool_calls") or []
        if not tool_calls:
            if current_stage in ("DONE", "NEEDS_MANUAL_SUBS"):
                print(f"[AGENT DONE] {msg.get('content', '').strip()}")
                break
            print(f"  [NUDGE] Model stopped at stage={current_stage}; prompting next step...")
            messages.append({
                "role": "user",
                "content": f"Not done yet. Current stage is {current_stage}. Call the next required tool now.",
            })
            continue

        for tc in tool_calls:
            fn_name = tc["function"]["name"]
            fn_args = json.loads(tc["function"]["arguments"])
            print(f"  -> Step {step+1}: {fn_name}({fn_args})")

            if dry_run:
                if fn_name in STAGE_ON_TOOL_OK:
                    current_stage = STAGE_ON_TOOL_OK[fn_name]
                result = {
                    "dry_run": True,
                    "simulated": fn_name,
                    "ok": True,
                    "new_stage": current_stage,
                }
                messages.append({"role": "tool", "tool_call_id": tc["id"], "content": json.dumps(result)})
                if current_stage in ("DONE", "NEEDS_MANUAL_SUBS"):
                    print(f"[AGENT DONE] Reached {current_stage} (dry-run)")
                    return
                continue

            fn = DISPATCH.get(fn_name)
            result = fn(**fn_args) if fn else {"ok": False, "error": f"Unknown tool: {fn_name}"}
            if result.get("ok") and fn_name in STAGE_ON_TOOL_OK:
                current_stage = STAGE_ON_TOOL_OK[fn_name]
                result["new_stage"] = current_stage
            print(f"     Result: {result}")
            messages.append({"role": "tool", "tool_call_id": tc["id"], "content": json.dumps(result)})

            if current_stage in ("DONE", "NEEDS_MANUAL_SUBS"):
                return


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true", help="Let the LLM plan tool calls without touching files")
    parser.add_argument("--limit", type=int, default=0, help="Max items to process (0 = all pending)")
    parser.add_argument("--retry-manual", action="store_true", help="Resume items flagged NEEDS_MANUAL_SUBS after you fix them")
    parser.add_argument("--skip-sync", type=str, default="", help="Skip Whisper sync for an item matching this name (e.g. --skip-sync Godzilla)")
    args = parser.parse_args()

    if args.skip_sync:
        from state import load_state, save_state
        state = load_state()
        needle = args.skip_sync.lower()
        pending = []
        for item_id, info in state["items"].items():
            if needle in item_id.lower() and info.get("library_path"):
                info["skip_sub_sync"] = True
                info["stage"] = "METADATA_OK"
                info.pop("review_reason", None)
                pending.append({"item_id": item_id, **info})
        save_state(state)
    elif args.retry_manual:
        from pathlib import Path
        from state import load_state, save_state
        state = load_state()
        pending = []
        for item_id, info in state["items"].items():
            if info.get("stage") == "NEEDS_MANUAL_SUBS" and info.get("library_path"):
                lib = Path(info["library_path"])
                if info.get("media_type") == "movie" and not lib.with_suffix(".nfo").exists():
                    info["stage"] = "MOVED"
                else:
                    info["stage"] = "METADATA_OK"
                info.pop("review_reason", None)
                pending.append({"item_id": item_id, **info})
        save_state(state)
    else:
        pending = wrappers.inspect_pending_downloads()["items"]

    if not pending:
        print("No pending items found.")
        return

    to_process = pending if args.limit <= 0 else pending[: args.limit]
    for item in to_process:
        print(f"\n=== Processing: {item['item_id']} (stage={item['stage']}) ===")
        process_one_item(item, dry_run=args.dry_run)

if __name__ == "__main__":
    main()