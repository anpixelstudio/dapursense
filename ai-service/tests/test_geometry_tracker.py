from worker.detectors import Detection
from worker.geometry import foot_point, iou, point_in_polygon, zone_for
from worker.tracker import IoUTracker

SQUARE = [[0.2, 0.2], [0.6, 0.2], [0.6, 0.6], [0.2, 0.6]]


def test_point_in_polygon():
    assert point_in_polygon(0.4, 0.4, SQUARE)
    assert not point_in_polygon(0.7, 0.4, SQUARE)
    assert not point_in_polygon(0.1, 0.1, SQUARE)


def test_concave_polygon():
    l_shape = [[0, 0], [1, 0], [1, 0.3], [0.3, 0.3], [0.3, 1], [0, 1]]
    assert point_in_polygon(0.1, 0.9, l_shape)
    assert not point_in_polygon(0.8, 0.8, l_shape)


def test_zone_membership_uses_the_foot_point():
    zones = [{"id": "a", "type": "prep", "points": [[0, 0.7], [1, 0.7], [1, 1], [0, 1]]}]
    head_in_zone_feet_outside = (0.4, 0.75, 0.5, 0.69)
    feet_in_zone = (0.4, 0.2, 0.5, 0.9)
    assert foot_point(feet_in_zone) == (0.45, 0.9)
    assert zone_for(feet_in_zone, zones)["id"] == "a"
    assert zone_for(head_in_zone_feet_outside, zones) is None


def test_overlapping_zones_prefer_restricted():
    zones = [
        {"id": "prep", "type": "prep", "points": [[0, 0], [1, 0], [1, 1], [0, 1]]},
        {"id": "r", "type": "restricted", "points": [[0, 0], [1, 0], [1, 1], [0, 1]]},
    ]
    assert zone_for((0.4, 0.2, 0.5, 0.5), zones)["id"] == "r"


def test_iou():
    assert iou((0, 0, 1, 1), (0, 0, 1, 1)) == 1.0
    assert iou((0, 0, 0.5, 0.5), (0.5, 0.5, 1, 1)) == 0.0
    assert 0.1 < iou((0, 0, 0.5, 0.5), (0.25, 0.25, 0.75, 0.75)) < 0.2


def test_tracker_keeps_identity_and_reports_lost_tracks():
    tracker = IoUTracker(max_lost_ms=1000)
    a, b = Detection((0.1, 0.1, 0.3, 0.5), 0.9), Detection((0.6, 0.1, 0.8, 0.5), 0.8)

    first, _ = tracker.update(0, [a, b])
    second, _ = tracker.update(333, [Detection((0.12, 0.1, 0.32, 0.5), 0.9), Detection((0.61, 0.1, 0.81, 0.5), 0.8)])
    assert {k for k, _ in first} == {k for k, _ in second} == {"1", "2"}

    _, lost = tracker.update(1500, [Detection((0.13, 0.1, 0.33, 0.5), 0.9)])
    assert lost == ["2"]


def test_tracker_starts_a_new_track_for_a_new_person():
    tracker = IoUTracker()
    tracker.update(0, [Detection((0.1, 0.1, 0.3, 0.5), 0.9)])
    matched, _ = tracker.update(333, [Detection((0.7, 0.1, 0.9, 0.5), 0.9)])
    assert matched[0][0] == "2"
