#!/usr/bin/env python3
import os
import re
import sys
import time
import requests
from io import BytesIO
from typing import Optional, Set
from PIL import Image
from ddgs import DDGS

import config

# ----------------------------------------------------------------------
# CONFIGURATION
# ----------------------------------------------------------------------
SHOWS_DIR = str(config.SHOWS_DIR)
TARGET_MAX_WIDTH = 512

# Folders to completely ignore inside shows
IGNORE_FOLDERS = {"720p", "stills", "showsarchived", "temp", "extras", "magic"}


def has_folder_icon(folder_path: str) -> bool:
    """Checks if foldericon.png (case-insensitive) exists directly inside a folder."""
    try:
        for entry in os.scandir(folder_path):
            if entry.is_file() and entry.name.lower() == "foldericon.png":
                return True
    except Exception:
        pass
    return False


def season_has_parent_poster(sub_name: str, show_files_lower: Set[str]) -> bool:
    """
    Replicates the showGridTwo.php poster match logic:
    Checks if a matching poster file exists in the parent show folder.
    e.g. 'Season 1-poster.jpg', 'season01-poster.jpg', 'season00-poster.jpg'
    """
    clean_sub = sub_name.lower()
    
    # Check direct name matches: "{title}-poster.jpg" or .png
    possible = [
        f"{clean_sub}-poster.jpg",
        f"{clean_sub}-poster.jpeg",
        f"{clean_sub}-poster.png"
    ]

    # Check "Season 1" -> "season01-poster.jpg"
    m_season = re.match(r"^season\s*0*(\d+)$", sub_name, re.IGNORECASE)
    if m_season:
        s_num = int(m_season.group(1))
        possible.extend([
            f"season{s_num:02d}-poster.jpg",
            f"season{s_num:02d}-poster.jpeg",
            f"season{s_num:02d}-poster.png"
        ])

    # Check "Specials" -> "season00-poster.jpg"
    if re.match(r"^specials?$", sub_name, re.IGNORECASE):
        possible.extend([
            "season00-poster.jpg",
            "season00-poster.jpeg",
            "season00-poster.png"
        ])

    for candidate in possible:
        if candidate.lower() in show_files_lower:
            return True
            
    return False


def resize_if_needed(img: Image.Image, max_width: int = TARGET_MAX_WIDTH) -> Image.Image:
    """Proportionally scales down the image if its width exceeds max_width."""
    if img.width > max_width:
        ratio = max_width / float(img.width)
        new_height = int(float(img.height) * ratio)
        resample_filter = getattr(Image.Resampling, "LANCZOS", Image.LANCZOS)
        img = img.resize((max_width, new_height), resample=resample_filter)
        print(f"  📐 Resized down to: {max_width}x{new_height}")
    return img


def search_and_download_poster(query: str) -> Optional[Image.Image]:
    """Searches DuckDuckGo Images and returns the first portrait PIL Image."""
    print(f"  🔍 Searching web for: '{query}'...")
    try:
        with DDGS() as ddgs:
            results = list(ddgs.images(query, max_results=5))

            for r in results:
                image_url = r.get("image")
                if not image_url:
                    continue

                try:
                    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}
                    resp = requests.get(image_url, headers=headers, timeout=8)
                    if resp.status_code == 200:
                        img = Image.open(BytesIO(resp.content))

                        # Ensure image is valid and portrait/vertical (height >= width)
                        if img.width > 200 and img.height > 200 and (img.height >= img.width):
                            return img
                except Exception:
                    continue
    except Exception as e:
        print(f"  ⚠ Search error: {e}")
    return None


def save_poster_image(img: Image.Image, dest_path: str):
    """Resizes, converts, saves as PNG, and sets readable 0644 permissions."""
    img = resize_if_needed(img, TARGET_MAX_WIDTH)
    if img.mode in ("RGBA", "LA"):
        img.save(dest_path, "PNG", optimize=True)
    else:
        img.convert("RGB").save(dest_path, "PNG", optimize=True)
    os.chmod(dest_path, 0o644)
    print(f"  ✓ Saved: {dest_path} ({img.width}x{img.height})")


def process_shows(base_dir: str):
    if not os.path.isdir(base_dir):
        print(f"Error: Directory '{base_dir}' does not exist.")
        return

    print(f"Scanning TV Shows in: {base_dir}")
    print("=" * 70)

    for show_entry in os.scandir(base_dir):
        if not show_entry.is_dir() or show_entry.name.lower() in IGNORE_FOLDERS:
            continue

        show_name = show_entry.name
        show_path = show_entry.path

        # -------------------------------------------------------------
        # 1. SHOW-LEVEL CHECK
        # -------------------------------------------------------------
        if not has_folder_icon(show_path):
            print(f"\n[Missing Show Icon] {show_name}")
            query = f"{show_name} poster"
            show_img = search_and_download_poster(query)
            if show_img:
                dest = os.path.join(show_path, "foldericon.png")
                try:
                    save_poster_image(show_img, dest)
                except Exception as e:
                    print(f"  ✗ Failed to save show icon: {e}")
            else:
                print(f"  ✗ No poster found for show '{show_name}'")
            time.sleep(1.5)

        # Cache existing files in the show directory (lowercase)
        show_files_lower = set()
        sub_directories = []
        try:
            for item in os.scandir(show_path):
                if item.is_file():
                    show_files_lower.add(item.name.lower())
                elif item.is_dir() and item.name.lower() not in IGNORE_FOLDERS:
                    sub_directories.append(item)
        except Exception as e:
            print(f"Error reading {show_path}: {e}")
            continue

        # -------------------------------------------------------------
        # 2. SEASON / SUBFOLDER CHECK
        # -------------------------------------------------------------
        for sub_entry in sub_directories:
            sub_name = sub_entry.name
            sub_path = sub_entry.path

            # Skip if the subfolder already has foldericon.png
            if has_folder_icon(sub_path):
                continue

            # Skip if a matched poster file exists in the parent show folder
            if season_has_parent_poster(sub_name, show_files_lower):
                continue

            print(f"\n[Missing Season Icon] {show_name} -> {sub_name}")
            query = f"{show_name} {sub_name} poster"
            poster_img = search_and_download_poster(query)

            if poster_img:
                dest_file = os.path.join(sub_path, "folderIcon.png")
                try:
                    save_poster_image(poster_img, dest_file)
                except Exception as e:
                    print(f"  ✗ Failed to save season image: {e}")
            else:
                print(f"  ✗ No valid image found for '{query}'")

            time.sleep(1.5)

    print("\n" + "=" * 70)
    print("Scan complete!")


if __name__ == "__main__":
    process_shows(SHOWS_DIR)