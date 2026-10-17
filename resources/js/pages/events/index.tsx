import { Head, Link, router } from '@inertiajs/react';
import {
    CategoryBadge,
    EmptyState,
    NativeSelect,
    Page,
    PageHeader,
    Pagination,
    ReviewBadge,
    SeverityBadge,
    SimulationBadge,
    Table,
    Td,
    Th,
} from '@/components/kitchen/ui';
import {
    CATEGORY_LABELS,
    eventPlace,
    eventTypeLabel,
    formatDateTime,
    formatMs,
    REVIEW_LABELS,
    useTenant,
} from '@/lib/kitchen';
import events from '@/routes/events';
import type { AnomalyEvent, Paginated } from '@/types/kitchen';

type Filters = {
    type?: string;
    category?: string;
    review_status?: string;
    source_id?: string;
    simulation?: string;
    run_id?: string;
};

type Props = {
    events: Paginated<AnomalyEvent>;
    filters: Filters;
    sources: { id: string; name: string }[];
    types: string[];
};

export default function Events({
    events: page,
    filters,
    sources,
    types,
}: Props) {
    const { tenant } = useTenant();
    const apply = (next: Partial<Filters>) =>
        router.get(
            events.index().url,
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Anomali" />
            <Page>
                <PageHeader
                    title="Anomali"
                    description="Kandidat pelanggaran SOP dari AI. Setiap kandidat perlu ditinjau manusia sebelum dianggap pelanggaran."
                />

                <div className="flex flex-wrap gap-2">
                    <NativeSelect
                        className="w-52"
                        value={filters.type ?? ''}
                        onChange={(e) =>
                            apply({ type: e.target.value || undefined })
                        }
                    >
                        <option value="">Semua jenis</option>
                        {types.map((t) => (
                            <option key={t} value={t}>
                                {eventTypeLabel(t)}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        className="w-44"
                        value={filters.category ?? ''}
                        onChange={(e) =>
                            apply({ category: e.target.value || undefined })
                        }
                    >
                        <option value="">Semua kategori</option>
                        {Object.entries(CATEGORY_LABELS).map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        className="w-44"
                        value={filters.review_status ?? ''}
                        onChange={(e) =>
                            apply({
                                review_status: e.target.value || undefined,
                            })
                        }
                    >
                        <option value="">Semua status</option>
                        {Object.entries(REVIEW_LABELS).map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        className="w-48"
                        value={filters.source_id ?? ''}
                        onChange={(e) =>
                            apply({ source_id: e.target.value || undefined })
                        }
                    >
                        <option value="">Semua sumber</option>
                        {sources.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        className="w-48"
                        value={filters.simulation ?? 'all'}
                        onChange={(e) => apply({ simulation: e.target.value })}
                    >
                        <option value="all">Nyata + simulasi</option>
                        <option value="exclude">Hanya nyata</option>
                        <option value="only">Hanya simulasi</option>
                    </NativeSelect>
                </div>

                {page.data.length === 0 ? (
                    <EmptyState
                        title="Tidak ada anomali"
                        description="Ubah filter atau jalankan analisis video."
                    />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th className="w-24">Bukti</Th>
                                <Th>Jenis</Th>
                                <Th>Kategori</Th>
                                <Th>Sumber</Th>
                                <Th>Posisi video</Th>
                                <Th>Status</Th>
                                <Th>Dibuat</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {page.data.map((e) => (
                                <tr key={e.id} className="hover:bg-muted/40">
                                    <Td>
                                        <Link
                                            href={events.show(e.id)}
                                            className="block aspect-video w-24 overflow-hidden rounded-md bg-muted"
                                            aria-label={`Buka ${eventTypeLabel(e.type)}`}
                                        >
                                            {e.media?.[0] && (
                                                <img
                                                    src={
                                                        events.media({
                                                            event: e.id,
                                                            media: e.media[0]
                                                                .id,
                                                        }).url
                                                    }
                                                    alt=""
                                                    loading="lazy"
                                                    className="size-full object-cover"
                                                    data-test="event-thumb"
                                                />
                                            )}
                                        </Link>
                                    </Td>
                                    <Td>
                                        <Link
                                            href={events.show(e.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {eventTypeLabel(e.type)}
                                        </Link>
                                        <div className="mt-1 flex gap-1">
                                            {e.is_simulation && (
                                                <SimulationBadge />
                                            )}
                                            {e.track && (
                                                <span className="text-xs text-muted-foreground">
                                                    Orang {e.track.track_key}
                                                </span>
                                            )}
                                        </div>
                                    </Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-1">
                                            <CategoryBadge
                                                category={e.category}
                                            />
                                            {e.category === 'violation' && (
                                                <SeverityBadge
                                                    severity={e.severity}
                                                />
                                            )}
                                        </div>
                                    </Td>
                                    <Td>
                                        {eventPlace(e)}
                                        <p className="max-w-48 truncate text-xs text-muted-foreground">
                                            {e.run?.video?.original_name}
                                        </p>
                                    </Td>
                                    <Td className="font-mono">
                                        {formatMs(e.first_ms)}
                                    </Td>
                                    <Td>
                                        <ReviewBadge status={e.review_status} />
                                    </Td>
                                    <Td className="whitespace-nowrap text-muted-foreground">
                                        {formatDateTime(
                                            e.created_at,
                                            tenant?.timezone,
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
                <Pagination page={page} />
            </Page>
        </>
    );
}

Events.layout = {
    breadcrumbs: [{ title: 'Anomali', href: events.index() }],
};
