import hashlib
import json
import subprocess
from pathlib import Path

import cv2


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


class EvidenceWriter:
    """
    Writes snapshot, clip and overlay files under the run directory. Each item
    fails independently; a failure is reported as FAILED with an error code,
    never silently dropped.
    """

    def __init__(self, storage_root: Path, output_dir: str, video_path: Path, duration_ms: int,
                 before_ms: int, after_ms: int, ffmpeg: str = "ffmpeg"):
        self.root = storage_root
        self.output_dir = output_dir.strip("/")
        self.video_path = video_path
        self.duration_ms = duration_ms
        self.before_ms = before_ms
        self.after_ms = after_ms
        self.ffmpeg = ffmpeg
        (self.root / self.output_dir / "evidence").mkdir(parents=True, exist_ok=True)

    def _key(self, slug: str, ext: str) -> str:
        return f"{self.output_dir}/evidence/{slug}.{ext}"

    @staticmethod
    def slug(event: dict) -> str:
        raw = f"{event['type']}|{event['track_key']}|{event['episode_start_ms']}"
        return hashlib.sha1(raw.encode()).hexdigest()[:16]

    def write(self, event: dict, track_history: list[tuple[int, tuple]]) -> list[dict]:
        slug = self.slug(event)
        start = max(0, event["first_ms"] - self.before_ms)
        end = min(self.duration_ms, event["last_ms"] + self.after_ms)
        return [
            self._snapshot(slug, event),
            self._clip(slug, start, end),
            self._overlay(slug, event, start, end, track_history),
        ]

    def _media(self, kind: str, key: str | None, state: str, start: int, end: int, error: str | None = None) -> dict:
        item = {"kind": kind, "key": key, "state": state, "start_ms": start, "end_ms": end}
        if state == "READY":
            item["checksum"] = sha256_file(self.root / key)
        else:
            item["key"] = None
            item["error_code"] = error
        return item

    def _snapshot(self, slug: str, event: dict) -> dict:
        ts = event.get("_peak_ts", event["first_ms"])
        key = self._key(slug, "jpg")
        try:
            cap = cv2.VideoCapture(str(self.video_path))
            cap.set(cv2.CAP_PROP_POS_MSEC, ts)
            ok, frame = cap.read()
            cap.release()
            if not ok:
                return self._media("snapshot", None, "FAILED", ts, ts, "frame_unavailable")
            h, w = frame.shape[:2]
            x1, y1, x2, y2 = event.get("_peak_bbox") or event["evidence"]["bbox_xyxy_normalized"]
            cv2.rectangle(frame, (int(x1 * w), int(y1 * h)), (int(x2 * w), int(y2 * h)), (0, 0, 255), 2)
            if not cv2.imwrite(str(self.root / key), frame, [cv2.IMWRITE_JPEG_QUALITY, 85]):
                return self._media("snapshot", None, "FAILED", ts, ts, "write_failed")
            return self._media("snapshot", key, "READY", ts, ts)
        except Exception:
            return self._media("snapshot", None, "FAILED", ts, ts, "snapshot_failed")

    def _clip(self, slug: str, start: int, end: int) -> dict:
        key = self._key(slug, "mp4")
        cmd = [
            self.ffmpeg, "-y", "-v", "error", "-ss", f"{start / 1000:.3f}", "-i", str(self.video_path),
            "-t", f"{max(0.5, (end - start) / 1000):.3f}", "-map", "0:v:0", "-c:v", "libx264", "-preset", "veryfast",
            "-crf", "26", "-pix_fmt", "yuv420p", "-an", "-movflags", "+faststart", str(self.root / key),
        ]
        try:
            result = subprocess.run(cmd, capture_output=True, timeout=300)
        except (OSError, subprocess.TimeoutExpired):
            return self._media("clip", None, "FAILED", start, end, "ffmpeg_failed")
        if result.returncode != 0 or not (self.root / key).exists():
            return self._media("clip", None, "FAILED", start, end, "ffmpeg_failed")
        return self._media("clip", key, "READY", start, end)

    def _overlay(self, slug: str, event: dict, start: int, end: int, history: list[tuple[int, tuple]]) -> dict:
        key = self._key(slug, "json")
        frames = [
            {"t_ms": ts - start, "source_timestamp_ms": ts, "bbox_xyxy_normalized": [round(v, 5) for v in bbox]}
            for ts, bbox in history if start <= ts <= end
        ]
        payload = {"schema_version": 1, "track_key": event["track_key"], "type": event["type"], "clip_start_ms": start, "frames": frames}
        try:
            (self.root / key).write_text(json.dumps(payload, separators=(",", ":")))
        except OSError:
            return self._media("overlay", None, "FAILED", start, end, "write_failed")
        return self._media("overlay", key, "READY", start, end)
