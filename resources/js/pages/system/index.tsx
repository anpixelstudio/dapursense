import { Head } from '@inertiajs/react';
import { Page, PageHeader, Pill, Table, Td, Th } from '@/components/kitchen/ui';
import {
    auditActionLabel,
    auditSubjectLabel,
    formatDateTime,
    useTenant,
} from '@/lib/kitchen';
import system from '@/routes/system';

type Installation = {
    id: string;
    operation: string;
    target_version: string;
    status: string;
    last_step: string | null;
    error_code: string | null;
    started_at: string;
    finished_at: string | null;
};

type Props = {
    status: {
        app_version: string;
        tagged_release: boolean;
        git_sha: string | null;
        installed: boolean;
        schema: {
            installed_version: string | null;
            installed_schema_hash: string | null;
            current_schema_hash: string;
            pending_migrations: string[];
            modified_migrations: string[];
        };
        services: {
            database: boolean;
            redis: boolean;
            storage_used_percent: number;
            storage_warning: boolean;
            storage_blocked: boolean;
            worker: {
                ok: boolean;
                contract_version?: number;
                queue_depth?: number;
                error?: string;
                [k: string]: unknown;
            } | null;
        };
        analysis_paused: boolean;
        analysis_ready: boolean;
        real_detection_model: boolean;
        last_installation: Installation | null;
    };
    releases: {
        app_version: string;
        git_sha: string | null;
        schema_hash: string;
        installed_at: string;
    }[];
    installations: Installation[];
    audit: {
        id: string;
        action: string;
        subject_type: string | null;
        created_at: string;
        actor?: { name: string } | null;
    }[];
    contractVersion: number;
    isPlatformAdmin: boolean;
};

function Check({
    ok,
    warning = false,
    label,
    detail,
}: {
    ok: boolean;
    warning?: boolean;
    label: string;
    detail?: string;
}) {
    return (
        <div className="flex items-center justify-between rounded-lg border px-3 py-2 text-sm">
            <span>{label}</span>
            <span className="flex items-center gap-2">
                {detail && (
                    <span className="text-xs text-muted-foreground">
                        {detail}
                    </span>
                )}
                <Pill tone={!ok ? 'danger' : warning ? 'warning' : 'success'}>
                    {!ok ? 'Bermasalah' : warning ? 'Peringatan' : 'OK'}
                </Pill>
            </span>
        </div>
    );
}

export default function System({
    status,
    releases,
    installations,
    audit,
    contractVersion,
    isPlatformAdmin,
}: Props) {
    const { tenant } = useTenant();
    const worker = status.services.worker;

    return (
        <>
            <Head title="Sistem" />
            <Page>
                <PageHeader
                    title="Sistem"
                    description={`Versi ${status.app_version}${status.tagged_release ? '' : ' (build pengembangan)'}${status.git_sha ? ` · ${status.git_sha.slice(0, 8)}` : ''}`}
                />

                {!status.real_detection_model && (
                    <p className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                        Belum ada model deteksi APD tervalidasi. Analisis nyata
                        hanya mendeteksi orang; penilaian APD akan berstatus
                        "tidak dapat dinilai". Gunakan mode simulasi untuk demo
                        alur.
                    </p>
                )}

                <div className="grid gap-3 md:grid-cols-2">
                    <Check
                        ok={status.services.database}
                        label="Database PostgreSQL"
                    />
                    <Check ok={status.services.redis} label="Redis (antrean)" />
                    <Check
                        ok={!status.services.storage_blocked}
                        warning={status.services.storage_warning}
                        label="Penyimpanan"
                        detail={`${status.services.storage_used_percent.toFixed(1)}% terpakai${status.services.storage_blocked ? ' · upload diblokir' : status.services.storage_warning ? ' · hampir penuh' : ''}`}
                    />
                    <Check
                        ok={worker?.ok ?? false}
                        label="Worker AI"
                        detail={
                            worker
                                ? `kontrak v${worker.contract_version ?? '?'} (app v${contractVersion})${worker.error ? ` · ${worker.error}` : ''}`
                                : 'tidak dicek'
                        }
                    />
                    <Check
                        ok={
                            status.installed &&
                            status.schema.pending_migrations.length === 0 &&
                            status.schema.modified_migrations.length === 0
                        }
                        label="Skema database"
                        detail={`terpasang ${status.schema.installed_version ?? '-'}`}
                    />
                    <Check
                        ok={status.analysis_ready}
                        label="Siap analisis"
                        detail={
                            status.analysis_paused
                                ? 'dijeda (upgrade)'
                                : undefined
                        }
                    />
                </div>

                {(status.schema.pending_migrations.length > 0 ||
                    status.schema.modified_migrations.length > 0) && (
                    <div className="rounded-lg border border-destructive/40 p-4 text-sm">
                        {status.schema.pending_migrations.length > 0 && (
                            <p>
                                Migrasi tertunda:{' '}
                                {status.schema.pending_migrations.join(', ')}.
                                Jalankan <code>php artisan system:upgrade</code>
                                .
                            </p>
                        )}
                        {status.schema.modified_migrations.length > 0 && (
                            <p className="text-destructive">
                                Migrasi yang sudah terpasang berubah:{' '}
                                {status.schema.modified_migrations.join(', ')}.
                            </p>
                        )}
                    </div>
                )}

                <section className="space-y-2">
                    <h2 className="font-medium">Riwayat rilis</h2>
                    <Table>
                        <thead>
                            <tr>
                                <Th>Versi</Th>
                                <Th>Git</Th>
                                <Th>Schema hash</Th>
                                <Th>Dipasang</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {releases.map((r) => (
                                <tr key={`${r.app_version}-${r.installed_at}`}>
                                    <Td>{r.app_version}</Td>
                                    <Td className="font-mono text-xs">
                                        {r.git_sha?.slice(0, 8) ?? '-'}
                                    </Td>
                                    <Td className="font-mono text-xs">
                                        {r.schema_hash.slice(0, 12)}
                                    </Td>
                                    <Td>
                                        {formatDateTime(
                                            r.installed_at,
                                            tenant?.timezone,
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </section>

                {isPlatformAdmin && installations.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">
                            Operasi install / upgrade
                        </h2>
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Operasi</Th>
                                    <Th>Versi</Th>
                                    <Th>Status</Th>
                                    <Th>Langkah terakhir</Th>
                                    <Th>Mulai</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {installations.map((i) => (
                                    <tr key={i.id}>
                                        <Td>{i.operation}</Td>
                                        <Td>{i.target_version}</Td>
                                        <Td>
                                            <Pill
                                                tone={
                                                    i.status === 'SUCCEEDED'
                                                        ? 'success'
                                                        : i.status === 'FAILED'
                                                          ? 'danger'
                                                          : 'info'
                                                }
                                            >
                                                {i.status}
                                            </Pill>
                                            {i.error_code && (
                                                <span className="ml-2 text-xs text-destructive">
                                                    {i.error_code}
                                                </span>
                                            )}
                                        </Td>
                                        <Td>{i.last_step ?? '-'}</Td>
                                        <Td>
                                            {formatDateTime(
                                                i.started_at,
                                                tenant?.timezone,
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </section>
                )}

                <section className="space-y-2">
                    <h2 className="font-medium">Audit log organisasi</h2>
                    <Table>
                        <thead>
                            <tr>
                                <Th>Waktu</Th>
                                <Th>Pelaku</Th>
                                <Th>Aksi</Th>
                                <Th>Objek</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {audit.map((a) => (
                                <tr key={a.id}>
                                    <Td className="whitespace-nowrap">
                                        {formatDateTime(
                                            a.created_at,
                                            tenant?.timezone,
                                        )}
                                    </Td>
                                    <Td>{a.actor?.name ?? 'Sistem'}</Td>
                                    <Td>
                                        <span title={a.action}>
                                            {auditActionLabel(a.action)}
                                        </span>
                                    </Td>
                                    <Td className="text-xs text-muted-foreground">
                                        {auditSubjectLabel(a.subject_type)}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </section>
            </Page>
        </>
    );
}

System.layout = {
    breadcrumbs: [{ title: 'Sistem', href: system.index() }],
};
