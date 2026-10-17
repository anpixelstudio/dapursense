import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    Field,
    NativeSelect,
    Page,
    PageHeader,
    Pill,
} from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime, ppeLabel, useTenant, ZONE_TYPES } from '@/lib/kitchen';
import rules from '@/routes/rules';
import type { AiModel, ZoneType } from '@/types/kitchen';

type RuleConfig = {
    sampling_fps: number;
    requirements: { zone_type: ZoneType; items: string[] }[];
    thresholds: Record<string, number> & {
        min_confidence: Record<string, number>;
    };
    severity: Record<string, string>;
};

type RuleSet = {
    id: string;
    name: string;
    active_revision: {
        id: string;
        version: number;
        checksum: string;
        config: RuleConfig;
        created_at: string;
    } | null;
    revisions: {
        id: string;
        version: number;
        checksum: string;
        created_at: string;
    }[];
};

type Props = {
    ruleSets: RuleSet[];
    models: AiModel[];
    template: RuleConfig;
    ppeItems: string[];
};

const THRESHOLDS: { key: string; label: string; unit: string }[] = [
    { key: 'grace_ms', label: 'Toleransi awal masuk zona', unit: 'ms' },
    { key: 'window_ms', label: 'Jendela evaluasi', unit: 'ms' },
    {
        key: 'missing_ratio',
        label: 'Rasio frame APD tidak terdeteksi',
        unit: '0.5–1',
    },
    {
        key: 'recovery_ms',
        label: 'Waktu pemulihan sebelum event ditutup',
        unit: 'ms',
    },
    {
        key: 'cooldown_ms',
        label: 'Jeda sebelum event baru orang yang sama',
        unit: 'ms',
    },
    { key: 'restricted_dwell_ms', label: 'Lama di area terlarang', unit: 'ms' },
    {
        key: 'unassessable_ms',
        label: 'Lama APD tidak dapat dinilai',
        unit: 'ms',
    },
    { key: 'min_visibility', label: 'Visibilitas minimum', unit: '0–1' },
    { key: 'max_gap_ms', label: 'Celah tracking maksimum', unit: 'ms' },
];

function RuleEditor({
    ruleSet,
    ppeItems,
    canEdit,
}: {
    ruleSet: RuleSet;
    ppeItems: string[];
    canEdit: boolean;
}) {
    const [config, setConfig] = useState<RuleConfig>(
        ruleSet.active_revision!.config,
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const requirementFor = (type: ZoneType) =>
        config.requirements.find((r) => r.zone_type === type)?.items ?? [];

    const toggleItem = (type: ZoneType, item: string) => {
        const current = requirementFor(type);
        const items = current.includes(item)
            ? current.filter((i) => i !== item)
            : [...current, item];
        const others = config.requirements.filter((r) => r.zone_type !== type);
        setConfig({
            ...config,
            requirements: items.length
                ? [...others, { zone_type: type, items }]
                : others,
        });
    };

    const publish = () =>
        router.post(
            rules.publish(ruleSet.id).url,
            { config },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (e) => setErrors(e),
                onSuccess: () => setErrors({}),
            },
        );

    return (
        <div className="space-y-4">
            <div>
                <p className="mb-2 text-sm font-medium">
                    APD wajib per jenis zona
                </p>
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm">
                        <thead>
                            <tr>
                                <th className="border-b px-3 py-2 text-left font-medium text-muted-foreground">
                                    Zona
                                </th>
                                {ppeItems.map((i) => (
                                    <th
                                        key={i}
                                        className="border-b px-3 py-2 font-medium text-muted-foreground"
                                    >
                                        {ppeLabel(i)}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {ZONE_TYPES.filter(
                                (z) => z.value !== 'restricted',
                            ).map((z) => (
                                <tr key={z.value}>
                                    <td className="border-b px-3 py-2">
                                        {z.label}
                                    </td>
                                    {ppeItems.map((i) => (
                                        <td
                                            key={i}
                                            className="border-b px-3 py-2 text-center"
                                        >
                                            <input
                                                type="checkbox"
                                                aria-label={`${z.label} ${ppeLabel(i)}`}
                                                disabled={!canEdit}
                                                checked={requirementFor(
                                                    z.value,
                                                ).includes(i)}
                                                onChange={() =>
                                                    toggleItem(z.value, i)
                                                }
                                            />
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                    Area terlarang selalu memicu event setelah orang berada di
                    dalamnya melebihi durasi minimum.
                </p>
            </div>

            <div className="grid gap-3 md:grid-cols-3">
                <Field
                    label="Sampling (frame/detik)"
                    htmlFor={`${ruleSet.id}-fps`}
                    error={errors['config.sampling_fps']}
                >
                    <Input
                        id={`${ruleSet.id}-fps`}
                        type="number"
                        step="0.5"
                        disabled={!canEdit}
                        value={config.sampling_fps}
                        onChange={(e) =>
                            setConfig({
                                ...config,
                                sampling_fps: Number(e.target.value),
                            })
                        }
                    />
                </Field>
                {THRESHOLDS.map((t) => (
                    <Field
                        key={t.key}
                        label={`${t.label} (${t.unit})`}
                        htmlFor={`${ruleSet.id}-${t.key}`}
                        error={errors[`config.thresholds.${t.key}`]}
                    >
                        <Input
                            id={`${ruleSet.id}-${t.key}`}
                            type="number"
                            step="any"
                            disabled={!canEdit}
                            value={config.thresholds[t.key]}
                            onChange={(e) =>
                                setConfig({
                                    ...config,
                                    thresholds: {
                                        ...config.thresholds,
                                        [t.key]: Number(e.target.value),
                                    },
                                })
                            }
                        />
                    </Field>
                ))}
                {['ppe_missing', 'restricted_zone'].map((type) => (
                    <Field
                        key={type}
                        label={`Tingkat ${type === 'ppe_missing' ? 'APD tidak terdeteksi' : 'area terlarang'}`}
                        htmlFor={`${ruleSet.id}-sev-${type}`}
                    >
                        <NativeSelect
                            id={`${ruleSet.id}-sev-${type}`}
                            disabled={!canEdit}
                            value={config.severity[type]}
                            onChange={(e) =>
                                setConfig({
                                    ...config,
                                    severity: {
                                        ...config.severity,
                                        [type]: e.target.value,
                                    },
                                })
                            }
                        >
                            <option value="low">Rendah</option>
                            <option value="medium">Sedang</option>
                            <option value="high">Tinggi</option>
                        </NativeSelect>
                    </Field>
                ))}
            </div>

            {Object.keys(errors).length > 0 && (
                <p className="text-sm text-destructive">
                    Periksa kembali nilai yang ditandai.
                </p>
            )}
            {canEdit && (
                <Button
                    onClick={publish}
                    disabled={processing}
                    data-test="publish-rules"
                >
                    Terbitkan revisi baru
                </Button>
            )}
            <p className="text-xs text-muted-foreground">
                Revisi tidak mengubah hasil analisis lama; setiap run menyimpan
                snapshot aturan yang dipakai. Nilai awal adalah titik mulai dan
                perlu dikalibrasi dengan video dapur Anda.
            </p>
        </div>
    );
}

export default function Rules({ ruleSets, models, ppeItems }: Props) {
    const { can, tenant } = useTenant();
    const canEdit = can('manage_rules');
    const form = useForm({ name: '' });

    return (
        <>
            <Head title="Aturan SOP & Model" />
            <Page>
                <PageHeader
                    title="Aturan SOP & Model"
                    description="Aturan menentukan APD wajib per zona dan ambang waktu sebelum kandidat pelanggaran dibuat."
                />

                {canEdit && (
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(rules.store().url, {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <Field
                            label="Rule set baru (dari template)"
                            htmlFor="ruleset-name"
                            error={form.errors.name}
                        >
                            <Input
                                id="ruleset-name"
                                className="w-72"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                        </Field>
                        <Button disabled={form.processing}>Buat</Button>
                    </form>
                )}

                {ruleSets.map((set) => (
                    <section
                        key={set.id}
                        className="space-y-4 rounded-xl border p-4"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-medium">{set.name}</h2>
                            {set.active_revision && (
                                <Pill tone="success">
                                    Aktif: revisi {set.active_revision.version}
                                </Pill>
                            )}
                            <span className="text-xs text-muted-foreground">
                                {set.revisions.length} revisi · terakhir{' '}
                                {formatDateTime(
                                    set.active_revision?.created_at,
                                    tenant?.timezone,
                                )}
                            </span>
                        </div>
                        {set.active_revision ? (
                            <RuleEditor
                                key={set.active_revision.id}
                                ruleSet={set}
                                ppeItems={ppeItems}
                                canEdit={canEdit}
                            />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Belum ada revisi.
                            </p>
                        )}
                    </section>
                ))}

                <section className="space-y-3">
                    <h2 className="font-medium">Model AI terdaftar</h2>
                    <div className="grid gap-3 md:grid-cols-2">
                        {models.map((m) => (
                            <div
                                key={m.id}
                                className="space-y-1 rounded-xl border p-4 text-sm"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {m.name} {m.version}
                                    </span>
                                    <Pill
                                        tone={
                                            m.capabilities.simulated
                                                ? 'purple'
                                                : 'info'
                                        }
                                    >
                                        {m.runtime}
                                    </Pill>
                                    <Pill>{m.validation_status}</Pill>
                                </div>
                                <p className="text-muted-foreground">
                                    {m.capabilities.notes}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    APD didukung:{' '}
                                    {Object.entries(m.capabilities.ppe ?? {})
                                        .filter(([, ok]) => ok)
                                        .map(([i]) => ppeLabel(i))
                                        .join(', ') ||
                                        'tidak ada (APD dinilai tidak dapat dinilai)'}
                                </p>
                                {m.license_ref && (
                                    <p className="text-xs text-muted-foreground">
                                        Lisensi: {m.license_ref}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                </section>
            </Page>
        </>
    );
}

Rules.layout = {
    breadcrumbs: [{ title: 'Aturan SOP & Model', href: rules.index() }],
};
