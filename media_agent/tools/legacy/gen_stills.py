#!/usr/bin/env python3
import os
import sys
import glob
import subprocess

import config

# 1. CONFIGURATION
BASE_DIRECTORY = str(config.VIDEOS_DIR)

# Override base directory if passed as a command-line argument
if len(sys.argv) > 1:
    BASE_DIRECTORY = sys.argv[1]

# Allowed video extensions (case-insensitive)
VIDEO_EXTENSIONS = ('.mp4', '.mkv', '.avi', '.mov', '.wmv', '.flv', '.webm', '.m4v')


def main():
    if not os.path.isdir(BASE_DIRECTORY):
        print(f"Error: Base directory '{BASE_DIRECTORY}' does not exist.", flush=True)
        sys.exit(1)

    print(f"Scanning directory: {BASE_DIRECTORY}", flush=True)
    print("Excluding directories named '720p' or 'stills'.\n", flush=True)

    processed_count = 0
    skipped_count = 0
    failed_count = 0

    # 2. RECURSIVE DIRECTORY ITERATION WITH EXCLUSIONS
    for root, dirs, files in os.walk(BASE_DIRECTORY):
        # In-place modification prevents os.walk from entering these folders
        dirs[:] = [d for d in dirs if d.lower() not in ['720p', 'stills']]

        for file in files:
            ext = os.path.splitext(file)[1].lower()
            if ext not in VIDEO_EXTENSIONS:
                continue

            video_path = os.path.join(root, file)
            video_filename = os.path.splitext(file)[0]
            
            # 1. Check for both 'stills' and 'Stills' to prevent Linux case mismatches
            stills_dir = os.path.join(root, 'stills', video_filename)
            if not os.path.isdir(stills_dir):
                alt_stills_dir = os.path.join(root, 'Stills', video_filename)
                if os.path.isdir(alt_stills_dir):
                    stills_dir = alt_stills_dir

            # 2. Check if stills already exist (avoids glob bracket bug and handles .JPG / .jpeg / .png)
            if os.path.isdir(stills_dir):
                try:
                    has_stills = any(
                        entry.name.lower().endswith(('.jpg', '.jpeg', '.png'))
                        for entry in os.scandir(stills_dir)
                        if entry.is_file()
                    )
                    if has_stills:
                        print(f"Skipped (Already exists): {video_path}", flush=True)
                        skipped_count += 1
                        continue
                except PermissionError:
                    print(f"Permission denied accessing: {stills_dir}", flush=True)

            # Create the stills directory
            try:
                os.makedirs(stills_dir, exist_ok=True)
            except Exception as e:
                print(f"Failed to create directory '{stills_dir}': {e}", flush=True)
                failed_count += 1
                continue

            print(f"Processing: {video_path}", flush=True)
            
            output_pattern = os.path.join(stills_dir, 'still_%03d.jpg')

            ffmpeg_cmd = [
                'ffmpeg',
                '-i', video_path,
                '-vf', 'fps=1/10,scale=160:90',
                '-q:v', '4',
                output_pattern,
                '-y'
            ]

            try:
                subprocess.run(
                    ffmpeg_cmd,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                    text=True,
                    check=True
                )
                print(" -> Success", flush=True)
                processed_count += 1
            except subprocess.CalledProcessError as e:
                print(" -> Failed! ffmpeg error output:", flush=True)
                err_lines = e.stderr.strip().splitlines()
                for line in err_lines[-5:]:
                    print(f"    {line}", flush=True)
                failed_count += 1
            except FileNotFoundError:
                print(" -> Error: 'ffmpeg' was not found on your system PATH.", flush=True)
                failed_count += 1
                sys.exit(1)

    print("\n=== Execution Summary ===", flush=True)
    print(f"Processed: {processed_count}", flush=True)
    print(f"Skipped (already generated): {skipped_count}", flush=True)
    print(f"Failed: {failed_count}", flush=True)


if __name__ == "__main__":
    main()