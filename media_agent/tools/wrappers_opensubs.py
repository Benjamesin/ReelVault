import json
import os
import random
import re
import tempfile
import time
import xml.etree.ElementTree as ET
import zipfile
from pathlib import Path
from typing import Optional, List, Tuple

import requests
import webvtt
import undetected_chromedriver as uc
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.support.ui import WebDriverWait

import config


def _get_movie_id_from_nfo(nfo_path: Path) -> Optional[str]:
    tree = ET.parse(nfo_path)
    movie_id = tree.getroot().find("id")
    return movie_id.text.strip() if (movie_id is not None and movie_id.text) else None


def _login(driver, username: str, password: str) -> None:
    driver.find_element(By.CLASS_NAME, "login-li").click()
    user_form = WebDriverWait(driver, 20).until(EC.presence_of_element_located((By.ID, "user_login")))
    user_form.send_keys(username)
    user_form.submit()
    pw_form = WebDriverWait(driver, 10).until(EC.presence_of_element_located((By.ID, "user_password")))
    pw_form.send_keys(password)
    driver.find_element(By.ID, "page_login_btn").click()


def _ask_llm_to_pick_sub(
    raw_filename: str,
    duration_mins: Optional[float],
    candidates: List[Tuple[int, str]],
) -> Tuple[int, Optional[int], str]:
    """Ask the local LM Studio model to pick the best matching subtitle row (and a backup)."""
    if len(candidates) <= 1:
        return 0, None, "Only 1 candidate available"

    cand_lines = "\n".join(f"[{idx}] {text}" for idx, text in candidates)
    dur_str = f"{duration_mins} minutes" if duration_mins else "unknown"

    prompt = (
        f"You are matching subtitles to a movie file.\n"
        f"Original video filename: {raw_filename}\n"
        f"Video runtime: {dur_str}\n\n"
        f"Top OpenSubtitles candidates (sorted by downloads):\n{cand_lines}\n\n"
        f"Pick the candidate index most likely to match the exact cut/edition/release "
        f"(e.g. Extended, Special Edition/SE, Director's Cut, Theatrical, Unrated, BluRay vs WEB-DL).\n"
        f"If uncertain, prefer the most downloaded standard match as choice and a plausible alternative as backup.\n"
        f'Respond ONLY with raw JSON: {{"choice": 0, "backup": 1, "reason": "short explanation"}}'
    )

    try:
        resp = requests.post(
            f"{config.LM_STUDIO_BASE_URL}/v1/chat/completions",
            json={
                "model": config.LM_STUDIO_MODEL,
                "messages": [{"role": "user", "content": prompt}],
                "temperature": 0.0,
            },
            timeout=60,
        )
        resp.raise_for_status()
        content = resp.json()["choices"][0]["message"]["content"].strip()

        # Extract JSON even if the model wrapped it in ```json ... ```
        match = re.search(r"\{.*\}", content, re.DOTALL)
        if match:
            data = json.loads(match.group(0))
            choice = int(data.get("choice", 0))
            backup = data.get("backup")
            backup = int(backup) if backup is not None else None
            reason = str(data.get("reason", ""))

            valid_indices = {idx for idx, _ in candidates}
            if choice not in valid_indices:
                choice = 0
            if backup not in valid_indices or backup == choice:
                backup = None
            return choice, backup, reason
    except Exception as e:
        print(f"  [OPENSUBS] LLM picker fallback to #0 ({e})")

    fallback_backup = 1 if len(candidates) > 1 else None
    return 0, fallback_backup, "Fallback to top downloaded"


def _search_and_pick(
    driver,
    base_url: str,
    movie_id: str,
    raw_filename: str,
    duration_mins: Optional[float],
    force_index: Optional[int] = None,
) -> Tuple[bool, Optional[int], Optional[int], str]:
    if driver.current_url != base_url:
        driver.get(base_url)
        time.sleep(3)

    form = WebDriverWait(driver, 10).until(EC.presence_of_element_located((By.ID, "form-search-input")))
    try:
        form.clear()
    except Exception:
        pass
    form.send_keys(movie_id)

    btn = WebDriverWait(driver, 10).until(EC.element_to_be_clickable((By.ID, "button-search-submit")))
    time.sleep(random.uniform(0.3, 1.5))
    btn.click()
    time.sleep(random.uniform(1.5, 3.0))

    if driver.find_elements(By.XPATH, "//h2[contains(normalize-space(.), 'No results found')]"):
        return False, None, None, "No results found"

    try:
        lang_btn = WebDriverWait(driver, 8).until(EC.element_to_be_clickable((By.CLASS_NAME, "btn-info.lang_ln")))
        time.sleep(random.uniform(0.2, 1.2))
        lang_btn.click()
    except Exception:
        pass

    time.sleep(2.5)
    for _ in range(2):
        dl_hdr = WebDriverWait(driver, 10).until(
            EC.presence_of_element_located((By.XPATH, "//th[i[@class='icon-download']]"))
        )
        time.sleep(random.uniform(0.3, 1.2))
        dl_hdr.click()

    time.sleep(1.5)
    WebDriverWait(driver, 10).until(
        EC.presence_of_element_located((By.XPATH, "//span[@class='non_vip_area']"))
    )

    spans = driver.find_elements(By.XPATH, "//span[@class='non_vip_area']")[:6]
    if not spans:
        return False, None, None, "No non-VIP download rows found"

    candidates: List[Tuple[int, str]] = []
    for idx, span in enumerate(spans):
        try:
            row = span.find_element(By.XPATH, "./ancestor::tr[1]")
            row_text = " | ".join(line.strip() for line in row.text.splitlines() if line.strip())
        except Exception:
            row_text = f"Candidate {idx}"
        candidates.append((idx, row_text[:250]))

    if force_index is not None and 0 <= force_index < len(spans):
        chosen_idx = force_index
        backup_idx = None
        reason = f"Forced backup candidate #{force_index}"
    else:
        chosen_idx, backup_idx, reason = _ask_llm_to_pick_sub(raw_filename, duration_mins, candidates)

    print(f"  [OPENSUBS] Picked candidate [{chosen_idx}] (backup={backup_idx}): {reason}")
    target_span = spans[chosen_idx]
    try:
        driver.execute_script("arguments[0].scrollIntoView({block: 'center'});", target_span)
        time.sleep(random.uniform(0.3, 0.8))
    except Exception:
        pass

    target_span.click()
    return True, chosen_idx, backup_idx, reason


def _download_page(driver) -> None:
    WebDriverWait(driver, 10).until(EC.element_to_be_clickable((By.CLASS_NAME, "btn.download-trigger"))).click()
    time.sleep(3 + random.uniform(0.2, 1.5))
    WebDriverWait(driver, 10).until(EC.element_to_be_clickable((By.CLASS_NAME, "btn.btn-block.no-opt-link"))).click()
    time.sleep(11)
    WebDriverWait(driver, 10).until(EC.element_to_be_clickable((By.CLASS_NAME, "btn.btn-block.btn-sm"))).click()


def download_vtt_for_movie(
    video_path: Path,
    raw_filename: str = "",
    duration_mins: Optional[float] = None,
    force_index: Optional[int] = None,
) -> Tuple[bool, Optional[int], Optional[int], str]:
    """
    Scrape subtitles for a single movie using its matching .nfo <id>.
    Uses the local LLM to choose from the top 6 downloaded non-VIP rows.
    Returns: (success, chosen_idx, backup_idx, reason)
    """
    username = os.getenv("OPENSUBTITLES_USER", "")
    password = os.getenv("OPENSUBTITLES_PASS", "")
    if not username or not password:
        raise RuntimeError("OPENSUBTITLES_USER / OPENSUBTITLES_PASS missing from .env")

    nfo_path = video_path.with_suffix(".nfo")
    if not nfo_path.exists():
        return False, None, None, "Missing .nfo"

    movie_id = _get_movie_id_from_nfo(nfo_path)
    if not movie_id:
        return False, None, None, "No <id> in .nfo"

    if not raw_filename:
        raw_filename = video_path.name

    out_vtt = video_path.with_suffix(".vtt")
    url = "https://www.opensubtitles.com/en"

    with tempfile.TemporaryDirectory() as dl_dir:
        options = uc.ChromeOptions()
        options.add_experimental_option("prefs", {
            "download.default_directory": dl_dir,
            "download.prompt_for_download": False,
            "directory_upgrade": True,
        })

        driver = uc.Chrome(options=options)
        try:
            driver.get(url)
            time.sleep(5)
            _login(driver, username, password)
            time.sleep(3)

            found, chosen_idx, backup_idx, reason = _search_and_pick(
                driver, url, movie_id, raw_filename, duration_mins, force_index=force_index
            )
            if not found:
                return False, None, None, reason

            time.sleep(2)
            _download_page(driver)

            downloaded: Optional[Path] = None
            for _ in range(20):
                files = [
                    p for p in Path(dl_dir).iterdir()
                    if p.is_file() and not p.name.endswith(".crdownload")
                ]
                if files:
                    downloaded = files[0]
                    break
                time.sleep(1)

            if not downloaded:
                return False, chosen_idx, backup_idx, "Download timed out"

            srt_file: Optional[Path] = None
            if downloaded.suffix.lower() == ".zip":
                with zipfile.ZipFile(downloaded, "r") as zf:
                    zf.extractall(dl_dir)
                srts = list(Path(dl_dir).rglob("*.srt"))
                srt_file = srts[0] if srts else None
            elif downloaded.suffix.lower() == ".srt":
                srt_file = downloaded

            if not srt_file:
                return False, chosen_idx, backup_idx, "No .srt found in downloaded file"

            webvtt.from_srt(str(srt_file)).save(str(out_vtt))
            ok = out_vtt.exists() and out_vtt.stat().st_size > 0
            return ok, chosen_idx, backup_idx, reason
        finally:
            driver.quit()