import os
import re
import sys
from typing import Optional


class TitleExtractor:
    """Enhanced movie and TV show title extractor from filenames."""

    def __init__(self):
        # Common small words that should remain lowercase in titles (except at start)
        self.small_words = {
            "a", "an", "the", "of", "and", "in", "on", "for", "to", "from",
            "vs", "versus", "vol", "part", "at", "by", "with", "but", "or",
            "nor", "as", "into", "onto", "per", "via"
        }

        # Noise patterns to identify and remove
        self.noise_patterns = [
            re.compile(r'^(19|20)\d{2}$'),
            re.compile(r'^\d{3,4}p$', re.I),
            re.compile(r'^(4k|8k|uhd|fhd|hd)$', re.I),
            re.compile(r'^(2160p|1440p|1080p|720p|576p|480p|360p)$', re.I),
            re.compile(r'^[0-9]{1,2}x[0-9]{1,2}$', re.I),
            re.compile(r'^s\d{1,2}e\d{1,2}(-e?\d{1,2})?$', re.I),
            re.compile(r'^(episode|ep|e)\d+$', re.I),
            re.compile(r'^season\d+$', re.I),
            re.compile(r'^(x264|h264|x265|h265|hevc|avc|xvid|divx|mpeg|av1)$', re.I),
            re.compile(r'^(bluray|blu-ray|webrip|web-dl|web|hdrip|hdtv|dvdrip|bdrip|brrip|cam|ts|telesync|r5|dvdscr|atvp)$', re.I),
            re.compile(r'^(aac|ac3|dts|mp3|flac|dd5\.1|dd\+?|atmos|truehd)$', re.I),
            re.compile(r'^(hdr|hdr10|dolbyvision|dv|sdr)$', re.I),
            re.compile(r'^\[.*\]$'),
            re.compile(r'^\(.*\)$'),
            re.compile(r'^{.*}$'),
            re.compile(r'^(www|http|https)$', re.I),
            re.compile(r'^(repack|proper|rerip|uncut|extended|directors?\.cut|theatrical|imax|3d|unrated|remastered)$', re.I),
            re.compile(r'^(multi|dual|english|eng|spanish|french|german|italian|japanese|chinese|korean|russian|hindi)$', re.I),
            re.compile(r'^(subs?|dubbed?|subbed)$', re.I),
            re.compile(r'^(complete|limited|festival|internal|retail|screener|sample)$', re.I),
            re.compile(r'^(galaxytv|rarbg|yts|yify|etrg|ettv|ion10)$', re.I),
        ]

    def extract_title(self, filename: str) -> str:
        """
        Extract a clean movie or TV show title from a filename.
        For TV shows, includes season and episode information.

        Args:
            filename: The filename to parse

        Returns:
            Cleaned and formatted title string with episode info if applicable
        """
        # Remove file extension
        base = os.path.splitext(filename)[0]

        # Normalize various separators to spaces
        base = re.sub(r'[\._]+', ' ', base)
        base = re.sub(r'--+', ' ', base)
        base = re.sub(r'[-]+', ' ', base)
        base = re.sub(r'\+', ' ', base)

        # Handle camelCase and PascalCase
        base = re.sub(r'(?<=[a-z0-9])(?=[A-Z])', ' ', base)

        # Normalize whitespace
        base = re.sub(r'\s+', ' ', base).strip()

        # Normalize SxxExx pattern AFTER separator normalization
        base = re.sub(r'[sS](\d{1,2})\s*[eE](\d{1,2})', r'S\1E\2', base)

        # Check if this is a TV show episode (has season/episode info)
        episode_info = self._extract_episode_info(base)

        if episode_info:
            episode_title = self._extract_episode_title(base, episode_info)
            if episode_title:
                final_title = f"{episode_info['formatted']} {episode_title}"
                return self._format_title(final_title)

            title = self._extract_show_title(base, episode_info)
            final_title = f"{title} {episode_info['formatted']}"
            return final_title

        # Not a TV show — extract movie title
        title = self._extract_before_delimiter(base)

        if not title:
            title = self._extract_by_tokens(base)

        if not title:
            title = base

        title = self._format_title(title)
        return title

    def _extract_episode_info(self, text: str) -> Optional[dict]:
        """Extract season and episode information if present."""
        match = re.search(r's(\d{1,2})e(\d{1,2})', text, re.I)
        if match:
            season = int(match.group(1))
            episode = int(match.group(2))
            return {
                'season': season,
                'episode': episode,
                'formatted': f"S{season:02d}E{episode:02d}",
                'position': match.start(),
                'end_position': match.end(),
                'matched_text': match.group(0)
            }

        match = re.search(r'(\d{1,2})x(\d{1,2})', text, re.I)
        if match:
            season = int(match.group(1))
            episode = int(match.group(2))
            return {
                'season': season,
                'episode': episode,
                'formatted': f"S{season:02d}E{episode:02d}",
                'position': match.start(),
                'end_position': match.end(),
                'matched_text': match.group(0)
            }

        match = re.search(r'season\s*(\d{1,2})\s*episode\s*(\d{1,2})', text, re.I)
        if match:
            season = int(match.group(1))
            episode = int(match.group(2))
            return {
                'season': season,
                'episode': episode,
                'formatted': f"S{season:02d}E{episode:02d}",
                'position': match.start(),
                'end_position': match.end(),
                'matched_text': match.group(0)
            }

        return None

    def _extract_show_title(self, text: str, episode_info: dict) -> str:
        """Extract show title before the episode information."""
        title_part = text[:episode_info['position']].strip()
        title_part = re.sub(r'\s+(19|20)\d{2}$', '', title_part)
        title = self._format_title(title_part)

        if not title or len(title) < 2:
            title = self._extract_by_tokens(text)
            if title:
                title = self._format_title(title)

        return title if title else "Unknown Show"

    def _extract_episode_title(self, text: str, episode_info: dict) -> Optional[str]:
        """Try to extract an episode title that follows the episode marker."""
        after = text[episode_info['end_position']:].strip()
        if not after:
            return None

        tokens = after.split()
        collected = []
        for tok in tokens:
            if not tok:
                continue
            if self._is_noise(tok):
                break
            if re.match(r'^(19|20)\d{2}$', tok) or re.match(r'^\d{3,4}p$', tok, re.I):
                break
            if tok.startswith('[') or tok.startswith('(') or tok.endswith(']') or tok.endswith(')'):
                break
            collected.append(tok)

        if not collected:
            return None

        ep_title = ' '.join(collected).strip(' -_.')
        return ep_title if len(ep_title) > 1 else None

    def _extract_before_delimiter(self, text: str) -> Optional[str]:
        """Try to extract title by finding natural stop points."""
        match = re.search(r'\b(19|20)\d{2}\b', text)
        if match:
            candidate = text[:match.start()].strip()
            if candidate and len(candidate) > 2:
                return candidate

        match = re.search(r'\b\d{3,4}p\b', text, re.I)
        if match:
            candidate = text[:match.start()].strip()
            if candidate and len(candidate) > 2:
                return candidate

        return None

    def _extract_by_tokens(self, text: str) -> Optional[str]:
        """Extract title by analyzing individual tokens."""
        tokens = text.split()
        title_parts = []

        for tok in tokens:
            if not tok:
                continue
            if self._is_noise(tok):
                break
            if self._is_title_word(tok):
                title_parts.append(tok)
            else:
                if title_parts:
                    break
                title_parts.append(tok)

        return " ".join(title_parts).strip() if title_parts else None

    def _is_noise(self, token: str) -> bool:
        """Check if a token is likely noise/metadata."""
        if not token:
            return True

        if any(pattern.match(token) for pattern in self.noise_patterns):
            return True

        if '.' in token and any(token.lower().endswith(ext) for ext in ['.com', '.net', '.org', '.info', '.tv', '.to', '.me']):
            return True

        if token.lower().startswith('www'):
            return True

        if token.isdigit() and len(token) <= 4:
            return True

        if token.islower() and len(token) > 4 and token not in self.small_words:
            return True

        return False

    def _is_title_word(self, token: str) -> bool:
        """Check if a token looks like part of a title."""
        if not token:
            return False

        if token[0].isupper():
            return True

        if token.lower() in self.small_words:
            return True

        if token.isupper() and len(token) <= 5:
            return True

        return False

    def _format_title(self, title: str) -> str:
        """Apply final formatting to the extracted title."""
        title = re.sub(r'[\[\](){}<>]', ' ', title)
        title = re.sub(r'\s+', ' ', title).strip()

        if not title:
            return ""

        words = title.split()
        formatted_words = []

        for i, word in enumerate(words):
            if i == 0 or i == len(words) - 1:
                formatted_words.append(self._capitalize_word(word))
            elif word.lower() in self.small_words:
                formatted_words.append(word.lower())
            else:
                formatted_words.append(self._capitalize_word(word))

        return " ".join(formatted_words)

    def _capitalize_word(self, word: str) -> str:
        """Capitalize a word while preserving intentional caps (like McDonalds)."""
        if not word:
            return word

        if word.isupper() and len(word) <= 5:
            return word

        if any(c.isupper() for c in word[1:]):
            return word

        return word.capitalize()


# ── CLI entry point ──────────────────────────────────────────────────────────
# Usage:  python title_extractor.py "Some.Movie.2021.1080p.BluRay.mkv"
#         python title_extractor.py "Show.S02E04.Episode.Title.720p.mkv"
#
# Prints only the extracted title to stdout so the caller can capture it easily.

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python title_extractor.py <filename>", file=sys.stderr)
        sys.exit(1)

    input_filename = sys.argv[1]
    extractor = TitleExtractor()
    title = extractor.extract_title(input_filename)
    print(title)