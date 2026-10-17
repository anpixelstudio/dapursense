from worker.detectors import Detection
from worker.ppe_assoc import associate_ppe, ioppe


def test_ioppe_is_one_when_ppe_fully_inside_person():
    person = (0.1, 0.1, 0.5, 0.9)
    helmet = (0.2, 0.12, 0.35, 0.28)
    assert ioppe(person, helmet) == 1.0


def test_associate_hairnet_and_glove_onto_person():
    person = Detection((0.2, 0.1, 0.5, 0.95), 0.9, {})
    boxes = [
        {"name": "Hairnet", "box": (0.25, 0.12, 0.4, 0.28), "score": 0.88},
        {"name": "Glove", "box": (0.22, 0.55, 0.32, 0.7), "score": 0.81},
    ]
    out = associate_ppe([person], boxes)
    assert len(out) == 1
    assert out[0].ppe["hair_cover"].state == "present"
    assert out[0].ppe["glove"].state == "present"
    assert out[0].ppe["apron"].reason == "unsupported"
    assert out[0].ppe["mask"].reason == "unsupported"


def test_bare_hair_and_bare_hand_mark_missing():
    person = Detection((0.2, 0.1, 0.5, 0.95), 0.9, {})
    boxes = [
        {"name": "Bare Hair", "box": (0.25, 0.12, 0.4, 0.28), "score": 0.77},
        {"name": "Bare Hand", "box": (0.22, 0.55, 0.32, 0.7), "score": 0.7},
    ]
    out = associate_ppe([person], boxes)[0]
    assert out.ppe["hair_cover"].state == "missing"
    assert out.ppe["glove"].state == "missing"


def test_ppe_far_from_person_is_ignored():
    person = Detection((0.1, 0.1, 0.3, 0.9), 0.9, {})
    boxes = [{"name": "Hairnet", "box": (0.8, 0.1, 0.95, 0.3), "score": 0.99}]
    out = associate_ppe([person], boxes)[0]
    assert out.ppe["hair_cover"].state == "unknown"
    assert out.ppe["hair_cover"].reason is None  # supported but not seen


def test_exclusive_matching_does_not_share_one_ppe_box():
    a = Detection((0.1, 0.1, 0.4, 0.9), 0.9, {})
    b = Detection((0.35, 0.1, 0.65, 0.9), 0.85, {})
    # Hairnet mostly inside person A
    boxes = [{"name": "Hairnet", "box": (0.15, 0.12, 0.3, 0.28), "score": 0.9}]
    out = associate_ppe([a, b], boxes)
    states = [d.ppe["hair_cover"].state for d in out]
    assert states.count("present") == 1
    assert states.count("unknown") == 1
