import copy
import hashlib
import json
import subprocess
import uuid
from pathlib import Path

import httpx
import pytest

from worker.config import Settings

DEFAULT_CONFIG = {
    "sampling_fps": 3,
    "requirements": [
        {"zone_type": "prep", "items": ["hair_cover", "apron"]},
        {"zone_type": "stove", "items": ["hair_cover", "apron"]},
    ],
    "thresholds": {
        "min_confidence": {"person": 0.5, "hair_cover": 0.6, "apron": 0.6, "glove": 0.6, "mask": 0.6},
        "min_visibility": 0.5,
        "missing_ratio": 0.8,
        "window_ms": 5000,
        "max_gap_ms": 1500,
        "grace_ms": 2000,
        "recovery_ms": 3000,
        "cooldown_ms": 30000,
        "restricted_dwell_ms": 2000,
        "unassessable_ms": 15000,
    },
    "severity": {"ppe_missing": "medium", "restricted_zone": "high", "unassessable_ppe": "review_needed"},
}

ZONES = [
    {"id": "z-stove", "name": "Kompor", "type": "stove", "revision": 1, "points": [[0.3, 0.5], [0.7, 0.5], [0.7, 1.0], [0.3, 1.0]]},
    {"id": "z-restricted", "name": "Gudang", "type": "restricted", "revision": 1, "points": [[0.8, 0.5], [1.0, 0.5], [1.0, 1.0], [0.8, 1.0]]},
]


@pytest.fixture
def config():
    return copy.deepcopy(DEFAULT_CONFIG)


@pytest.fixture
def settings(tmp_path):
    return Settings(service_token="svc-token", jobs_dir=tmp_path / "jobs", heartbeat_interval_s=0.2, batch_interval_ms=10_000)


@pytest.fixture(scope="session")
def video_file(tmp_path_factory) -> Path:
    path = tmp_path_factory.mktemp("video") / "kitchen.mp4"
    subprocess.run([
        "ffmpeg", "-y", "-v", "error", "-f", "lavfi", "-i", "testsrc=size=320x240:rate=10:duration=60",
        "-c:v", "libx264", "-pix_fmt", "yuv420p", str(path),
    ], check=True)
    return path


class FakeLaravel:
    """Implements the ingestion contract rules the worker relies on."""

    def __init__(self, cancel_after_heartbeats: int | None = None):
        self.batches: list[dict] = []
        self.hashes: dict[int, str] = {}
        self.completed: dict | None = None
        self.heartbeats = 0
        self.cancel_after = cancel_after_heartbeats
        self.fail_next_batches = 0

    def handler(self, request: httpx.Request) -> httpx.Response:
        assert request.headers["authorization"] == "Bearer worker-token-1234567890"
        body = request.content
        data = json.loads(body)
        path = request.url.path

        if path.endswith("/batches"):
            if self.fail_next_batches:
                self.fail_next_batches -= 1
                return httpx.Response(503)
            seq = data["batch_sequence"]
            sha = hashlib.sha256(body).hexdigest()
            if seq in self.hashes:
                if self.hashes[seq] == sha:
                    return httpx.Response(200, json={"ok": True, "replayed": True})
                return httpx.Response(409, json={"error": "payload_conflict"})
            if seq != len(self.hashes) + 1:
                return httpx.Response(409, json={"error": "sequence_gap", "expected_sequence": len(self.hashes) + 1})
            self.hashes[seq] = sha
            self.batches.append(data)
            return httpx.Response(200, json={"ok": True, "last_batch_sequence": seq})

        if path.endswith("/heartbeat"):
            self.heartbeats += 1
            cancel = self.cancel_after is not None and self.heartbeats >= self.cancel_after
            return httpx.Response(200, json={"ok": True, "cancel_requested": cancel})

        if path.endswith("/complete"):
            assert data["final_batch_sequence"] == len(self.hashes)
            self.completed = data
            return httpx.Response(200, json={"ok": True, "status": data["status"].upper()})

        return httpx.Response(404)

    @property
    def transport(self) -> httpx.MockTransport:
        return httpx.MockTransport(self.handler)

    def events(self) -> dict:
        merged = {}
        for batch in self.batches:
            for event in batch["events"]:
                merged[(event["type"], event["track_key"], event["episode_start_ms"])] = event
        return merged


@pytest.fixture
def make_job(tmp_path, video_file, config):
    def factory(runtime: str = "simulation", zones=None, duration_ms: int = 60000, key: str | None = "tenants/t1/videos/v1/preview.mp4"):
        root = tmp_path / "storage"
        if key:
            target = root / key
            target.parent.mkdir(parents=True, exist_ok=True)
            if not target.exists():
                target.write_bytes(video_file.read_bytes())
        run_id = str(uuid.uuid4())
        return {
            "run_id": run_id,
            "tenant_id": "t1",
            "attempt": 1,
            "worker_token": "worker-token-1234567890",
            "callback_base": f"http://laravel.test/internal/runs/{run_id}",
            "storage_root": str(root),
            "input": {
                "contract_version": 1,
                "mode": "simulation" if runtime == "simulation" else "actual",
                "video": {"id": "v1", "key": key, "duration_ms": duration_ms, "width": 320, "height": 240, "fps": 10},
                "zones": ZONES if zones is None else zones,
                "rules": {"revision_id": "r1", "version": 1, "checksum": "c" * 64, "config": config},
                "model": {"id": "m1", "name": "anpixel-simulator" if runtime == "simulation" else "opencv-hog-person",
                          "version": "1", "runtime": runtime, "sha256": "a" * 64},
                "evidence": {"clip_before_ms": 5000, "clip_after_ms": 10000},
                "output_dir": f"tenants/t1/runs/{run_id}",
            },
        }

    return factory
