import json

from fastapi.testclient import TestClient

from worker.api import create_app
from worker.runner import process_one

from .conftest import FakeLaravel

AUTH = {"Authorization": "Bearer svc-token"}


def test_endpoints_require_the_service_token(settings):
    client = TestClient(create_app(settings))
    assert client.get("/health").status_code == 401
    assert client.get("/health", headers={"Authorization": "Bearer wrong"}).status_code == 401
    assert client.post("/runs/x/cancel").status_code == 401


def test_submit_writes_a_private_job_file_idempotently(settings, make_job):
    client = TestClient(create_app(settings))
    job = make_job()

    assert client.post("/runs", json=job, headers=AUTH).status_code == 202
    assert client.post("/runs", json=job, headers=AUTH).status_code == 202

    files = list((settings.jobs_dir / "pending").glob("*.json"))
    assert len(files) == 1
    assert oct(files[0].stat().st_mode & 0o777) == "0o600"
    assert json.loads(files[0].read_text())["worker_token"] == job["worker_token"]


def test_submit_rejects_another_contract_version(settings, make_job):
    client = TestClient(create_app(settings))
    job = make_job()
    job["input"]["contract_version"] = 99

    assert client.post("/runs", json=job, headers=AUTH).status_code == 422


def test_health_reports_worker_liveness(settings):
    client = TestClient(create_app(settings))
    assert client.get("/health", headers=AUTH).json()["ok"] is False

    (settings.jobs_dir / "worker.alive").touch()
    body = client.get("/health", headers=AUTH).json()
    assert body["ok"] is True and body["contract_version"] == 1


def test_cancel_removes_a_pending_job(settings, make_job):
    client = TestClient(create_app(settings))
    job = make_job()
    client.post("/runs", json=job, headers=AUTH)

    assert client.post(f"/runs/{job['run_id']}/cancel", headers=AUTH).status_code == 202
    assert list((settings.jobs_dir / "pending").glob("*.json")) == []
    assert (settings.jobs_dir / "cancel" / job["run_id"]).exists()


def test_runner_processes_one_job_at_a_time(settings, make_job):
    client = TestClient(create_app(settings))
    first, second = make_job(), make_job()
    client.post("/runs", json=first, headers=AUTH)
    client.post("/runs", json=second, headers=AUTH)
    server = FakeLaravel()

    assert process_one(settings, transport=server.transport) == "succeeded"
    assert len(list((settings.jobs_dir / "pending").glob("*.json"))) == 1
    assert list((settings.jobs_dir / "running").glob("*.json")) == []
