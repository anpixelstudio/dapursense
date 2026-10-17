import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Settings:
    service_token: str
    jobs_dir: Path
    heartbeat_interval_s: float = 10.0
    batch_interval_ms: int = 10_000
    observation_chunk_ms: int = 10_000
    ffmpeg: str = "ffmpeg"
    models_dir: Path = Path(__file__).resolve().parent.parent / "models"

    @classmethod
    def from_env(cls) -> "Settings":
        token = os.environ.get("AI_SERVICE_TOKEN", "")
        if not token:
            raise RuntimeError("AI_SERVICE_TOKEN wajib diisi")

        return cls(
            service_token=token,
            jobs_dir=Path(os.environ.get("AI_JOBS_DIR", Path(__file__).resolve().parent.parent / "var")),
            heartbeat_interval_s=float(os.environ.get("AI_HEARTBEAT_INTERVAL", "10")),
            ffmpeg=os.environ.get("FFMPEG_BINARY", "ffmpeg"),
            models_dir=Path(os.environ.get("AI_MODELS_DIR", Path(__file__).resolve().parent.parent / "models")),
        )

    def dirs(self) -> dict[str, Path]:
        paths = {name: self.jobs_dir / name for name in ("pending", "running", "cancel", "outbox")}
        for path in paths.values():
            path.mkdir(parents=True, exist_ok=True)
        return paths
