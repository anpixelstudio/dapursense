import json
from pathlib import Path

import httpx
import pytest

from worker.callback import CallbackClient, RunAborted
from worker.pipeline import run_job

from .conftest import FakeLaravel


def test_simulation_run_produces_contract_valid_batches_and_evidence(make_job, settings):
    job = make_job("simulation")
    server = FakeLaravel()

    assert run_job(job, settings, transport=server.transport) == "succeeded"

    assert server.completed["status"] == "succeeded"
    assert server.completed["manifest"]["simulated"] is True
    assert server.completed["manifest"]["frames_sampled"] >= 170
    assert [b["batch_sequence"] for b in server.batches] == list(range(1, len(server.batches) + 1))

    for batch in server.batches:
        assert batch["schema_version"] == 1
        assert batch["model_sha256"] == "a" * 64 and batch["rules_sha256"] == "c" * 64
        for key in ("tracks", "activity_segments", "ppe_assessments", "events"):
            assert isinstance(batch[key], list)

    events = server.events()
    types = {e["type"] for e in events.values()}
    assert {"ppe_missing", "restricted_zone", "unassessable_ppe"} <= types
    assert all(e["lifecycle"] != "active" for e in events.values())

    root = Path(job["storage_root"])
    for event in events.values():
        media = {m["kind"]: m for m in event["media"]}
        assert set(media) == {"snapshot", "clip", "overlay"}
        for item in media.values():
            assert item["state"] == "READY", item
            assert item["key"].startswith(job["input"]["output_dir"] + "/evidence/")
            assert (root / item["key"]).stat().st_size > 0
            assert len(item["checksum"]) == 64


def test_observations_are_written_as_time_indexed_chunks(make_job, settings):
    job = make_job("simulation")
    run_job(job, settings, transport=FakeLaravel().transport)

    obs = Path(job["storage_root"]) / job["input"]["output_dir"] / "observations"
    chunks = sorted(obs.glob("*.jsonl"))
    assert chunks[0].name == "000000.jsonl" and len(chunks) == 6
    first = json.loads(chunks[1].read_text().splitlines()[0])
    assert 10000 <= first["source_timestamp_ms"] < 20000
    assert first["detections"][0]["track_key"]


def test_hog_runtime_reports_ppe_as_unknown(make_job, settings):
    job = make_job("opencv")
    server = FakeLaravel()

    assert run_job(job, settings, transport=server.transport) == "succeeded"
    assert server.completed["manifest"]["simulated"] is False
    states = {a["state"] for b in server.batches for a in b["ppe_assessments"]}
    assert states <= {"unknown"}
    assert not [e for e in server.events().values() if e["type"] == "ppe_missing"]


YOLOX = Path(__file__).resolve().parent.parent / "models" / "yolox_nano.onnx"


def yolox_job(make_job, sha256: str) -> dict:
    job = make_job("onnx")
    job["input"]["model"].update(name="yolox-nano-person", artifact_key="yolox_nano.onnx", sha256=sha256)
    return job


@pytest.mark.skipif(not YOLOX.exists(), reason="models/yolox_nano.onnx belum diunduh")
def test_yolox_runtime_runs_with_verified_artifact(make_job, settings):
    import hashlib

    job = yolox_job(make_job, hashlib.sha256(YOLOX.read_bytes()).hexdigest())
    server = FakeLaravel()

    assert run_job(job, settings, transport=server.transport) == "succeeded"
    assert server.completed["manifest"]["simulated"] is False
    assert server.completed["manifest"]["detector"] == "yolox-nano-person"
    assert {a["state"] for b in server.batches for a in b["ppe_assessments"]} <= {"unknown"}


WHOLEBODY = Path(__file__).resolve().parent.parent / "models" / "yolox_n_wholebody12_640.onnx"


@pytest.mark.skipif(not WHOLEBODY.exists(), reason="models/yolox_n_wholebody12_640.onnx belum diunduh")
def test_wholebody_runtime_runs_with_verified_artifact(make_job, settings):
    import hashlib

    job = make_job("onnx-wholebody")
    job["input"]["model"].update(
        name="yolox-wholebody12-n",
        artifact_key=WHOLEBODY.name,
        sha256=hashlib.sha256(WHOLEBODY.read_bytes()).hexdigest(),
    )
    server = FakeLaravel()

    assert run_job(job, settings, transport=server.transport) == "succeeded"
    assert server.completed["manifest"]["simulated"] is False
    assert server.completed["manifest"]["detector"] == "yolox-wholebody12-n"


def test_onnx_model_with_wrong_checksum_is_refused(make_job, settings):
    job = yolox_job(make_job, "0" * 64)
    server = FakeLaravel()

    assert run_job(job, settings, transport=server.transport) == "failed"
    assert server.completed["error_code"] == "model_unsupported"


def test_missing_video_fails_as_source_unreadable(make_job, settings):
    job = make_job("simulation", key="tenants/t1/videos/v1/missing.mp4")
    Path(job["storage_root"], job["input"]["video"]["key"]).unlink()
    server = FakeLaravel()

    assert run_job(job, settings, transport=server.transport) == "failed"
    assert server.completed["error_code"] == "source_unreadable"


def test_corrupt_video_fails_as_source_unreadable(make_job, settings):
    job = make_job("simulation")
    Path(job["storage_root"], job["input"]["video"]["key"]).write_bytes(b"not a video" * 100)
    server = FakeLaravel()

    assert run_job(job, settings, transport=server.transport) == "failed"
    assert server.completed["error_code"] == "source_unreadable"


def test_path_traversal_in_video_key_is_refused(make_job, settings):
    job = make_job("simulation")
    job["input"]["video"]["key"] = "../../etc/passwd"
    server = FakeLaravel()

    assert run_job(job, settings, transport=server.transport) == "failed"
    assert server.completed["error_code"] == "source_unreadable"


def test_unsupported_runtime_fails_cleanly(make_job, settings):
    job = make_job("simulation")
    job["input"]["model"]["runtime"] = "tensorrt"
    server = FakeLaravel()

    assert run_job(job, settings, transport=server.transport) == "failed"
    assert server.completed["error_code"] == "model_unsupported"


def test_cancel_file_stops_the_run(make_job, settings, tmp_path):
    job = make_job("simulation")
    cancel = tmp_path / "cancel-me"
    cancel.touch()
    server = FakeLaravel()

    assert run_job(job, settings, transport=server.transport, cancel_file=cancel) == "cancelled"
    assert server.completed["status"] == "cancelled"


def test_retry_resends_identical_bytes_so_the_server_sees_a_replay(tmp_path):
    server = FakeLaravel()
    server.fail_next_batches = 2
    client = CallbackClient("http://laravel.test/internal/runs/x", "worker-token-1234567890", tmp_path, "x", 1,
                            transport=server.transport, sleep=lambda s: None)

    client.send_batch({"events": [], "attempt": 1})

    assert len(server.batches) == 1
    assert list(tmp_path.glob("*.json")) == []


def test_fatal_conflicts_abort_the_run(tmp_path):
    def handler(request):
        return httpx.Response(409, json={"error": "stale_attempt"})

    client = CallbackClient("http://laravel.test/internal/runs/x", "t" * 20, tmp_path, "x", 1,
                            transport=httpx.MockTransport(handler), sleep=lambda s: None)
    with pytest.raises(RunAborted) as error:
        client.send_batch({"events": []})
    assert error.value.code == "stale_attempt"


def test_unreachable_server_gives_up_after_the_retry_budget(tmp_path):
    def handler(request):
        raise httpx.ConnectError("refused")

    client = CallbackClient("http://laravel.test/internal/runs/x", "t" * 20, tmp_path, "x", 1,
                            transport=httpx.MockTransport(handler), sleep=lambda s: None, max_wait_s=5)
    with pytest.raises(RunAborted) as error:
        client.send_batch({"events": []})
    assert error.value.code == "callback_unreachable"
    assert len(list(tmp_path.glob("*.json"))) == 1
