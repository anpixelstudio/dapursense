import { usePage } from '@inertiajs/react';
import type {
    ReviewStatus,
    RunStatus,
    VideoStatus,
    ZoneType,
} from '@/types/kitchen';

export const ZONE_TYPES: { value: ZoneType; label: string; color: string }[] = [
    { value: 'prep', label: 'Prep', color: '#2563eb' },
    { value: 'stove', label: 'Stove', color: '#ea580c' },
    { value: 'sink', label: 'Sink', color: '#0891b2' },
    { value: 'cleaning', label: 'Cleaning', color: '#16a34a' },
    { value: 'restricted', label: 'Restricted', color: '#dc2626' },
];

export const zoneColor = (type: string): string =>
    ZONE_TYPES.find((z) => z.value === type)?.color ?? '#6b7280';

export const zoneLabel = (type: string): string =>
    ZONE_TYPES.find((z) => z.value === type)?.label ?? type;

const ACTIVITY_LABELS: Record<string, string> = {
    in_prep_zone: 'In prep zone',
    in_stove_zone: 'In stove zone',
    in_sink_zone: 'In sink zone',
    in_cleaning_zone: 'In cleaning zone',
    in_restricted_zone: 'In restricted area',
    outside_zones: 'Outside zones',
    cooking: 'Cooking',
    preparing: 'Preparing',
    washing: 'Washing',
    cleaning: 'Cleaning',
    serving: 'Serving',
    stationary: 'Stationary',
    unknown: 'Unknown',
};

export const activityLabel = (label: string): string =>
    ACTIVITY_LABELS[label] ?? label;

const EVENT_TYPES: Record<string, string> = {
    ppe_missing: 'PPE not detected',
    restricted_zone: 'Entered restricted area',
    unassessable_ppe: 'PPE cannot be assessed',
    source_unreadable: 'Video unreadable',
    observation_lost: 'Observation lost',
    analysis_stalled: 'Analysis stalled',
    device_offline: 'Device offline',
};

export const eventTypeLabel = (type: string): string =>
    EVENT_TYPES[type] ?? type;

const DEVICE_ERRORS: Record<string, string> = {
    refused: 'Connection refused',
    timeout: 'No response',
    dns: 'Host not found',
    no_heartbeat: 'Heartbeat stopped',
    unavailable: 'Service unavailable',
    storage_full: 'Storage full',
};

export const deviceErrorLabel = (code: string | null | undefined): string =>
    code ? (DEVICE_ERRORS[code] ?? code) : '-';

export const eventPlace = (event: {
    source?: { name: string } | null;
    evidence?: Record<string, unknown>;
}): string =>
    event.source?.name ?? (event.evidence?.device_name as string) ?? '-';

const PPE_ITEMS: Record<string, string> = {
    hair_cover: 'Hair cover',
    apron: 'Apron',
    glove: 'Gloves',
    mask: 'Mask',
};

export const ppeLabel = (item: string): string => PPE_ITEMS[item] ?? item;

const PPE_REASONS: Record<string, string> = {
    unsupported: 'model does not detect this PPE',
    low_confidence: 'low confidence',
    occluded: 'occluded',
    not_reported: 'not reported by model',
};

export const ppeReasonLabel = (reason: string): string =>
    PPE_REASONS[reason] ?? reason;

const LIFECYCLE_LABELS: Record<string, string> = {
    active: 'In progress',
    closed: 'Closed',
    observation_lost: 'Person lost from observation',
};

export const lifecycleLabel = (lifecycle: string): string =>
    LIFECYCLE_LABELS[lifecycle] ?? lifecycle;

export const ruleRevisionLabel = (
    revision?: { version: number; rule_set?: { name: string } | null } | null,
): string =>
    revision
        ? `${revision.rule_set?.name ?? 'Rules'} (rev ${revision.version})`
        : '-';

export const CATEGORY_LABELS: Record<string, string> = {
    violation: 'Violation',
    system: 'System',
    review_needed: 'Needs review',
};

export const REVIEW_LABELS: Record<ReviewStatus, string> = {
    OPEN: 'Open',
    ACKNOWLEDGED: 'Acknowledged',
    CONFIRMED: 'Confirmed',
    DISMISSED: 'Dismissed',
    RESOLVED: 'Resolved',
};

export const DISMISS_LABELS: Record<string, string> = {
    false_detection: 'False detection',
    not_applicable: 'Not applicable',
    duplicate: 'Duplicate',
    other: 'Other',
};

export const RUN_LABELS: Record<RunStatus, string> = {
    QUEUED: 'Queued',
    RUNNING: 'Running',
    SUCCEEDED: 'Succeeded',
    FAILED: 'Failed',
    CANCELLED: 'Cancelled',
};

export const VIDEO_LABELS: Record<VideoStatus, string> = {
    VALIDATING: 'Validating',
    READY: 'Ready',
    REJECTED: 'Rejected',
};

const AUDIT_ACTIONS: Record<string, string> = {
    'tenant.registered': 'Organization registered',
    'property.created': 'Property created',
    'property.updated': 'Property updated',
    'property.deleted': 'Property deleted',
    'kitchen.created': 'Kitchen created',
    'kitchen.updated': 'Kitchen updated',
    'kitchen.deleted': 'Kitchen deleted',
    'source.created': 'Camera added',
    'source.updated': 'Camera updated',
    'source.deleted': 'Camera removed',
    'zone.created': 'Zone created',
    'zone.updated': 'Zone updated',
    'zone.deactivated': 'Zone deactivated',
    'zone.duplicated': 'Zone duplicated',
    'video.uploaded': 'Video uploaded',
    'video.updated': 'Video updated',
    'video.deleted': 'Video deleted',
    'analysis.requested': 'Analysis started',
    'analysis.cancel_requested': 'Analysis cancelled',
    'event.reviewed': 'Anomaly reviewed',
    'event.hold_set': 'Evidence hold set',
    'event.hold_released': 'Evidence hold released',
    'rules.created': 'Rule set created',
    'rules.published': 'Rules published',
    'report.requested': 'Report requested',
    'report.downloaded': 'Report downloaded',
    'report.schedule_created': 'Report schedule created',
    'report.schedule_deleted': 'Report schedule deleted',
    'member.invited': 'Member invited',
    'member.invitation_revoked': 'Invitation revoked',
    'member.joined': 'Member joined',
    'member.updated': 'Member updated',
    'device.created': 'Device added',
    'device.updated': 'Device updated',
    'device.deleted': 'Device removed',
    'device.token_rotated': 'Device token rotated',
};

export const auditActionLabel = (action: string): string =>
    AUDIT_ACTIONS[action] ?? action;

const AUDIT_SUBJECTS: Record<string, string> = {
    Tenant: 'Organization',
    Property: 'Property',
    Kitchen: 'Kitchen',
    MediaSource: 'Camera',
    Zone: 'Zone',
    VideoAsset: 'Video',
    AnalysisRun: 'Analysis',
    AnomalyEvent: 'Anomaly',
    RuleSet: 'Rule set',
    Report: 'Report',
    ReportSchedule: 'Report schedule',
    Invitation: 'Invitation',
    TenantMembership: 'Membership',
    Device: 'Device',
};

export const auditSubjectLabel = (type: string | null): string => {
    const name = type?.split('\\').pop() ?? '';

    return AUDIT_SUBJECTS[name] ?? name;
};

export const ROLE_LABELS: Record<string, string> = {
    owner: 'Owner',
    supervisor: 'Supervisor',
    operator: 'Operator',
    viewer: 'Viewer',
};

export function formatMs(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) {
        return '-';
    }

    const total = Math.floor(ms / 1000);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = (n: number) => String(n).padStart(2, '0');

    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
}

export function formatDateTime(
    value: string | null | undefined,
    timezone?: string,
): string {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: timezone,
    }).format(new Date(value));
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(0)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export function formatPercent(ratio: number | null | undefined): string {
    return ratio === null || ratio === undefined
        ? '-'
        : `${(ratio * 100).toFixed(1)}%`;
}

export function useTenant() {
    const { tenant } = usePage().props;
    const can = (permission: string) =>
        tenant?.permissions.includes(permission) ?? false;

    return { tenant, can };
}

export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return (await response.json()) as T;
}
