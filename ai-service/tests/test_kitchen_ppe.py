from pathlib import Path

import numpy as np
import pytest

from worker.detectors import Detection
from worker.kitchen_ppe import KitchenPpeDetector, Yolov8OnnxPpeNet

YOLOX = Path(__file__).resolve().parent.parent / "models" / "yolox_nano.onnx"
PPE = Path(__file__).resolve().parent.parent / "models" / "hairnet_gloves_yolov8.onnx"


@pytest.mark.skipif(not PPE.exists(), reason="hairnet_gloves_yolov8.onnx belum ada")
def test_yolov8_ppe_net_runs_on_blank_frame():
    net = Yolov8OnnxPpeNet(PPE, min_score=0.5)
    dets = net.detect(np.zeros((480, 640, 3), dtype=np.uint8))
    assert isinstance(dets, list)


@pytest.mark.skipif(not (YOLOX.exists() and PPE.exists()), reason="model kitchen PPE belum lengkap")
def test_kitchen_ppe_detector_merges_person_and_ppe(monkeypatch):
    det = KitchenPpeDetector(YOLOX, PPE, min_person_score=0.1, min_ppe_score=0.1)
    monkeypatch.setattr(
        det.person,
        "detect",
        lambda frame, ts: [Detection((0.2, 0.1, 0.55, 0.95), 0.92, {})],
    )
    monkeypatch.setattr(
        det.ppe,
        "detect",
        lambda frame: [
            {"name": "Hairnet", "box": (0.28, 0.12, 0.42, 0.3), "score": 0.87},
            {"name": "Bare Hand", "box": (0.25, 0.6, 0.35, 0.75), "score": 0.8},
        ],
    )
    out = det.detect(np.zeros((480, 640, 3), dtype=np.uint8), 0)
    assert len(out) == 1
    assert out[0].ppe["hair_cover"].state == "present"
    assert out[0].ppe["glove"].state == "missing"
    assert out[0].ppe["apron"].reason == "unsupported"
