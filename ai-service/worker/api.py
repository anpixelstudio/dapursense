import hmac
import json
import os
import time
from pathlib import Path

from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

from . import CONTRACT_VERSION
from .config import Settings

ALIVE_SECONDS = 30
MODELS = [
    "anpixel-simulator",
    "opencv-hog-person",
    "yolox-nano-person",
    "yolox-wholebody12-n",
    "yolox-kitchen-ppe",
]


class RunRequest(BaseModel):
    run_id: str = Field(pattern=r"^[0-9a-fA-F-]{36}$")
    tenant_id: str
    attempt: int = Field(ge=1)
    worker_token: str = Field(min_length=16)
    callback_base: str
    storage_root: str
    input: dict


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or Settings.from_env()
    dirs = settings.dirs()
    app = FastAPI(title="Anpixel Kitchen AI Worker", docs_url=None, redoc_url=None)

    def authorize(authorization: str = Header(default="")) -> None:
        token = authorization.removeprefix("Bearer ").strip()
        if not token or not hmac.compare_digest(token, settings.service_token):
            raise HTTPException(status_code=401, detail="unauthorized")

    @app.post("/runs", status_code=202, dependencies=[Depends(authorize)])
    def submit(run: RunRequest) -> dict:
        if run.input.get("contract_version") != CONTRACT_VERSION:
            raise HTTPException(status_code=422, detail="contract_version_mismatch")
        target = dirs["pending"] / f"{run.run_id}-{run.attempt}.json"
        if not target.exists():
            tmp = target.with_suffix(".tmp")
            tmp.write_text(json.dumps(run.model_dump()))
            os.chmod(tmp, 0o600)
            tmp.replace(target)
        (dirs["cancel"] / run.run_id).unlink(missing_ok=True)
        return {"accepted": True, "queue_depth": len(list(dirs["pending"].glob("*.json")))}

    @app.post("/runs/{run_id}/cancel", status_code=202, dependencies=[Depends(authorize)])
    def cancel(run_id: str) -> dict:
        if not run_id.replace("-", "").isalnum():
            raise HTTPException(status_code=422, detail="invalid_run_id")
        (dirs["cancel"] / run_id).touch()
        for pending in dirs["pending"].glob(f"{run_id}-*.json"):
            pending.unlink(missing_ok=True)
        return {"cancel_requested": True}

    @app.get("/health", dependencies=[Depends(authorize)])
    def health() -> dict:
        alive_file = settings.jobs_dir / "worker.alive"
        alive = alive_file.exists() and time.time() - alive_file.stat().st_mtime < ALIVE_SECONDS
        return {
            "ok": alive,
            "worker_alive": alive,
            "contract_version": CONTRACT_VERSION,
            "queue_depth": len(list(dirs["pending"].glob("*.json"))),
            "running": [p.stem for p in dirs["running"].glob("*.json")],
            "models": MODELS,
        }

    return app
