"""
Temporal rules on top of per-frame detections.

Everything is expressed as intervals (activity segments, PPE assessments) and
episodes (events) keyed by a stable episode start, so a batch can be replayed
and an episode that spans several batches updates one event on the server.
"""

from collections import deque
from dataclasses import dataclass, field

from .detectors import Detection, PpeObservation
from .geometry import zone_for


@dataclass
class ItemState:
    window: deque = field(default_factory=deque)
    missing_since: int | None = None
    present_since: int | None = None
    interval: dict | None = None


@dataclass
class TrackState:
    segment: dict | None = None
    items: dict[str, ItemState] = field(default_factory=dict)
    ppe_event: tuple | None = None
    ppe_active: set = field(default_factory=set)
    restricted_since: int | None = None
    restricted_event: tuple | None = None
    unknown_since: int | None = None
    unassessable_event: tuple | None = None
    cooldown: dict = field(default_factory=dict)


class RulesEngine:
    def __init__(self, config: dict, zones: list[dict]):
        th = config["thresholds"]
        self.zones = zones
        self.requirements = {r["zone_type"]: list(r["items"]) for r in config.get("requirements", [])}
        self.severity = config.get("severity", {})
        self.min_conf = th["min_confidence"]
        self.min_visibility = th["min_visibility"]
        self.missing_ratio = th["missing_ratio"]
        self.window_ms = th["window_ms"]
        self.max_gap_ms = th["max_gap_ms"]
        self.grace_ms = th["grace_ms"]
        self.recovery_ms = th["recovery_ms"]
        self.cooldown_ms = th["cooldown_ms"]
        self.restricted_dwell_ms = th["restricted_dwell_ms"]
        self.unassessable_ms = th["unassessable_ms"]

        self.state: dict[str, TrackState] = {}
        self.tracks: dict[str, dict] = {}
        self.events: dict[tuple, dict] = {}
        self._dirty_tracks: set[str] = set()
        self._dirty_events: set[tuple] = set()
        self._segments: list[dict] = []
        self._assessments: list[dict] = []
        self.newly_closed: list[tuple] = []

    # ---- frame input -------------------------------------------------

    def observe(self, ts: int, track_key: str, det: Detection) -> dict | None:
        st = self.state.setdefault(track_key, TrackState())
        track = self.tracks.setdefault(track_key, {"track_key": track_key, "first_ms": ts, "last_ms": ts})
        track["last_ms"] = ts
        self._dirty_tracks.add(track_key)

        zone = zone_for(det.bbox, self.zones)
        zone_type = zone["type"] if zone else None
        self._segment(st, track_key, ts, zone, det.score)

        required = self.requirements.get(zone_type, []) if zone_type else []
        for item in [i for i in st.items if i not in required]:
            self._drop_item(st, track_key, item, ts)

        states = {}
        for item in required:
            obs = self._normalise(item, det.ppe.get(item))
            states[item] = obs
            self._assess(st, track_key, item, ts, obs)

        self._ppe_episode(st, track_key, ts, det, zone, states)
        self._unassessable(st, track_key, ts, det, zone, states)
        self._restricted(st, track_key, ts, det, zone)
        return zone

    def lost(self, track_key: str, ts: int) -> None:
        self._close_track(track_key, ts, "observation_lost")

    def finish(self, ts: int) -> None:
        for key in list(self.state):
            self._close_track(key, ts, "closed")

    # ---- output ------------------------------------------------------

    def drain(self) -> dict:
        out = {
            "tracks": [dict(self.tracks[k]) for k in sorted(self._dirty_tracks)],
            "activity_segments": self._segments,
            "ppe_assessments": self._assessments,
            "events": [self.public_event(self.events[k]) for k in sorted(self._dirty_events, key=lambda k: (k[2], k[0], k[1]))],
        }
        self._dirty_tracks, self._dirty_events = set(), set()
        self._segments, self._assessments = [], []
        return out

    @staticmethod
    def public_event(event: dict) -> dict:
        return {k: v for k, v in event.items() if not k.startswith("_")}

    # ---- intervals ---------------------------------------------------

    def _segment(self, st: TrackState, key: str, ts: int, zone: dict | None, score: float) -> None:
        label = f"in_{zone['type']}_zone" if zone else "outside_zones"
        zone_id = zone["id"] if zone else None
        seg = st.segment
        if seg and seg["label"] == label and seg["zone_id"] == zone_id and ts - seg["end_ms"] <= self.max_gap_ms:
            seg["end_ms"] = ts
            seg["_scores"].append(score)
            return
        self._close_segment(st)
        st.segment = {"track_key": key, "zone_id": zone_id, "label": label, "start_ms": ts, "end_ms": ts, "_scores": [score]}

    def _close_segment(self, st: TrackState) -> None:
        if st.segment:
            seg = st.segment
            scores = seg.pop("_scores")
            seg["score"] = round(sum(scores) / len(scores), 4)
            seg["evidence"] = {"samples": len(scores)}
            self._segments.append(seg)
            st.segment = None

    def _normalise(self, item: str, obs: PpeObservation | None) -> PpeObservation:
        if obs is None:
            return PpeObservation("unknown", 0.0, 0.0, "not_reported")
        if obs.state != "unknown" and (obs.visibility < self.min_visibility or obs.score < self.min_conf.get(item, 0.5)):
            return PpeObservation("unknown", obs.score, obs.visibility, "low_confidence")
        return obs

    def _assess(self, st: TrackState, key: str, item: str, ts: int, obs: PpeObservation) -> None:
        it = st.items.setdefault(item, ItemState())
        iv = it.interval
        if iv and iv["state"] == obs.state and ts - iv["end_ms"] <= self.max_gap_ms:
            iv["end_ms"] = ts
            iv["_scores"].append(obs.score)
            iv["_vis"].append(obs.visibility)
        else:
            self._close_interval(it)
            it.interval = {
                "track_key": key, "item_code": item, "required": True, "state": obs.state,
                "start_ms": ts, "end_ms": ts, "reason": obs.reason, "_scores": [obs.score], "_vis": [obs.visibility],
            }

        it.window.append((ts, obs.state))
        while it.window and ts - it.window[0][0] > self.window_ms:
            it.window.popleft()

        if obs.state == "missing":
            it.missing_since = it.missing_since if it.missing_since is not None else ts
            it.present_since = None
        elif obs.state == "present":
            it.present_since = it.present_since if it.present_since is not None else ts
            it.missing_since = None

    def _close_interval(self, it: ItemState) -> None:
        if it.interval:
            iv = it.interval
            scores, vis = iv.pop("_scores"), iv.pop("_vis")
            iv["score"] = round(sum(scores) / len(scores), 4)
            iv["visibility"] = round(sum(vis) / len(vis), 4)
            self._assessments.append(iv)
            it.interval = None

    def _drop_item(self, st: TrackState, key: str, item: str, ts: int) -> None:
        self._close_interval(st.items.pop(item))
        st.ppe_active.discard(item)
        if st.ppe_event and not st.ppe_active:
            self._close_event(st.ppe_event, "closed")
            st.cooldown["ppe_missing"] = ts + self.cooldown_ms
            st.ppe_event = None

    # ---- episodes ----------------------------------------------------

    def _confirmed_missing(self, it: ItemState, ts: int) -> bool:
        if it.missing_since is None or ts - it.missing_since < self.grace_ms:
            return False
        assessable = [s for _, s in it.window if s != "unknown"]
        return bool(assessable) and assessable.count("missing") / len(assessable) >= self.missing_ratio

    def _ppe_episode(self, st: TrackState, key: str, ts: int, det: Detection, zone: dict | None, states: dict) -> None:
        confirmed = {item for item, it in st.items.items() if self._confirmed_missing(it, ts)}

        if st.ppe_event is None:
            if confirmed and ts >= st.cooldown.get("ppe_missing", -1):
                start = min(st.items[i].missing_since for i in confirmed)
                st.ppe_event = self._open_event("ppe_missing", "violation", self.severity.get("ppe_missing", "medium"), key, start, ts, det, zone, items=sorted(confirmed))
                st.ppe_active = set(confirmed)
            return

        event = self.events[st.ppe_event]
        for item in confirmed - st.ppe_active:
            st.ppe_active.add(item)
            event["evidence"]["items"] = sorted(set(event["evidence"]["items"]) | {item})
        for item in list(st.ppe_active):
            it = st.items.get(item)
            if it and it.present_since is not None and ts - it.present_since >= self.recovery_ms:
                st.ppe_active.discard(item)

        if any(states.get(i) and states[i].state == "missing" for i in st.ppe_active):
            missing_scores = [states[i].score for i in st.ppe_active if states.get(i) and states[i].state == "missing"]
            self._extend(st.ppe_event, ts, det, max(missing_scores))

        if not st.ppe_active:
            self._close_event(st.ppe_event, "closed")
            st.cooldown["ppe_missing"] = ts + self.cooldown_ms
            st.ppe_event = None

    def _unassessable(self, st: TrackState, key: str, ts: int, det: Detection, zone: dict | None, states: dict) -> None:
        # A model that cannot detect an item at all is a coverage gap, not a case for human review.
        unknown = [i for i, o in states.items() if o.state == "unknown" and o.reason != "unsupported"]
        if not unknown:
            st.unknown_since = None
            if st.unassessable_event:
                self._close_event(st.unassessable_event, "closed")
                st.cooldown["unassessable_ppe"] = ts + self.cooldown_ms
                st.unassessable_event = None
            return

        st.unknown_since = st.unknown_since if st.unknown_since is not None else ts
        if st.unassessable_event:
            self._extend(st.unassessable_event, ts, det, None)
        elif ts - st.unknown_since >= self.unassessable_ms and ts >= st.cooldown.get("unassessable_ppe", -1):
            reasons = sorted({states[i].reason or "unknown" for i in unknown})
            st.unassessable_event = self._open_event(
                "unassessable_ppe", "review_needed", "review_needed", key, st.unknown_since, ts, det, zone,
                items=sorted(unknown), reasons=reasons,
            )

    def _restricted(self, st: TrackState, key: str, ts: int, det: Detection, zone: dict | None) -> None:
        if not zone or zone["type"] != "restricted":
            st.restricted_since = None
            if st.restricted_event:
                self._close_event(st.restricted_event, "closed")
                st.cooldown["restricted_zone"] = ts + self.cooldown_ms
                st.restricted_event = None
            return

        st.restricted_since = st.restricted_since if st.restricted_since is not None else ts
        if st.restricted_event:
            self._extend(st.restricted_event, ts, det, det.score)
        elif ts - st.restricted_since >= self.restricted_dwell_ms and ts >= st.cooldown.get("restricted_zone", -1):
            st.restricted_event = self._open_event(
                "restricted_zone", "violation", self.severity.get("restricted_zone", "high"), key, st.restricted_since, ts, det, zone,
            )

    def _open_event(self, type_: str, category: str, severity: str, key: str, start: int, ts: int, det: Detection, zone: dict | None, **extra) -> tuple:
        ek = (type_, key, start)
        self.events[ek] = {
            "type": type_, "category": category, "severity": severity, "lifecycle": "active",
            "track_key": key, "episode_start_ms": start, "first_ms": start, "last_ms": ts,
            "peak_score": round(det.score, 4),
            "evidence": {
                "bbox_xyxy_normalized": [round(v, 5) for v in det.bbox],
                "zone_id": zone["id"] if zone else None,
                "zone_name": zone["name"] if zone else None,
                **extra,
            },
            "_peak_ts": ts, "_peak_bbox": det.bbox,
        }
        self._dirty_events.add(ek)
        return ek

    def _extend(self, ek: tuple, ts: int, det: Detection, score: float | None) -> None:
        event = self.events[ek]
        event["last_ms"] = max(event["last_ms"], ts)
        if score is not None and score >= (event["peak_score"] or 0):
            event["peak_score"] = round(score, 4)
            event["_peak_ts"], event["_peak_bbox"] = ts, det.bbox
            event["evidence"]["bbox_xyxy_normalized"] = [round(v, 5) for v in det.bbox]
        self._dirty_events.add(ek)

    def _close_event(self, ek: tuple, lifecycle: str) -> None:
        self.events[ek]["lifecycle"] = lifecycle
        self._dirty_events.add(ek)
        self.newly_closed.append(ek)

    def _close_track(self, key: str, ts: int, lifecycle: str) -> None:
        st = self.state.pop(key, None)
        if st is None:
            return
        self._close_segment(st)
        for it in st.items.values():
            self._close_interval(it)
        for ek in (st.ppe_event, st.unassessable_event, st.restricted_event):
            if ek:
                self._close_event(ek, lifecycle)
