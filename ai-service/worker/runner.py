"""Single-slot job loop: one analysis at a time, jobs handed over by the API as files."""

import json
import logging
import signal
import threading
import time

from .config import Settings
from .pipeline import run_job

log = logging.getLogger("worker.runner")


def next_job(settings: Settings):
    pending = sorted(settings.dirs()["pending"].glob("*.json"), key=lambda p: p.stat().st_mtime)
    return pending[0] if pending else None


def process_one(settings: Settings, transport=None) -> str | None:
    dirs = settings.dirs()
    path = next_job(settings)
    if path is None:
        return None

    running = dirs["running"] / path.name
    path.replace(running)
    try:
        job = json.loads(running.read_text())
        cancel_file = dirs["cancel"] / job["run_id"]
        log.info("run %s attempt %s started", job["run_id"], job["attempt"])
        status = run_job(job, settings, transport=transport, cancel_file=cancel_file)
        log.info("run %s finished: %s", job["run_id"], status)
        cancel_file.unlink(missing_ok=True)
        return status
    finally:
        running.unlink(missing_ok=True)


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")
    settings = Settings.from_env()
    dirs = settings.dirs()
    alive = settings.jobs_dir / "worker.alive"
    stopping = False

    def stop(*_):
        nonlocal stopping
        stopping = True

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)

    # Jobs interrupted by a crash are not resumed here: the Laravel watchdog
    # retries them with a new attempt and token, which invalidates the old one.
    for stale in dirs["running"].glob("*.json"):
        stale.unlink(missing_ok=True)

    def keep_alive():
        while not stopping:
            alive.touch()
            time.sleep(5)

    threading.Thread(target=keep_alive, daemon=True).start()

    log.info("worker started, jobs dir %s", settings.jobs_dir)
    while not stopping:
        if process_one(settings) is None:
            time.sleep(1)


if __name__ == "__main__":
    main()
