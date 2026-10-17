import { usePage } from '@inertiajs/react';
import type {
    ReviewStatus,
    RunStatus,
    VideoStatus,
    ZoneType,
} from '@/types/kitchen';

export const ZONE_TYPES: { value: ZoneType; label: string; color: string }[] = [
    { value: 'prep', label: 'Persiapan', color: '#2563eb' },
    { value: 'stove', label: 'Kompor', color: '#ea580c' },
    { value: 'sink', label: 'Cuci', color: '#0891b2' },
    { value: 'cleaning', label: 'Kebersihan', color: '#16a34a' },
    { value: 'restricted', label: 'Area terlarang', color: '#dc2626' },
];

export const zoneColor = (type: string): string =>
    ZONE_TYPES.find((z) => z.value === type)?.color ?? '#6b7280';

export const zoneLabel = (type: string): string =>
    ZONE_TYPES.find((z) => z.value === type)?.label ?? type;

const ACTIVITY_LABELS: Record<string, string> = {
    in_prep_zone: 'Di zona persiapan',
    in_stove_zone: 'Di zona kompor',
    in_sink_zone: 'Di zona cuci',
    in_cleaning_zone: 'Di zona kebersihan',
    in_restricted_zone: 'Di area terlarang',
    outside_zones: 'Di luar zona',
    cooking: 'Memasak',
    preparing: 'Menyiapkan',
    washing: 'Mencuci',
    cleaning: 'Membersihkan',
    serving: 'Menyajikan',
    stationary: 'Diam',
    unknown: 'Tidak diketahui',
};

export const activityLabel = (label: string): string =>
    ACTIVITY_LABELS[label] ?? label;

const EVENT_TYPES: Record<string, string> = {
    ppe_missing: 'APD tidak terdeteksi',
    restricted_zone: 'Masuk area terlarang',
    unassessable_ppe: 'APD tidak dapat dinilai',
    source_unreadable: 'Video tidak terbaca',
    observation_lost: 'Pengamatan terputus',
    analysis_stalled: 'Analisis macet',
    device_offline: 'Perangkat offline',
};

export const eventTypeLabel = (type: string): string =>
    EVENT_TYPES[type] ?? type;

const DEVICE_ERRORS: Record<string, string> = {
    refused: 'Koneksi ditolak',
    timeout: 'Tidak merespons',
    dns: 'Host tidak ditemukan',
    no_heartbeat: 'Heartbeat berhenti',
    unavailable: 'Layanan tidak tersedia',
    storage_full: 'Penyimpanan penuh',
};

export const deviceErrorLabel = (code: string | null | undefined): string =>
    code ? (DEVICE_ERRORS[code] ?? code) : '-';

export const eventPlace = (event: {
    source?: { name: string } | null;
    evidence?: Record<string, unknown>;
}): string =>
    event.source?.name ?? (event.evidence?.device_name as string) ?? '-';

const PPE_ITEMS: Record<string, string> = {
    hair_cover: 'Penutup rambut',
    apron: 'Celemek',
    glove: 'Sarung tangan',
    mask: 'Masker',
};

export const ppeLabel = (item: string): string => PPE_ITEMS[item] ?? item;

const PPE_REASONS: Record<string, string> = {
    unsupported: 'model belum mendeteksi APD',
    low_confidence: 'keyakinan rendah',
    occluded: 'tertutup',
    not_reported: 'tidak dilaporkan model',
};

export const ppeReasonLabel = (reason: string): string =>
    PPE_REASONS[reason] ?? reason;

const LIFECYCLE_LABELS: Record<string, string> = {
    active: 'Masih berlangsung',
    closed: 'Selesai',
    observation_lost: 'Orang hilang dari pengamatan',
};

export const lifecycleLabel = (lifecycle: string): string =>
    LIFECYCLE_LABELS[lifecycle] ?? lifecycle;

export const ruleRevisionLabel = (
    revision?: { version: number; rule_set?: { name: string } | null } | null,
): string =>
    revision
        ? `${revision.rule_set?.name ?? 'Aturan'} (revisi ${revision.version})`
        : '-';

export const CATEGORY_LABELS: Record<string, string> = {
    violation: 'Pelanggaran',
    system: 'Sistem',
    review_needed: 'Perlu ditinjau',
};

export const REVIEW_LABELS: Record<ReviewStatus, string> = {
    OPEN: 'Terbuka',
    ACKNOWLEDGED: 'Diperiksa',
    CONFIRMED: 'Dikonfirmasi',
    DISMISSED: 'Ditolak',
    RESOLVED: 'Selesai',
};

export const DISMISS_LABELS: Record<string, string> = {
    false_detection: 'Deteksi keliru',
    not_applicable: 'Tidak berlaku',
    duplicate: 'Duplikat',
    other: 'Lainnya',
};

export const RUN_LABELS: Record<RunStatus, string> = {
    QUEUED: 'Antre',
    RUNNING: 'Berjalan',
    SUCCEEDED: 'Selesai',
    FAILED: 'Gagal',
    CANCELLED: 'Dibatalkan',
};

export const VIDEO_LABELS: Record<VideoStatus, string> = {
    VALIDATING: 'Sedang divalidasi',
    READY: 'Siap',
    REJECTED: 'Ditolak',
};

const AUDIT_ACTIONS: Record<string, string> = {
    'tenant.registered': 'Organisasi didaftarkan',
    'property.created': 'Outlet dibuat',
    'property.updated': 'Outlet diubah',
    'property.deleted': 'Outlet dihapus',
    'kitchen.created': 'Dapur dibuat',
    'kitchen.updated': 'Dapur diubah',
    'kitchen.deleted': 'Dapur dihapus',
    'source.created': 'Kamera ditambahkan',
    'source.updated': 'Kamera diubah',
    'source.deleted': 'Kamera dihapus',
    'zone.created': 'Zona dibuat',
    'zone.updated': 'Zona diubah',
    'zone.deactivated': 'Zona dinonaktifkan',
    'zone.duplicated': 'Zona disalin',
    'video.uploaded': 'Video diunggah',
    'video.updated': 'Video diubah',
    'video.deleted': 'Video dihapus',
    'analysis.requested': 'Analisis dijalankan',
    'analysis.cancel_requested': 'Analisis dibatalkan',
    'event.reviewed': 'Anomali ditinjau',
    'event.hold_set': 'Bukti ditahan',
    'event.hold_released': 'Penahanan bukti dilepas',
    'rules.created': 'Rule set dibuat',
    'rules.published': 'Aturan diterbitkan',
    'report.requested': 'Laporan diminta',
    'report.downloaded': 'Laporan diunduh',
    'report.schedule_created': 'Jadwal laporan dibuat',
    'report.schedule_deleted': 'Jadwal laporan dihapus',
    'member.invited': 'Anggota diundang',
    'member.invitation_revoked': 'Undangan dibatalkan',
    'member.joined': 'Anggota bergabung',
    'member.updated': 'Anggota diubah',
    'device.created': 'Perangkat ditambahkan',
    'device.updated': 'Perangkat diubah',
    'device.deleted': 'Perangkat dihapus',
    'device.token_rotated': 'Token perangkat diganti',
};

export const auditActionLabel = (action: string): string =>
    AUDIT_ACTIONS[action] ?? action;

const AUDIT_SUBJECTS: Record<string, string> = {
    Tenant: 'Organisasi',
    Property: 'Outlet',
    Kitchen: 'Dapur',
    MediaSource: 'Kamera',
    Zone: 'Zona',
    VideoAsset: 'Video',
    AnalysisRun: 'Analisis',
    AnomalyEvent: 'Anomali',
    RuleSet: 'Rule set',
    Report: 'Laporan',
    ReportSchedule: 'Jadwal laporan',
    Invitation: 'Undangan',
    TenantMembership: 'Keanggotaan',
    Device: 'Perangkat',
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

    return new Intl.DateTimeFormat('id-ID', {
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
