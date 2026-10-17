import json
import time
from pathlib import Path

import httpx

FATAL_CONFLICTS = {"payload_conflict", "stale_attempt", "run_not_active"}


class RunAborted(Exception):
    """The server no longer accepts results for this attempt."""

    def __init__(self, code: str, message: str = ""):
        super().__init__(f"{code}: {message}")
        self.code = code


class CallbackClient:
    """
    Sends results to Laravel. Every batch is written to the outbox before it is
    sent and the stored bytes are re-sent unchanged on retry, so the server can
    recognise a replay by its payload hash.
    """

    def __init__(self, base_url: str, token: str, outbox: Path, run_id: str, attempt: int,
                 transport: httpx.BaseTransport | None = None, max_wait_s: float = 120.0, sleep=time.sleep):
        self.client = httpx.Client(
            base_url=base_url.rstrip("/"), transport=transport, timeout=30.0,
            headers={"Authorization": f"Bearer {token}", "Accept": "application/json", "Content-Type": "application/json"},
        )
        self.outbox = outbox
        self.prefix = f"{run_id}-{attempt}"
        self.max_wait_s = max_wait_s
        self.sleep = sleep
        self.sequence = 0

    def send_batch(self, payload: dict) -> dict:
        self.sequence += 1
        payload = {**payload, "batch_sequence": self.sequence}
        path = self.outbox / f"{self.prefix}-{self.sequence:06d}.json"
        body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode()
        path.write_bytes(body)
        result = self._post("/batches", body)
        path.unlink(missing_ok=True)
        return result

    def heartbeat(self, attempt: int, processed_ms: int, phase: str) -> dict:
        return self._post("/heartbeat", json.dumps({"attempt": attempt, "processed_ms": processed_ms, "phase": phase}).encode(), retry=False)

    def complete(self, payload: dict) -> dict:
        return self._post("/complete", json.dumps({**payload, "final_batch_sequence": self.sequence}).encode())

    def _post(self, path: str, body: bytes, retry: bool = True) -> dict:
        delay, waited = 1.0, 0.0
        while True:
            try:
                response = self.client.post(path, content=body)
            except httpx.TransportError:
                response = None

            if response is not None and response.status_code < 300:
                return response.json()

            if response is not None and response.status_code in (401, 403, 409, 413, 422):
                data = self._json(response)
                code = data.get("error") or ("validation_failed" if response.status_code == 422 else f"http_{response.status_code}")
                if response.status_code == 409 and code not in FATAL_CONFLICTS and not retry:
                    return data
                raise RunAborted(code, json.dumps(data)[:500])

            if not retry or waited >= self.max_wait_s:
                raise RunAborted("callback_unreachable", f"{path} status={getattr(response, 'status_code', None)}")
            self.sleep(delay)
            waited += delay
            delay = min(delay * 2, 15.0)

    @staticmethod
    def _json(response: httpx.Response) -> dict:
        try:
            return response.json()
        except ValueError:
            return {}

    def close(self) -> None:
        self.client.close()
