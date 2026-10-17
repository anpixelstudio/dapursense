export type TenantShare = {
    id: string;
    name: string;
    timezone: string;
    role: 'owner' | 'supervisor' | 'operator' | 'viewer';
    permissions: string[];
};

export type Membership = { tenant_id: string; name: string; role: string };

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export type ZoneType = 'prep' | 'stove' | 'sink' | 'cleaning' | 'restricted';
export type Point = [number, number];

export type Zone = {
    id: string;
    source_id?: string;
    name: string;
    type: ZoneType;
    revision: number;
    points: Point[];
    active?: boolean;
};

export type Kitchen = {
    id: string;
    name: string;
    property_id: string;
    description?: string | null;
    sources_count?: number;
    property?: { id: string; name: string };
};

export type Property = {
    id: string;
    tenant_id: string;
    name: string;
    address: string | null;
    timezone: string;
    kitchens: Kitchen[];
};

export type Source = {
    id: string;
    name: string;
    type: string;
    status: 'active' | 'inactive';
    kitchen_id: string;
    kitchen?: Kitchen;
    videos_count?: number;
    zones_count?: number;
};

export type DeviceKind = 'camera' | 'nvr' | 'edge' | 'network' | 'server';

export type Device = {
    id: string;
    name: string;
    kind: DeviceKind;
    check_type: 'tcp' | 'heartbeat' | 'system';
    host: string | null;
    port: number | null;
    target: string | null;
    interval_seconds: number;
    failure_threshold: number;
    paused: boolean;
    status: 'unknown' | 'up' | 'down';
    consecutive_failures: number;
    last_latency_ms: number | null;
    last_error: string | null;
    last_checked_at: string | null;
    last_seen_at: string | null;
    status_changed_at: string | null;
    meta: Record<string, number | string> | null;
    kitchen?: Kitchen | null;
    source?: { id: string; name: string } | null;
    bars: { hour: string; uptime: number | null }[];
    uptime_24h: number | null;
    uptime_7d: number | null;
    uptime_30d: number | null;
    avg_latency_ms: number | null;
};

export type DeviceIncident = {
    id: string;
    device_id: string;
    event_id: string | null;
    started_at: string;
    ended_at: string | null;
    reason: string | null;
    device?: { id: string; name: string; kind: DeviceKind };
};

export type VideoStatus = 'VALIDATING' | 'READY' | 'REJECTED';

export type Video = {
    id: string;
    source_id: string;
    original_name: string;
    bytes: number;
    duration_ms: number | null;
    width: number | null;
    height: number | null;
    fps: number | null;
    codec: string | null;
    status: VideoStatus;
    error_code: string | null;
    error_message: string | null;
    recorded_at: string | null;
    recorded_timezone: string | null;
    original_expires_at: string | null;
    preview_key: string | null;
    created_at: string;
    runs_count?: number;
    source?: Source;
    uploader?: { id: number; name: string } | null;
};

export type AiModel = {
    id: string;
    name: string;
    version: string;
    runtime: string;
    validation_status: string;
    capabilities: {
        simulated?: boolean;
        ppe?: Record<string, boolean>;
        notes?: string;
    };
    scope?: string;
    license_ref?: string;
    sha256?: string;
    labels?: string[];
};

export type RunStatus =
    | 'QUEUED'
    | 'RUNNING'
    | 'SUCCEEDED'
    | 'FAILED'
    | 'CANCELLED';

export type Run = {
    id: string;
    status: RunStatus;
    mode: 'actual' | 'simulation';
    phase: string | null;
    /** 0–100 */
    progress: number;
    processed_ms: number;
    attempt: number;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
    cancel_requested_at: string | null;
    app_version?: string;
    result_manifest?: Record<string, unknown> | null;
    video?: Video;
    model?: AiModel;
    rule_revision?: {
        id: string;
        version: number;
        rule_set?: { id: string; name: string } | null;
    };
    requester?: { id: number; name: string } | null;
    source?: { id: string; name: string };
    input_snapshot?: { zones?: Zone[] };
};

export type ActivitySegment = {
    id: string;
    label: string;
    zone_id: string | null;
    start_ms: number;
    end_ms: number;
    score: number | null;
    track?: { id: string; track_key: string };
    zone?: { id: string; name: string; type: ZoneType } | null;
};

export type PpeAssessment = {
    id: string;
    item_code: string;
    state: 'present' | 'missing' | 'unknown';
    required: boolean;
    start_ms: number;
    end_ms: number;
    score: number | null;
    visibility: number | null;
    reason: string | null;
};

export type Track = {
    id: string;
    track_key: string;
    first_ms: number;
    last_ms: number;
    activity_segments: ActivitySegment[];
    ppe_assessments: PpeAssessment[];
};

export type ReviewStatus =
    | 'OPEN'
    | 'ACKNOWLEDGED'
    | 'CONFIRMED'
    | 'DISMISSED'
    | 'RESOLVED';

export type EventMedia = {
    id: string;
    kind: 'snapshot' | 'clip' | 'overlay';
    state: 'PENDING' | 'READY' | 'FAILED' | 'EXPIRED';
    start_ms: number | null;
    end_ms: number | null;
    error_code: string | null;
};

export type AnomalyEvent = {
    id: string;
    type: string;
    category: 'violation' | 'system' | 'review_needed';
    severity: string;
    review_status: ReviewStatus;
    lifecycle: string;
    revision: number;
    first_ms: number | null;
    last_ms: number | null;
    occurred_at: string | null;
    peak_score: number | null;
    is_simulation: boolean;
    evidence_hold: boolean;
    evidence: Record<string, unknown> & {
        bbox_xyxy_normalized?: number[];
        items?: string[];
        zone_name?: string;
    };
    created_at: string;
    last_reviewed_at: string | null;
    track_id: string | null;
    source?: { id: string; name: string } | null;
    track?: { id: string; track_key: string } | null;
    media?: EventMedia[];
    last_reviewer?: { id: number; name: string } | null;
    reviews?: EventReviewEntry[];
    run?: Run & { model?: AiModel; video?: Video };
};

export type EventReviewEntry = {
    id: string;
    from_status: ReviewStatus;
    to_status: ReviewStatus;
    reason_code: string | null;
    reason: string | null;
    action_note: string | null;
    event_revision: number;
    created_at: string;
    actor?: { id: number; name: string } | null;
};

export type ObservationFrame = {
    source_timestamp_ms: number;
    detections: {
        track_key: string;
        bbox: number[];
        score: number;
        zone_id: string | null;
        ppe: Record<string, string>;
    }[];
};
