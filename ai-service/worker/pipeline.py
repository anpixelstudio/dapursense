import json
import logging
import threading
from pathlib import Path

import cv2
import httpx

from . import CONTRACT_VERSION
from .callback import CallbackClient, RunAborted
from .config import Settings
from .detectors import make_detector
from .evidence import EvidenceWriter
from .rules import RulesEngine
from .tracker import IoUTracker

log = logging.getLogger("worker.pipeline")


class Cancelled(Exception):
    pass


class SourceUnreadable(Exception):
    pass


def resolve_inside(root: Path, key: str) -> Path:
    path = (root / key).resolve()
    if root.resolve() not in path.parents:
        raise SourceUnreadable("Path video berada di luar storage root")
    return path


class Heartbeat(threading.Thread):
    def __init__(self, client: CallbackClient, attempt: int, interval: float, state: dict):
        super().__init__(daemon=True)
        self.client, self.attempt, self.interval, self.state = client, attempt, interval, state
        self.stop = threading.Event()
        self.cancel_requested = threading.Event()
        self.aborted: RunAborted | None = None

    def run(self) -> None:
        while not self.stop.wait(self.interval):
            try:
                response = self.client.heartbeat(self.attempt, self.state["processed_ms"], self.state["phase"])
                if response.get("cancel_requested"):
                    self.cancel_requested.set()
            except RunAborted as e:
                if e.code in ("stale_attempt", "run_not_active", "http_401"):
                    self.aborted = e
                    return
                log.warning("heartbeat failed: %s", e)


def run_job(job: dict, settings: Settings, transport: httpx.BaseTransport | None = None, cancel_file: Path | None = None) -> str:
    """Processes one job end to end and returns the final status sent to the server."""
    run_id, attempt = job["run_id"], int(job["attempt"])
    spec = job["input"]
    root = Path(job["storage_root"])
    dirs = settings.dirs()
    client = CallbackClient(job["callback_base"], job["worker_token"], dirs["outbox"], run_id, attempt, transport=transport)
    state = {"processed_ms": 0, "phase": "decoding"}
    heartbeat = Heartbeat(client, attempt, settings.heartbeat_interval_s, state)
    heartbeat.start()

    status, error_code, error_message, manifest = "failed", None, None, {}
    try:
        manifest = _analyse(job, spec, root, settings, client, state, heartbeat, cancel_file)
        status = "succeeded"
    except Cancelled:
        status = "cancelled"
    except SourceUnreadable as e:
        error_code, error_message = "source_unreadable", str(e)
    except RunAborted as e:
        log.warning("run %s aborted: %s", run_id, e)
        heartbeat.stop.set()
        client.close()
        return "aborted"
    except ValueError as e:
        error_code, error_message = "model_unsupported" if str(e).startswith("model_unsupported") else "invalid_input", str(e)[:500]
    except Exception as e:  # noqa: BLE001 - reported to the server as a failed run
        log.exception("run %s failed", run_id)
        error_code, error_message = "worker_error", f"{type(e).__name__}: {e}"[:500]
    finally:
        heartbeat.stop.set()

    try:
        state["phase"] = "finalizing"
        client.complete({
            "attempt": attempt, "status": status, "error_code": error_code,
            "error_message": error_message, "manifest": manifest or None,
        })
    except RunAborted as e:
        log.warning("complete for %s rejected: %s", run_id, e)
        status = "aborted"
    finally:
        client.close()
    return status


def _analyse(job: dict, spec: dict, root: Path, settings: Settings, client: CallbackClient,
             state: dict, heartbeat: Heartbeat, cancel_file: Path | None) -> dict:
    video = spec["video"]
    rules = spec["rules"]
    config = rules["config"]
    zones = spec.get("zones", [])
    output_dir = spec["output_dir"].strip("/")
    duration_ms = int(video.get("duration_ms") or 0)

    if not video.get("key"):
        raise SourceUnreadable("Video preview tidak tersedia")
    path = resolve_inside(root, video["key"])
    if not path.exists():
        raise SourceUnreadable("File video tidak ditemukan")

    cap = cv2.VideoCapture(str(path))
    if not cap.isOpened():
        raise SourceUnreadable("Video tidak dapat dibuka oleh decoder")

    src_fps = cap.get(cv2.CAP_PROP_FPS) or float(video.get("fps") or 25)
    sample_every_ms = 1000.0 / float(config.get("sampling_fps", 3))
    detector = make_detector(spec["model"], duration_ms, zones, config["thresholds"]["min_confidence"].get("person", 0.5),
                             settings.models_dir)
    engine = RulesEngine(config, zones)
    tracker = IoUTracker(max_lost_ms=max(int(config["thresholds"]["max_gap_ms"]), int(sample_every_ms * 2)))
    history: dict[str, list] = {}
    evidence = EvidenceWriter(root, output_dir, path, duration_ms, spec.get("evidence", {}).get("clip_before_ms", 5000),
                              spec.get("evidence", {}).get("clip_after_ms", 10000), settings.ffmpeg)
    obs_dir = root / output_dir / "observations"
    obs_dir.mkdir(parents=True, exist_ok=True)

    base = {
        "schema_version": CONTRACT_VERSION, "attempt": int(job["attempt"]),
        "model_sha256": spec["model"]["sha256"], "rules_sha256": rules["checksum"],
    }

    frame_index, sampled, next_sample, last_flush, last_ts = 0, 0, 0.0, 0, 0
    chunks: set[int] = set()
    state["phase"] = "inference"

    def flush(processed_ms: int, phase: str) -> None:
        for ek in engine.newly_closed:
            event = engine.events[ek]
            event["media"] = evidence.write(event, history.get(event["track_key"], []))
        engine.newly_closed.clear()
        data = engine.drain()
        client.send_batch({**base, "progress": {"processed_ms": processed_ms, "phase": phase}, **data})

    while True:
        if heartbeat.aborted:
            raise heartbeat.aborted
        if heartbeat.cancel_requested.is_set() or (cancel_file and cancel_file.exists()):
            raise Cancelled()

        if not cap.grab():
            break
        ts = int(round(frame_index * 1000.0 / src_fps))
        frame_index += 1
        if ts + 0.5 < next_sample:
            continue
        next_sample += sample_every_ms
        ok, frame = cap.retrieve()
        if not ok:
            continue

        sampled += 1
        last_ts = ts
        matched, lost = tracker.update(ts, detector.detect(frame, ts))
        for key in lost:
            engine.lost(key, ts)

        detections = []
        for key, det in matched:
            zone = engine.observe(ts, key, det)
            history.setdefault(key, []).append((ts, det.bbox))
            detections.append({
                "track_key": key, "bbox": [round(v, 5) for v in det.bbox], "score": det.score,
                "zone_id": zone["id"] if zone else None,
                "ppe": {item: o.state for item, o in det.ppe.items()},
            })

        chunk = ts // settings.observation_chunk_ms
        chunks.add(chunk)
        with open(obs_dir / f"{chunk:06d}.jsonl", "a") as f:
            f.write(json.dumps({"source_timestamp_ms": ts, "frame_index": frame_index - 1, "detections": detections}, separators=(",", ":")) + "\n")

        state["processed_ms"] = ts
        if ts - last_flush >= settings.batch_interval_ms:
            flush(ts, "inference")
            last_flush = ts

    cap.release()
    if sampled == 0:
        raise SourceUnreadable("Tidak ada frame yang dapat dibaca dari video")

    tracker.flush()
    engine.finish(last_ts)
    state["phase"] = "evidence"
    flush(max(last_ts, duration_ms), "evidence")

    return {
        "frames_sampled": sampled,
        "sampling_fps": config.get("sampling_fps", 3),
        "duration_ms": duration_ms,
        "observation_chunks": len(chunks),
        "detector": spec["model"]["name"],
        "simulated": bool(getattr(detector, "simulated", False)),
        "tracks": len(engine.tracks),
        "events": len(engine.events),
    }
