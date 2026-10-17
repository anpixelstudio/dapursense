from worker.detectors import Detection, PpeObservation
from worker.rules import RulesEngine

from .conftest import ZONES

IN_STOVE = (0.45, 0.4, 0.55, 0.9)
IN_RESTRICTED = (0.85, 0.4, 0.95, 0.9)
OUTSIDE = (0.05, 0.1, 0.15, 0.4)
STEP = 333


def det(bbox=IN_STOVE, hair="present", apron="present", vis=0.9, score=0.85):
    return Detection(bbox, 0.9, {
        "hair_cover": PpeObservation(hair, score, vis),
        "apron": PpeObservation(apron, score, vis),
    })


def feed(engine, start, end, make, key="1"):
    ts = start
    while ts <= end:
        engine.observe(ts, key, make(ts))
        ts += STEP
    return ts - STEP


def collect(engine):
    out = engine.drain()
    return out, {(e["type"], e["episode_start_ms"]): e for e in out["events"]}


def test_short_missing_within_grace_is_not_a_violation(config):
    engine = RulesEngine(config, ZONES)
    feed(engine, 0, 3000, lambda t: det())
    feed(engine, 3333, 4666, lambda t: det(hair="missing"))
    feed(engine, 4999, 9000, lambda t: det())
    engine.finish(9000)
    _, events = collect(engine)
    assert not [e for e in events.values() if e["type"] == "ppe_missing"]


def test_sustained_missing_opens_one_episode_and_recovery_closes_it(config):
    engine = RulesEngine(config, ZONES)
    feed(engine, 0, 3000, lambda t: det())
    feed(engine, 3333, 12000, lambda t: det(hair="missing"))
    out, events = collect(engine)
    ppe = [e for e in events.values() if e["type"] == "ppe_missing"]
    assert len(ppe) == 1 and ppe[0]["lifecycle"] == "active"
    assert ppe[0]["episode_start_ms"] == 3333
    assert ppe[0]["evidence"]["items"] == ["hair_cover"]

    feed(engine, 12333, 17000, lambda t: det())
    _, events = collect(engine)
    closed = events[("ppe_missing", 3333)]
    assert closed["lifecycle"] == "closed"
    assert closed["last_ms"] < 12333


def test_cooldown_prevents_a_new_episode_right_after_recovery(config):
    engine = RulesEngine(config, ZONES)
    feed(engine, 0, 8000, lambda t: det(hair="missing"))
    feed(engine, 8333, 12000, lambda t: det())
    feed(engine, 12333, 20000, lambda t: det(hair="missing"))
    engine.finish(20000)
    _, events = collect(engine)
    assert len([e for e in events.values() if e["type"] == "ppe_missing"]) == 1


def test_unknown_frames_do_not_count_as_missing(config):
    engine = RulesEngine(config, ZONES)
    feed(engine, 0, 10000, lambda t: det(hair="missing", vis=0.2))
    engine.finish(10000)
    out, events = collect(engine)
    assert not [e for e in events.values() if e["type"] == "ppe_missing"]
    assert {a["state"] for a in out["ppe_assessments"]} == {"unknown"}
    assert out["ppe_assessments"][0]["reason"] == "low_confidence"


def test_long_unknown_raises_unassessable_review(config):
    engine = RulesEngine(config, ZONES)
    feed(engine, 0, 20000, lambda t: det(hair="unknown", apron="unknown"))
    engine.finish(20000)
    _, events = collect(engine)
    review = [e for e in events.values() if e["type"] == "unassessable_ppe"]
    assert len(review) == 1
    assert review[0]["category"] == "review_needed"
    assert review[0]["episode_start_ms"] == 0


def test_unsupported_ppe_is_coverage_not_a_review_event(config):
    engine = RulesEngine(config, ZONES)
    unsupported = PpeObservation("unknown", 0.0, 0.0, "unsupported")
    feed(engine, 0, 20000, lambda t: Detection(IN_STOVE, 0.9, {"hair_cover": unsupported, "apron": unsupported}))
    engine.finish(20000)
    out, events = collect(engine)
    assert not events
    assert {(a["state"], a["reason"]) for a in out["ppe_assessments"]} == {("unknown", "unsupported")}


def test_no_ppe_requirement_outside_required_zones(config):
    engine = RulesEngine(config, ZONES)
    feed(engine, 0, 10000, lambda t: det(bbox=OUTSIDE, hair="missing"))
    engine.finish(10000)
    out, events = collect(engine)
    assert not events
    assert out["ppe_assessments"] == []
    assert {s["label"] for s in out["activity_segments"]} == {"outside_zones"}


def test_restricted_zone_requires_dwell(config):
    engine = RulesEngine(config, ZONES)
    feed(engine, 0, 1333, lambda t: det(bbox=IN_RESTRICTED))
    feed(engine, 1666, 3000, lambda t: det(bbox=OUTSIDE))
    feed(engine, 3333, 7000, lambda t: det(bbox=IN_RESTRICTED))
    engine.finish(7000)
    _, events = collect(engine)
    restricted = [e for e in events.values() if e["type"] == "restricted_zone"]
    assert len(restricted) == 1
    assert restricted[0]["episode_start_ms"] == 3333
    assert restricted[0]["severity"] == "high"
    assert restricted[0]["evidence"]["zone_id"] == "z-restricted"


def test_lost_track_marks_open_episode_as_observation_lost(config):
    engine = RulesEngine(config, ZONES)
    feed(engine, 0, 8000, lambda t: det(hair="missing"))
    engine.lost("1", 10000)
    _, events = collect(engine)
    assert events[("ppe_missing", 0)]["lifecycle"] == "observation_lost"


def test_activity_segments_follow_zone_changes(config):
    engine = RulesEngine(config, ZONES)
    feed(engine, 0, 3000, lambda t: det(bbox=OUTSIDE))
    feed(engine, 3333, 6000, lambda t: det(bbox=IN_STOVE))
    engine.finish(6000)
    out, _ = collect(engine)
    segments = [(s["label"], s["zone_id"]) for s in out["activity_segments"]]
    assert segments == [("outside_zones", None), ("in_stove_zone", "z-stove")]
    assert all(s["end_ms"] >= s["start_ms"] for s in out["activity_segments"])


def test_two_items_missing_share_one_track_episode(config):
    engine = RulesEngine(config, ZONES)
    feed(engine, 0, 8000, lambda t: det(hair="missing", apron="missing"))
    engine.finish(8000)
    _, events = collect(engine)
    ppe = [e for e in events.values() if e["type"] == "ppe_missing"]
    assert len(ppe) == 1
    assert ppe[0]["evidence"]["items"] == ["apron", "hair_cover"]


def test_drain_only_returns_changes(config):
    engine = RulesEngine(config, ZONES)
    feed(engine, 0, 8000, lambda t: det(hair="missing"))
    first = engine.drain()
    second = engine.drain()
    assert first["events"] and second["events"] == [] and second["tracks"] == []
