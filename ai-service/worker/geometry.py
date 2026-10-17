BBox = tuple[float, float, float, float]

ZONE_PRIORITY = {"restricted": 0, "stove": 1, "prep": 2, "sink": 3, "cleaning": 4}


def point_in_polygon(x: float, y: float, points: list[list[float]]) -> bool:
    inside = False
    n = len(points)
    j = n - 1
    for i in range(n):
        xi, yi = points[i]
        xj, yj = points[j]
        if (yi > y) != (yj > y) and x < (xj - xi) * (y - yi) / ((yj - yi) or 1e-12) + xi:
            inside = not inside
        j = i
    return inside


def foot_point(bbox: BBox) -> tuple[float, float]:
    """Zone membership uses the bottom-centre of the person box (where the feet stand)."""
    x1, _, x2, y2 = bbox
    return (x1 + x2) / 2, y2


def iou(a: BBox, b: BBox) -> float:
    ix1, iy1 = max(a[0], b[0]), max(a[1], b[1])
    ix2, iy2 = min(a[2], b[2]), min(a[3], b[3])
    inter = max(0.0, ix2 - ix1) * max(0.0, iy2 - iy1)
    union = (a[2] - a[0]) * (a[3] - a[1]) + (b[2] - b[0]) * (b[3] - b[1]) - inter
    return inter / union if union > 0 else 0.0


def zone_for(bbox: BBox, zones: list[dict]) -> dict | None:
    x, y = foot_point(bbox)
    hits = [z for z in zones if point_in_polygon(x, y, z["points"])]
    return min(hits, key=lambda z: ZONE_PRIORITY.get(z["type"], 9)) if hits else None


def centroid(points: list[list[float]]) -> tuple[float, float]:
    return sum(p[0] for p in points) / len(points), sum(p[1] for p in points) / len(points)
