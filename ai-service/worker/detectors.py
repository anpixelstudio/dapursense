import hashlib
import math
from dataclasses import dataclass, field
from pathlib import Path

import numpy as np

from .geometry import centroid

PPE_ITEMS = ("hair_cover", "apron", "glove", "mask")


@dataclass
class PpeObservation:
    state: str  # present | missing | unknown
    score: float = 0.0
    visibility: float = 0.0
    reason: str | None = None


@dataclass
class Detection:
    bbox: tuple[float, float, float, float]  # normalized xyxy
    score: float
    ppe: dict[str, PpeObservation] = field(default_factory=dict)
    hint: str | None = None


class SimulationDetector:
    """
    Deterministic scripted people placed relative to the configured zones, so a
    simulation exercises the whole pipeline (zones, PPE episodes, restricted
    dwell, unassessable) on any video. Results are always flagged as simulation.
    """

    simulated = True

    def __init__(self, duration_ms: int, zones: list[dict]):
        self.duration = max(1, duration_ms)
        required = [z for z in zones if z["type"] in ("prep", "stove")]
        restricted = [z for z in zones if z["type"] == "restricted"]
        self.work = centroid(required[0]["points"]) if required else (0.45, 0.8)
        self.work2 = centroid(required[1]["points"]) if len(required) > 1 else (min(0.9, self.work[0] + 0.15), self.work[1])
        self.restricted = centroid(restricted[0]["points"]) if restricted else None

    def detect(self, frame: np.ndarray | None, ts: int) -> list[Detection]:
        p = ts / self.duration
        people: list[Detection] = []

        if p < 0.9:
            arrive = min(1.0, p / 0.15)
            x = 0.05 + (self.work[0] - 0.05) * arrive
            hair = "missing" if 0.3 <= p < 0.6 else "present"
            people.append(self._person("sim-1", x, self.work[1], {
                "hair_cover": PpeObservation(hair, 0.85, 0.9),
                "apron": PpeObservation("present", 0.9, 0.9),
            }))

        if 0.1 <= p < 0.95:
            occluded_from = 0.35 * self.duration
            visible = not (occluded_from <= ts < occluded_from + max(0.2 * self.duration, 16_000))
            people.append(self._person("sim-2", self.work2[0], self.work2[1], {
                "hair_cover": PpeObservation("present" if visible else "unknown", 0.9 if visible else 0.2, 0.9 if visible else 0.2, None if visible else "occluded"),
                "apron": PpeObservation("present" if visible else "unknown", 0.9 if visible else 0.2, 0.9 if visible else 0.2, None if visible else "occluded"),
            }))

        if self.restricted and 0.5 <= p < 0.7:
            people.append(self._person("sim-3", *self.restricted, {}))

        return people

    @staticmethod
    def _person(hint: str, fx: float, fy: float, ppe: dict) -> Detection:
        w, h = 0.12, 0.45
        fy = min(max(fy, h + 0.01), 0.999)
        x1 = min(max(fx - w / 2, 0.0), 1 - w)
        return Detection((x1, fy - h, x1 + w, fy), 0.9, ppe, hint)


class HogPersonDetector:
    """OpenCV HOG people detector baseline. It cannot see PPE: every item is unknown."""

    simulated = False

    def __init__(self, min_score: float = 0.5, width: int = 640):
        import cv2

        self.cv2 = cv2
        self.hog = cv2.HOGDescriptor()
        self.hog.setSVMDetector(cv2.HOGDescriptor_getDefaultPeopleDetector())
        self.min_score = min_score
        self.width = width

    def detect(self, frame: np.ndarray, ts: int) -> list[Detection]:
        h, w = frame.shape[:2]
        scale = self.width / w if w > self.width else 1.0
        small = self.cv2.resize(frame, (int(w * scale), int(h * scale))) if scale != 1.0 else frame
        rects, weights = self.hog.detectMultiScale(small, winStride=(8, 8), padding=(8, 8), scale=1.05)

        boxes, scores = [], []
        for (x, y, bw, bh), weight in zip(rects, np.ravel(weights) if len(weights) else []):
            score = 1 / (1 + math.exp(-float(weight)))
            if score >= self.min_score:
                boxes.append([int(x), int(y), int(bw), int(bh)])
                scores.append(score)

        keep = self.cv2.dnn.NMSBoxes(boxes, scores, self.min_score, 0.4) if boxes else []
        sh, sw = small.shape[:2]
        unknown = {item: PpeObservation("unknown", 0.0, 0.0, "unsupported") for item in PPE_ITEMS}
        return [
            Detection(
                (boxes[i][0] / sw, boxes[i][1] / sh, (boxes[i][0] + boxes[i][2]) / sw, (boxes[i][1] + boxes[i][3]) / sh),
                round(scores[i], 4),
                dict(unknown),
            )
            for i in np.ravel(keep)
        ]


class YoloxPersonDetector:
    """
    YOLOX detector run through OpenCV DNN; only class 0 (person/body) is kept. Works on
    upper bodies behind counters where HOG fails. No PPE.

    letterbox=True matches the Megvii COCO export; letterbox=False (plain resize) matches
    PINTO YOLOX-WholeBody12, which also handles overhead and partially visible bodies.
    """

    simulated = False
    PERSON = 0

    def __init__(self, model_path: Path, min_score: float = 0.5, input_size: int = 416, nms: float = 0.45,
                 letterbox: bool = True):
        import cv2

        self.cv2 = cv2
        self.net = cv2.dnn.readNetFromONNX(str(model_path))
        self.size, self.min_score, self.nms, self.letterbox = input_size, min_score, nms, letterbox
        grids, strides = [], []
        for stride in (8, 16, 32):
            g = input_size // stride
            yv, xv = np.meshgrid(np.arange(g), np.arange(g), indexing="ij")
            grids.append(np.stack((xv, yv), 2).reshape(-1, 2))
            strides.append(np.full((g * g, 1), stride))
        self.grids, self.strides = np.concatenate(grids), np.concatenate(strides)

    def detect(self, frame: np.ndarray, ts: int) -> list[Detection]:
        h, w = frame.shape[:2]
        if self.letterbox:
            rx = ry = min(self.size / h, self.size / w)
            image = np.full((self.size, self.size, 3), 114, dtype=np.uint8)
            image[: int(h * ry), : int(w * rx)] = self.cv2.resize(frame, (int(w * rx), int(h * ry)))
        else:
            rx, ry = self.size / w, self.size / h
            image = self.cv2.resize(frame, (self.size, self.size))
        self.net.setInput(image.transpose(2, 0, 1)[None].astype(np.float32))
        out = self.net.forward()[0]

        xy = (out[:, :2] + self.grids) * self.strides
        wh = np.exp(out[:, 2:4]) * self.strides
        scores = out[:, 4] * out[:, 5 + self.PERSON]
        keep = scores >= self.min_score
        if not keep.any():
            return []

        boxes = [[float((cx - bw / 2) / rx), float((cy - bh / 2) / ry), float(bw / rx), float(bh / ry)]
                 for (cx, cy), (bw, bh) in zip(xy[keep], wh[keep])]
        kept_scores = scores[keep].tolist()
        unknown = {item: PpeObservation("unknown", 0.0, 0.0, "unsupported") for item in PPE_ITEMS}
        detections = []
        for i in np.ravel(self.cv2.dnn.NMSBoxes(boxes, kept_scores, self.min_score, self.nms)):
            x, y, bw, bh = boxes[i]
            x1, y1 = max(0.0, x / w), max(0.0, y / h)
            x2, y2 = min(1.0, (x + bw) / w), min(1.0, (y + bh) / h)
            detections.append(Detection((x1, y1, x2, y2), round(kept_scores[i], 4), dict(unknown)))
        return detections


def resolve_artifact(model: dict, models_dir: Path) -> Path:
    key = model.get("artifact_key") or ""
    path = (models_dir / key).resolve()
    if not key or models_dir.resolve() not in path.parents:
        raise ValueError(f"model_unsupported:artifact_key tidak valid untuk {model.get('name')}")
    if not path.is_file():
        raise ValueError(f"model_unsupported:file model {key} tidak ada di {models_dir}")
    if hashlib.sha256(path.read_bytes()).hexdigest() != model.get("sha256"):
        raise ValueError(f"model_unsupported:checksum {key} tidak cocok dengan registry")
    return path


def make_detector(model: dict, duration_ms: int, zones: list[dict], min_person_score: float, models_dir: Path | None = None):
    runtime = model.get("runtime")
    if runtime == "simulation":
        return SimulationDetector(duration_ms, zones)
    if runtime == "opencv":
        return HogPersonDetector(min_person_score)
    if runtime == "onnx" and models_dir is not None:
        return YoloxPersonDetector(resolve_artifact(model, models_dir), min_person_score)
    if runtime == "onnx-wholebody" and models_dir is not None:
        return YoloxPersonDetector(resolve_artifact(model, models_dir), min_person_score, input_size=640, letterbox=False)
    raise ValueError(f"model_unsupported:{runtime}")
