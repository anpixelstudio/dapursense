"""Real kitchen PPE detector: YOLOX person + YOLOv8 hairnet/gloves ONNX."""

from __future__ import annotations

from pathlib import Path

import numpy as np

from .detectors import YoloxPersonDetector
from .ppe_assoc import associate_ppe

# Ultralytics YOLOv8 class order for KhawlaQuraan/hairnet-gloves-yolov8-v6
PPE_CLASS_NAMES = ("Bare Hand", "Hairnet", "Glove", "Bare Hair")
SUPPORTED_PPE = {"hair_cover", "glove"}


class Yolov8OnnxPpeNet:
    """YOLOv8 detection head via onnxruntime (letterbox 640, /255)."""

    def __init__(self, model_path: Path, min_score: float = 0.35, nms: float = 0.45, input_size: int = 640):
        import onnxruntime as ort

        self.session = ort.InferenceSession(str(model_path), providers=["CPUExecutionProvider"])
        self.input_name = self.session.get_inputs()[0].name
        self.min_score = min_score
        self.nms = nms
        self.size = input_size

    def detect(self, frame: np.ndarray) -> list[dict]:
        import cv2

        h, w = frame.shape[:2]
        img, ratio, pad = self._letterbox(frame)
        blob = img[:, :, ::-1].transpose(2, 0, 1)[None].astype(np.float32) / 255.0
        out = self.session.run(None, {self.input_name: blob})[0][0]  # (4+nc, N)
        pred = out.T  # (N, 4+nc)
        if pred.size == 0:
            return []

        boxes_xywh = pred[:, :4]
        cls_scores = pred[:, 4:]
        class_ids = cls_scores.argmax(axis=1)
        scores = cls_scores.max(axis=1)
        keep = scores >= self.min_score
        if not keep.any():
            return []

        boxes_xywh = boxes_xywh[keep]
        class_ids = class_ids[keep]
        scores = scores[keep]

        # xywh → xyxy in letterboxed space, then undo pad/scale to original pixels
        xyxy = np.stack([
            boxes_xywh[:, 0] - boxes_xywh[:, 2] / 2,
            boxes_xywh[:, 1] - boxes_xywh[:, 3] / 2,
            boxes_xywh[:, 0] + boxes_xywh[:, 2] / 2,
            boxes_xywh[:, 1] + boxes_xywh[:, 3] / 2,
        ], axis=1)
        xyxy[:, [0, 2]] -= pad[0]
        xyxy[:, [1, 3]] -= pad[1]
        xyxy /= ratio
        xyxy[:, [0, 2]] = xyxy[:, [0, 2]].clip(0, w)
        xyxy[:, [1, 3]] = xyxy[:, [1, 3]].clip(0, h)

        # NMS per class
        boxes_for_nms = [[float(x1), float(y1), float(x2 - x1), float(y2 - y1)] for x1, y1, x2, y2 in xyxy]
        kept: list[dict] = []
        for cls_id in np.unique(class_ids):
            idxs = np.where(class_ids == cls_id)[0]
            order = cv2.dnn.NMSBoxes(
                [boxes_for_nms[i] for i in idxs],
                [float(scores[i]) for i in idxs],
                self.min_score,
                self.nms,
            )
            for j in np.ravel(order):
                i = int(idxs[int(j)])
                x1, y1, x2, y2 = xyxy[i]
                name = PPE_CLASS_NAMES[int(class_ids[i])] if int(class_ids[i]) < len(PPE_CLASS_NAMES) else f"cls_{class_ids[i]}"
                kept.append({
                    "name": name,
                    "box": (float(x1 / w), float(y1 / h), float(x2 / w), float(y2 / h)),
                    "score": float(scores[i]),
                })
        return kept

    def _letterbox(self, frame: np.ndarray) -> tuple[np.ndarray, float, tuple[float, float]]:
        import cv2

        h, w = frame.shape[:2]
        ratio = min(self.size / h, self.size / w)
        nw, nh = int(round(w * ratio)), int(round(h * ratio))
        resized = cv2.resize(frame, (nw, nh), interpolation=cv2.INTER_LINEAR)
        pad_w, pad_h = (self.size - nw) / 2, (self.size - nh) / 2
        top, bottom = int(round(pad_h - 0.1)), int(round(pad_h + 0.1))
        left, right = int(round(pad_w - 0.1)), int(round(pad_w + 0.1))
        out = cv2.copyMakeBorder(resized, top, bottom, left, right, cv2.BORDER_CONSTANT, value=(114, 114, 114))
        return out, ratio, (left, top)


class KitchenPpeDetector:
    """Person via YOLOX ONNX + kitchen PPE via YOLOv8 ONNX, linked with IoPPE."""

    simulated = False

    def __init__(
        self,
        person_model: Path,
        ppe_model: Path,
        min_person_score: float = 0.5,
        min_ppe_score: float = 0.35,
        person_input_size: int = 416,
        person_letterbox: bool = True,
    ):
        self.person = YoloxPersonDetector(
            person_model,
            min_person_score,
            input_size=person_input_size,
            letterbox=person_letterbox,
        )
        self.ppe = Yolov8OnnxPpeNet(ppe_model, min_score=min_ppe_score)

    def detect(self, frame: np.ndarray, ts: int):
        people = self.person.detect(frame, ts)
        if not people:
            return []
        items = self.ppe.detect(frame)
        return associate_ppe(people, items, supported=SUPPORTED_PPE)
