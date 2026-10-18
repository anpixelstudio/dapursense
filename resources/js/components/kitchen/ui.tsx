import { Link, usePage } from '@inertiajs/react';
import {
    Children,
    isValidElement,
    useMemo,
    type ChangeEvent,
    type ComponentProps,
    type ReactElement,
    type ReactNode,
} from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    CATEGORY_LABELS,
    REVIEW_LABELS,
    RUN_LABELS,
    VIDEO_LABELS,
} from '@/lib/kitchen';
import { cn } from '@/lib/utils';
import type {
    Paginated,
    ReviewStatus,
    RunStatus,
    VideoStatus,
} from '@/types/kitchen';

/** Radix Select disallows empty string values — map "" ↔ sentinel. */
const EMPTY_VALUE = '__native_select_empty__';

type SelectOption = {
    value: string;
    label: ReactNode;
    disabled?: boolean;
};

function collectOptions(children: ReactNode): SelectOption[] {
    const out: SelectOption[] = [];

    Children.forEach(children, (child) => {
        if (!isValidElement(child)) {
            return;
        }

        const el = child as ReactElement<{
            value?: string | number;
            disabled?: boolean;
            children?: ReactNode;
        }>;

        if (el.type === 'optgroup') {
            out.push(...collectOptions(el.props.children));
            return;
        }

        if (el.type !== 'option') {
            return;
        }

        out.push({
            value:
                el.props.value === undefined || el.props.value === null
                    ? ''
                    : String(el.props.value),
            label: el.props.children,
            disabled: el.props.disabled,
        });
    });

    return out;
}

function toSelectValue(
    value: string | number | readonly string[] | undefined,
): string | undefined {
    if (value === undefined || value === null) {
        return undefined;
    }

    const raw = String(Array.isArray(value) ? value[0] : value);

    return raw === '' ? EMPTY_VALUE : raw;
}

export function PageHeader({
    title,
    description,
    actions,
    brand = false,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    /** Show product name eyebrow (VERA). */
    brand?: boolean | string;
}) {
    const { name } = usePage().props;
    const brandLabel =
        typeof brand === 'string' ? brand : brand ? String(name) : null;

    return (
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="space-y-1">
                {brandLabel && (
                    <p className="text-[11px] font-semibold tracking-[0.14em] text-primary uppercase">
                        {brandLabel}
                    </p>
                )}
                <h1 className="text-xl font-semibold tracking-tight">
                    {title}
                </h1>
                {description && (
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex items-center gap-2">{actions}</div>
            )}
        </div>
    );
}

export function Page({ children }: { children: ReactNode }) {
    return (
        <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">{children}</div>
    );
}

const tone = {
    neutral: 'border-transparent bg-muted text-muted-foreground',
    info: 'border-transparent bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    success:
        'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    warning:
        'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    danger: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    purple: 'border-transparent bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-200',
};

export type Tone = keyof typeof tone;

export function Pill({
    tone: t = 'neutral',
    className,
    ...props
}: ComponentProps<typeof Badge> & { tone?: Tone }) {
    return <Badge className={cn(tone[t], className)} {...props} />;
}

export function SimulationBadge() {
    return (
        <Pill tone="purple" title="Simulation result, not real detection">
            SIMULATION
        </Pill>
    );
}

const runTone: Record<RunStatus, Tone> = {
    QUEUED: 'neutral',
    RUNNING: 'info',
    SUCCEEDED: 'success',
    FAILED: 'danger',
    CANCELLED: 'warning',
};

export function RunStatusBadge({ status }: { status: RunStatus }) {
    return <Pill tone={runTone[status]}>{RUN_LABELS[status]}</Pill>;
}

const videoTone: Record<VideoStatus, Tone> = {
    VALIDATING: 'info',
    READY: 'success',
    REJECTED: 'danger',
};

export function VideoStatusBadge({ status }: { status: VideoStatus }) {
    return <Pill tone={videoTone[status]}>{VIDEO_LABELS[status]}</Pill>;
}

const reviewTone: Record<ReviewStatus, Tone> = {
    OPEN: 'warning',
    ACKNOWLEDGED: 'info',
    CONFIRMED: 'danger',
    DISMISSED: 'neutral',
    RESOLVED: 'success',
};

export function ReviewBadge({ status }: { status: ReviewStatus }) {
    return <Pill tone={reviewTone[status]}>{REVIEW_LABELS[status]}</Pill>;
}

export function SeverityBadge({ severity }: { severity: string }) {
    const t: Tone =
        severity === 'high'
            ? 'danger'
            : severity === 'medium'
              ? 'warning'
              : severity === 'review_needed'
                ? 'info'
                : 'neutral';
    const label: Record<string, string> = {
        high: 'High',
        medium: 'Medium',
        low: 'Low',
        review_needed: 'Needs review',
        system: 'System',
    };

    return <Pill tone={t}>{label[severity] ?? severity}</Pill>;
}

export function CategoryBadge({ category }: { category: string }) {
    const t: Tone =
        category === 'violation'
            ? 'danger'
            : category === 'system'
              ? 'neutral'
              : 'info';

    return <Pill tone={t}>{CATEGORY_LABELS[category] ?? category}</Pill>;
}

/**
 * Drop-in replacement for native &lt;select&gt; that renders shadcn/Radix Select.
 * Keep using &lt;option&gt; children — they are mapped to SelectItem automatically.
 */
export function NativeSelect({
    className,
    id,
    value,
    defaultValue,
    onChange,
    disabled,
    children,
    name,
    required,
}: ComponentProps<'select'>) {
    const options = useMemo(() => collectOptions(children), [children]);
    const controlled = value !== undefined;
    const selectValue = toSelectValue(value);
    const selectDefault = toSelectValue(
        defaultValue as string | number | undefined,
    );

    return (
        <Select
            value={controlled ? (selectValue ?? EMPTY_VALUE) : undefined}
            defaultValue={!controlled ? selectDefault : undefined}
            onValueChange={(next) => {
                const real = next === EMPTY_VALUE ? '' : next;
                onChange?.({
                    target: {
                        value: real,
                        name: name ?? '',
                        id: id ?? '',
                    },
                    currentTarget: {
                        value: real,
                        name: name ?? '',
                        id: id ?? '',
                    },
                } as ChangeEvent<HTMLSelectElement>);
            }}
            disabled={disabled}
            name={name}
            required={required}
        >
            <SelectTrigger
                id={id}
                size="default"
                className={cn('w-full min-w-0', className)}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent align="start" position="popper" className="max-h-72">
                {options.map((opt, index) => (
                    <SelectItem
                        key={`${opt.value || 'empty'}-${index}`}
                        value={opt.value === '' ? EMPTY_VALUE : opt.value}
                        disabled={opt.disabled}
                    >
                        {opt.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export function Textarea({ className, ...props }: ComponentProps<'textarea'>) {
    return (
        <textarea
            className={cn(
                'flex min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30',
                className,
            )}
            {...props}
        />
    );
}

export function EmptyState({
    title,
    description,
    action,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-10 text-center">
            <p className="font-medium">{title}</p>
            {description && (
                <p className="max-w-md text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            {action}
        </div>
    );
}

export function Stat({
    label,
    value,
    hint,
    emphasize = false,
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    emphasize?: boolean;
}) {
    return (
        <div
            className={cn(
                'rounded-xl border bg-card p-4',
                emphasize && 'border-primary/25 bg-primary/[0.04]',
            )}
        >
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums tracking-tight">
                {value}
            </p>
            {hint && (
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}

export function Pagination<T>({ page }: { page: Paginated<T> }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav className="flex flex-wrap items-center gap-1">
            {page.links.map((link, i) =>
                link.url ? (
                    <Link
                        key={i}
                        href={link.url}
                        preserveScroll
                        className={cn(
                            'rounded-md border px-3 py-1 text-sm',
                            link.active && 'bg-primary text-primary-foreground',
                        )}
                    >
                        <span
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    </Link>
                ) : (
                    <span
                        key={i}
                        className="rounded-md px-3 py-1 text-sm text-muted-foreground"
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ),
            )}
        </nav>
    );
}

export function Table({ children }: { children: ReactNode }) {
    return (
        <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-sm">{children}</table>
        </div>
    );
}

export function Th({
    children,
    className,
}: {
    children?: ReactNode;
    className?: string;
}) {
    return (
        <th
            className={cn(
                'border-b bg-muted/50 px-3 py-2 text-left font-medium whitespace-nowrap text-muted-foreground',
                className,
            )}
        >
            {children}
        </th>
    );
}

export function Td({
    children,
    className,
}: {
    children?: ReactNode;
    className?: string;
}) {
    return (
        <td className={cn('border-b px-3 py-2 align-top', className)}>
            {children}
        </td>
    );
}

export function PageErrors({ keys }: { keys: string[] }) {
    const errors = usePage().props.errors as Record<string, string>;
    const messages = keys.map((k) => errors[k]).filter(Boolean);

    if (messages.length === 0) {
        return null;
    }

    return (
        <div className="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive">
            {messages.map((m) => (
                <p key={m}>{m}</p>
            ))}
        </div>
    );
}

export function Field({
    label,
    htmlFor,
    error,
    children,
    hint,
}: {
    label: string;
    htmlFor?: string;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <label htmlFor={htmlFor} className="text-sm font-medium">
                {label}
            </label>
            {children}
            {hint && !error && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}
