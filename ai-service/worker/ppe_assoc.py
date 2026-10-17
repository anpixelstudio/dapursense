"""Associate PPE detections to person boxes (IoPPE + anatomical zones)."""

from __future__ import annotations

from .detectors import PPE_ITEMS, Detection, PpeObservation

# Vertical zone of the person box where each PPE class is expected [y_min, y_max] ratio.
PPE_ZONES: dict[str, tuple[float, float]] = {
    "Hairnet": (0.0, 0.45),
    "Bare Hair": (0.0, 0.45),
    "Glove": (0.20, 1.05),
    "Bare Hand": (0.20, 1.05),
    "apron": (0.15, 0.95),
    "mask": (0.0, 0.40),
}

# Model class → (item_code, state)
CLASS_TO_PPE: dict[str, tuple[str, str]] = {
    "Hairnet": ("hair_cover", "present"),
    "Bare Hair": ("hair_cover", "missing"),
    "Glove": ("glove", "present"),
    "Bare Hand": ("glove", "missing"),
}


def intersection_area(a: tuple[float, float, float, float], b: tuple[float, float, float, float]) -> float:
    ix1, iy1 = max(a[0], b[0]), max(a[1], b[1])
    ix2, iy2 = min(a[2], b[2]), min(a[3], b[3])
    return max(0.0, ix2 - ix1) * max(0.0, iy2 - iy1)


def ioppe(person: tuple[float, float, float, float], ppe: tuple[float, float, float, float]) -> float:
    """Intersection over PPE area — better than IoU for small gear on a large body box."""
    area = max(0.0, (ppe[2] - ppe[0]) * (ppe[3] - ppe[1]))
    return intersection_area(person, ppe) / area if area > 0 else 0.0


def in_anatomical_zone(
    person: tuple[float, float, float, float],
    ppe: tuple[float, float, float, float],
    class_name: str,
) -> bool:
    zone = PPE_ZONES.get(class_name)
    if zone is None:
        return True
    py1, py2 = person[1], person[3]
    ph = max(1e-6, py2 - py1)
    cy = (ppe[1] + ppe[3]) / 2.0
    rel = (cy - py1) / ph
    return zone[0] <= rel <= zone[1]


def empty_ppe(supported: set[str] | None = None) -> dict[str, PpeObservation]:
    """Items in `supported` default to unknown (assessable gap); others are unsupported."""
    supported = supported or set()
    out: dict[str, PpeObservation] = {}
    for item in PPE_ITEMS:
        if item in supported:
            out[item] = PpeObservation("unknown", 0.0, 0.0, None)
        else:
            out[item] = PpeObservation("unknown", 0.0, 0.0, "unsupported")
    return out


def associate_ppe(
    people: list[Detection],
    ppe_boxes: list[dict],
    *,
    ioppe_threshold: float = 0.25,
    supported: set[str] | None = None,
) -> list[Detection]:
    """
    Greedy exclusive matching of PPE boxes onto person detections.

    `ppe_boxes` items: {"name": str, "box": xyxy normalized, "score": float}
    """
    supported = supported or {"hair_cover", "glove"}
    if not people:
        return []

    candidates: list[tuple[float, int, int, str, str, float]] = []
    for pi, person in enumerate(people):
        for bi, item in enumerate(ppe_boxes):
            name = item["name"]
            mapping = CLASS_TO_PPE.get(name)
            if mapping is None:
                continue
            item_code, state = mapping
            if item_code not in supported:
                continue
            box = item["box"]
            score = float(item["score"])
            overlap = ioppe(person.bbox, box)
            if overlap < ioppe_threshold or not in_anatomical_zone(person.bbox, box, name):
                continue
            candidates.append((overlap * score, pi, bi, item_code, state, score))

    candidates.sort(key=lambda x: x[0], reverse=True)
    used_ppe: set[int] = set()
    # person_idx -> item_code -> (state, score)
    assigned: dict[int, dict[str, tuple[str, float]]] = {i: {} for i in range(len(people))}

    for _, pi, bi, item_code, state, score in candidates:
        if bi in used_ppe:
            continue
        current = assigned[pi].get(item_code)
        if current is not None and current[1] >= score:
            continue
        used_ppe.add(bi)
        assigned[pi][item_code] = (state, score)

    result: list[Detection] = []
    for pi, person in enumerate(people):
        ppe = empty_ppe(supported)
        for item_code, (state, score) in assigned[pi].items():
            ppe[item_code] = PpeObservation(state, round(score, 4), 0.9, None)
        result.append(Detection(person.bbox, person.score, ppe, person.hint))
    return result
