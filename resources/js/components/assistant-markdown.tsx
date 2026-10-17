import { useMemo, useState, type ReactNode } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';

/**
 * Lightweight safe markdown for kitchen Gen AI replies.
 * Supports: **bold**, *italic*, `code`, headings, bullets, numbered lists, todos (- [ ] / - [x]).
 */

function renderInline(text: string, keyPrefix: string): ReactNode[] {
    const nodes: ReactNode[] = [];
    const pattern = /(\*\*[^*]+\*\*|\*[^*]+\*|`[^`]+`)/g;
    let last = 0;
    let match: RegExpExecArray | null;
    let i = 0;

    while ((match = pattern.exec(text)) !== null) {
        if (match.index > last) {
            nodes.push(text.slice(last, match.index));
        }

        const token = match[0];
        const key = `${keyPrefix}-${i++}`;

        if (token.startsWith('**') && token.endsWith('**')) {
            nodes.push(
                <strong key={key} className="font-semibold text-foreground">
                    {token.slice(2, -2)}
                </strong>,
            );
        } else if (token.startsWith('*') && token.endsWith('*')) {
            nodes.push(<em key={key}>{token.slice(1, -1)}</em>);
        } else if (token.startsWith('`') && token.endsWith('`')) {
            nodes.push(
                <code
                    key={key}
                    className="rounded bg-muted px-1 py-0.5 font-mono text-[0.85em]"
                >
                    {token.slice(1, -1)}
                </code>,
            );
        } else {
            nodes.push(token);
        }

        last = match.index + token.length;
    }

    if (last < text.length) {
        nodes.push(text.slice(last));
    }

    return nodes;
}

type ListKind = 'ul' | 'ol' | 'todo';

function listKindOf(line: string): ListKind | null {
    if (/^\s*[-*+]\s+\[[ xX]\]\s+/.test(line)) {
        return 'todo';
    }
    if (/^\s*[-*•]\s+/.test(line)) {
        return 'ul';
    }
    if (/^\s*\d+[.)]\s+/.test(line)) {
        return 'ol';
    }
    return null;
}

function parseTodo(line: string): { checked: boolean; text: string } {
    const m = line.match(/^\s*[-*+]\s+\[([ xX])\]\s+(.*)$/);
    if (!m) {
        return { checked: false, text: line };
    }
    return {
        checked: m[1].toLowerCase() === 'x',
        text: m[2],
    };
}

function listItemText(line: string): string {
    return line.replace(/^\s*(?:[-*•]|\d+[.)])\s+/, '');
}

function headingLevel(line: string): number | null {
    const m = line.match(/^(#{1,3})\s+(.+)$/);
    return m ? m[1].length : null;
}

function TodoList({
    items,
    blockKey,
}: {
    items: { checked: boolean; text: string }[];
    blockKey: number;
}) {
    const [checked, setChecked] = useState(() => items.map((i) => i.checked));

    return (
        <ul className="my-2 space-y-1.5" data-test="assistant-todo-list">
            {items.map((item, idx) => (
                <li key={idx} className="flex items-start gap-2 text-sm">
                    <Checkbox
                        checked={checked[idx] ?? false}
                        onCheckedChange={(value) => {
                            setChecked((prev) => {
                                const next = [...prev];
                                next[idx] = value === true;
                                return next;
                            });
                        }}
                        className="mt-0.5"
                        aria-label={item.text}
                    />
                    <span
                        className={cn(
                            'leading-relaxed',
                            (checked[idx] ?? false) &&
                                'text-muted-foreground line-through',
                        )}
                    >
                        {renderInline(item.text, `todo-${blockKey}-${idx}`)}
                    </span>
                </li>
            ))}
        </ul>
    );
}

export function AssistantMarkdown({
    content,
    className,
}: {
    content: string;
    className?: string;
}) {
    const lines = useMemo(
        () => content.replace(/\r\n/g, '\n').split('\n'),
        [content],
    );

    const blocks: ReactNode[] = [];
    let i = 0;
    let block = 0;

    while (i < lines.length) {
        const line = lines[i];

        if (line.trim() === '') {
            i += 1;
            continue;
        }

        const heading = headingLevel(line);
        if (heading) {
            const text = line.replace(/^#{1,3}\s+/, '');
            const Tag = (`h${heading}` as 'h1' | 'h2' | 'h3');
            blocks.push(
                <Tag
                    key={`b-${block++}`}
                    className={cn(
                        'mt-2 mb-1 font-semibold tracking-tight first:mt-0',
                        heading === 1 && 'text-base',
                        heading === 2 && 'text-sm',
                        heading === 3 && 'text-sm',
                    )}
                >
                    {renderInline(text, `h-${block}`)}
                </Tag>,
            );
            i += 1;
            continue;
        }

        const kind = listKindOf(line);
        if (kind === 'todo') {
            const items: { checked: boolean; text: string }[] = [];
            while (i < lines.length && listKindOf(lines[i]) === 'todo') {
                items.push(parseTodo(lines[i]));
                i += 1;
            }
            blocks.push(
                <TodoList key={`b-${block}`} items={items} blockKey={block} />,
            );
            block += 1;
            continue;
        }

        if (kind === 'ul' || kind === 'ol') {
            const items: string[] = [];
            while (i < lines.length && listKindOf(lines[i]) === kind) {
                items.push(listItemText(lines[i]));
                i += 1;
            }
            const ListTag = kind;
            blocks.push(
                <ListTag
                    key={`b-${block++}`}
                    className={cn(
                        'my-1.5 space-y-1 pl-5 text-sm',
                        kind === 'ul' ? 'list-disc' : 'list-decimal',
                    )}
                >
                    {items.map((item, idx) => (
                        <li key={idx}>
                            {renderInline(item, `li-${block}-${idx}`)}
                        </li>
                    ))}
                </ListTag>,
            );
            continue;
        }

        const para: string[] = [line];
        i += 1;
        while (
            i < lines.length &&
            lines[i].trim() !== '' &&
            !listKindOf(lines[i]) &&
            !headingLevel(lines[i])
        ) {
            para.push(lines[i]);
            i += 1;
        }

        blocks.push(
            <p
                key={`b-${block++}`}
                className="my-1.5 text-sm leading-relaxed first:mt-0 last:mb-0"
            >
                {para.map((row, idx) => (
                    <span key={idx}>
                        {idx > 0 && <br />}
                        {renderInline(row, `p-${block}-${idx}`)}
                    </span>
                ))}
            </p>,
        );
    }

    return (
        <div
            className={cn(
                'text-foreground [&_strong]:font-semibold',
                className,
            )}
        >
            {blocks}
        </div>
    );
}
