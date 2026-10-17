from dataclasses import dataclass, field

from .detectors import Detection
from .geometry import iou


@dataclass
class Track:
    key: str
    bbox: tuple
    last_ms: int
    hint: str | None = None
    history: list = field(default_factory=list)


class IoUTracker:
    """Greedy IoU association. A track not matched for max_lost_ms is reported lost."""

    def __init__(self, iou_threshold: float = 0.3, max_lost_ms: int = 1500):
        self.iou_threshold = iou_threshold
        self.max_lost_ms = max_lost_ms
        self.active: dict[str, Track] = {}
        self._next = 1

    def update(self, ts: int, detections: list[Detection]) -> tuple[list[tuple[str, Detection]], list[str]]:
        matched: list[tuple[str, Detection]] = []
        free = dict(self.active)

        for det in sorted(detections, key=lambda d: -d.score):
            best, best_iou = None, self.iou_threshold
            for key, track in free.items():
                if det.hint is not None and track.hint is not None:
                    score = 1.0 if det.hint == track.hint else 0.0
                else:
                    score = iou(det.bbox, track.bbox)
                if score >= best_iou:
                    best, best_iou = key, score
            if best is None:
                best = str(self._next)
                self._next += 1
                self.active[best] = Track(best, det.bbox, ts, det.hint)
            else:
                free.pop(best)
            track = self.active[best]
            track.bbox, track.last_ms = det.bbox, ts
            track.history.append((ts, det.bbox))
            matched.append((best, det))

        lost = [k for k, t in self.active.items() if ts - t.last_ms > self.max_lost_ms]
        for key in lost:
            self.active.pop(key)
        return matched, lost

    def flush(self) -> list[str]:
        keys = list(self.active)
        self.active.clear()
        return keys
