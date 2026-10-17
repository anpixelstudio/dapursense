import { Head, Link } from '@inertiajs/react';
import {
    AlertCircle,
    Check,
    Copy,
    FileText,
    HelpCircle,
    Languages,
    MessageSquare,
    Plus,
    Send,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { AssistantMarkdown } from '@/components/assistant-markdown';
import { Pill, Textarea } from '@/components/kitchen/ui';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    createThread,
    deleteThread,
    listThreads,
    saveThread,
    titleFromTurns,
    type ChatThread,
    type ChatTurn,
} from '@/lib/assistant-history';
import {
    assistantCopy,
    loadAssistantLocale,
    saveAssistantLocale,
    type AssistantLocale,
} from '@/lib/assistant-i18n';
import { postAssistant } from '@/lib/assistant';
import { cn } from '@/lib/utils';
import assistant from '@/routes/assistant';
import events from '@/routes/events';

type Mode = 'chat' | 'report';
type ReportFocus = 'operations' | 'safety' | 'throughput';

export default function AssistantIndex({
    provider,
    model,
    ready,
}: {
    provider: string;
    model: string;
    ready: boolean;
}) {
    const [locale, setLocale] = useState<AssistantLocale>('en');
    const t = useMemo(() => assistantCopy(locale), [locale]);

    const [mode, setMode] = useState<Mode>('chat');
    const [threads, setThreads] = useState<ChatThread[]>([]);
    const [activeId, setActiveId] = useState<string | null>(null);
    const [history, setHistory] = useState<ChatTurn[]>([]);
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [helpOpen, setHelpOpen] = useState(false);

    const [focus, setFocus] = useState<ReportFocus>('operations');
    const [report, setReport] = useState<string | null>(null);
    const [reportBusy, setReportBusy] = useState(false);
    const [copied, setCopied] = useState(false);

    const chatEndRef = useRef<HTMLDivElement>(null);

    const empty = history.length === 0 && !busy;
    const modelLabel =
        provider === 'gemini'
            ? model.replace(/^gemini-/, 'Gemini ')
            : t.demoMode;

    const focusOptions: {
        value: ReportFocus;
        label: string;
        hint: string;
    }[] = [
        { value: 'operations', label: t.focusOps, hint: t.focusOpsHint },
        { value: 'safety', label: t.focusSafety, hint: t.focusSafetyHint },
        {
            value: 'throughput',
            label: t.focusThroughput,
            hint: t.focusThroughputHint,
        },
    ];

    const activeThread = useMemo(
        () => threads.find((th) => th.id === activeId) ?? null,
        [threads, activeId],
    );

    useEffect(() => {
        setLocale(loadAssistantLocale());
        const existing = listThreads();
        if (existing.length === 0) {
            const thread = createThread();
            setThreads([thread]);
            setActiveId(thread.id);
            return;
        }
        setThreads(existing);
        setActiveId(existing[0].id);
        setHistory(existing[0].turns);
    }, []);

    useEffect(() => {
        chatEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [history, busy, mode]);

    useEffect(() => {
        if (mode === 'chat') {
            document.getElementById('sop-message')?.focus();
        }
    }, [mode, activeId]);

    const changeLocale = (next: AssistantLocale) => {
        setLocale(next);
        saveAssistantLocale(next);
    };

    const persist = (turns: ChatTurn[], threadId: string | null = activeId) => {
        if (!threadId) {
            return;
        }
        const current = listThreads().find((th) => th.id === threadId);
        if (!current) {
            return;
        }
        const next: ChatThread = {
            ...current,
            turns,
            title: titleFromTurns(turns),
            updatedAt: new Date().toISOString(),
        };
        saveThread(next);
        setThreads(listThreads());
    };

    const startNewChat = () => {
        const thread = createThread();
        setThreads(listThreads());
        setActiveId(thread.id);
        setHistory([]);
        setError(null);
        setMode('chat');
    };

    const openThread = (id: string) => {
        const thread = listThreads().find((th) => th.id === id);
        if (!thread) {
            return;
        }
        setActiveId(id);
        setHistory(thread.turns);
        setError(null);
        setMode('chat');
    };

    const removeThread = (id: string) => {
        deleteThread(id);
        const remaining = listThreads();
        setThreads(remaining);
        if (remaining.length === 0) {
            const thread = createThread();
            setThreads([thread]);
            setActiveId(thread.id);
            setHistory([]);
            return;
        }
        if (activeId === id) {
            setActiveId(remaining[0].id);
            setHistory(remaining[0].turns);
        }
    };

    const sendChat = async (text: string) => {
        const nextHistory: ChatTurn[] = [
            ...history,
            { role: 'user', content: text },
        ];
        setHistory(nextHistory);
        setBusy(true);
        try {
            const data = await postAssistant<{ text: string }>(
                assistant.chat().url,
                {
                    message: text,
                    locale,
                    history: history.slice(-8),
                },
            );
            const withReply: ChatTurn[] = [
                ...nextHistory,
                { role: 'assistant', content: data.text },
            ];
            setHistory(withReply);
            persist(withReply);
        } catch (e) {
            setError(e instanceof Error ? e.message : t.requestFailed);
            persist(nextHistory);
        } finally {
            setBusy(false);
        }
    };

    const send = async (override?: string) => {
        const text = (override ?? message).trim();
        if (!text || busy || !ready || mode !== 'chat') {
            return;
        }
        setError(null);
        setMessage('');
        setHelpOpen(false);
        setMode('chat');
        await sendChat(text);
    };

    const generateReport = async () => {
        if (!ready || reportBusy) {
            return;
        }
        setError(null);
        setReportBusy(true);
        try {
            const data = await postAssistant<{ text: string }>(
                assistant.dailyReport().url,
                { focus, locale },
            );
            setReport(data.text);
        } catch (e) {
            setError(e instanceof Error ? e.message : t.requestFailed);
        } finally {
            setReportBusy(false);
        }
    };

    const copyReport = async () => {
        if (!report) {
            return;
        }
        try {
            await navigator.clipboard.writeText(report);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setError(t.requestFailed);
        }
    };

    const onComposerKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            void send();
        }
    };

    const switchMode = (next: Mode) => {
        setMode(next);
        setError(null);
    };

    return (
        <>
            <Head title={t.title} />
            <div className="flex h-[calc(100dvh-4rem)] min-h-0 flex-col gap-3 overflow-hidden p-4 md:p-5">
                <div className="flex shrink-0 flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <p className="text-[11px] font-semibold tracking-[0.14em] text-primary uppercase">
                            {t.brand}
                        </p>
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t.title}
                        </h1>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={locale}
                            onValueChange={(value) => {
                                if (value === 'en' || value === 'id') {
                                    changeLocale(value);
                                }
                            }}
                            aria-label="Language"
                        >
                            <ToggleGroupItem value="en" aria-label="English">
                                <Languages />
                                EN
                            </ToggleGroupItem>
                            <ToggleGroupItem value="id" aria-label="Indonesia">
                                ID
                            </ToggleGroupItem>
                        </ToggleGroup>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={mode}
                            onValueChange={(value) => {
                                if (value === 'chat' || value === 'report') {
                                    switchMode(value);
                                }
                            }}
                            aria-label="Assistant mode"
                        >
                            <ToggleGroupItem value="chat" aria-label="Chat">
                                <MessageSquare />
                                {t.chat}
                            </ToggleGroupItem>
                            <ToggleGroupItem
                                value="report"
                                aria-label="Report"
                                data-test="report-tab"
                            >
                                <FileText />
                                {t.report}
                            </ToggleGroupItem>
                        </ToggleGroup>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setHelpOpen(true)}
                            data-test="assistant-helper"
                        >
                            <HelpCircle />
                            {t.helper}
                        </Button>
                        <Pill
                            tone={
                                provider === 'gemini' ? 'success' : 'neutral'
                            }
                            data-test="ai-provider"
                        >
                            {modelLabel}
                        </Pill>
                    </div>
                </div>

                {!ready && (
                    <Alert className="shrink-0 py-2">
                        <AlertCircle />
                        <AlertTitle>{t.geminiMissingTitle}</AlertTitle>
                        <AlertDescription>
                            {t.geminiMissingBody}
                        </AlertDescription>
                    </Alert>
                )}

                {error && (
                    <Alert
                        variant="destructive"
                        className="shrink-0 py-2"
                        data-test="ai-error"
                    >
                        <AlertCircle />
                        <AlertTitle>{t.requestFailed}</AlertTitle>
                        <AlertDescription>{error}</AlertDescription>
                    </Alert>
                )}

                <div className="grid min-h-0 flex-1 gap-3 lg:grid-cols-[220px_minmax(0,1fr)]">
                    <Card className="flex min-h-0 flex-col gap-0 overflow-hidden py-0">
                        <CardHeader className="flex shrink-0 flex-row items-center justify-between space-y-0 border-b px-3 py-2.5">
                            <CardTitle className="text-sm">
                                {t.history}
                            </CardTitle>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={startNewChat}
                            >
                                <Plus />
                                {t.newChat}
                            </Button>
                        </CardHeader>
                        <CardContent className="min-h-0 flex-1 space-y-0.5 overflow-y-auto p-2">
                            {threads.map((thread) => {
                                const active =
                                    activeId === thread.id && mode === 'chat';
                                return (
                                    <div
                                        key={thread.id}
                                        className={cn(
                                            'group flex items-center gap-1 rounded-md px-2 py-1.5 text-sm',
                                            active
                                                ? 'bg-accent text-accent-foreground'
                                                : 'hover:bg-muted/60',
                                        )}
                                    >
                                        <button
                                            type="button"
                                            onClick={() =>
                                                openThread(thread.id)
                                            }
                                            className="min-w-0 flex-1 truncate text-left"
                                        >
                                            {thread.title || t.chatTitleFallback}
                                        </button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-7 opacity-0 group-hover:opacity-100"
                                            onClick={() =>
                                                removeThread(thread.id)
                                            }
                                            aria-label="Delete chat"
                                        >
                                            <Trash2 className="size-3.5" />
                                        </Button>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>

                    {mode === 'chat' ? (
                        <Card className="flex min-h-0 flex-col gap-0 overflow-hidden py-0">
                            <CardHeader className="shrink-0 space-y-0.5 border-b px-4 py-2.5 sm:px-5">
                                <CardTitle className="truncate text-base">
                                    {activeThread?.title ??
                                        t.chatTitleFallback}
                                </CardTitle>
                                <CardDescription className="truncate">
                                    {t.chatHint}
                                </CardDescription>
                            </CardHeader>

                            <CardContent className="min-h-0 flex-1 overflow-y-auto px-4 py-3 sm:px-5">
                                {empty ? (
                                    <div className="flex h-full flex-col items-center justify-center gap-4 text-center">
                                        <div className="space-y-1">
                                            <p className="font-medium">
                                                {t.emptyTitle}
                                            </p>
                                            <p className="max-w-sm text-sm text-muted-foreground">
                                                {t.emptyBody}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap justify-center gap-2">
                                            {(
                                                t.helpers.find((g) =>
                                                    /report|laporan|people|karyawan/i.test(
                                                        g.category,
                                                    ),
                                                )?.prompts ??
                                                t.helpers[0]?.prompts ??
                                                []
                                            )
                                                .slice(0, 4)
                                                .map((chip) => (
                                                    <Button
                                                        key={chip}
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={
                                                            !ready || busy
                                                        }
                                                        onClick={() =>
                                                            void send(chip)
                                                        }
                                                        data-test="sop-chip"
                                                        className="h-auto max-w-xs whitespace-normal px-3 py-1.5 text-left"
                                                    >
                                                        {chip}
                                                    </Button>
                                                ))}
                                        </div>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            size="sm"
                                            onClick={() => setHelpOpen(true)}
                                        >
                                            <HelpCircle />
                                            {t.helper}
                                        </Button>
                                    </div>
                                ) : (
                                    <div
                                        className="flex flex-col gap-3"
                                        data-test="sop-chat"
                                    >
                                        {history.map((turn, i) =>
                                            turn.role === 'user' ? (
                                                <div
                                                    key={`u-${i}`}
                                                    className="ml-auto max-w-[85%] rounded-lg bg-primary px-3 py-2 text-sm whitespace-pre-wrap text-primary-foreground"
                                                >
                                                    {turn.content}
                                                </div>
                                            ) : (
                                                <div
                                                    key={`a-${i}`}
                                                    className="mr-auto max-w-[90%] rounded-lg border bg-card px-3 py-2 shadow-xs"
                                                >
                                                    <AssistantMarkdown
                                                        content={turn.content}
                                                    />
                                                </div>
                                            ),
                                        )}
                                        {busy && (
                                            <div
                                                className="flex items-center gap-2 text-sm text-muted-foreground"
                                                data-test="sop-typing"
                                            >
                                                <Spinner />
                                                {t.thinking}
                                            </div>
                                        )}
                                        <div ref={chatEndRef} />
                                    </div>
                                )}
                            </CardContent>

                            <Separator className="shrink-0" />

                            <CardFooter className="shrink-0 flex-col items-stretch gap-1.5 px-4 py-3 sm:px-5">
                                <div className="flex gap-2">
                                    <Textarea
                                        id="sop-message"
                                        rows={1}
                                        value={message}
                                        placeholder={t.placeholder}
                                        disabled={busy || !ready}
                                        onChange={(e) =>
                                            setMessage(e.target.value)
                                        }
                                        onKeyDown={onComposerKeyDown}
                                        className="min-h-9 max-h-24 flex-1 resize-none py-2"
                                    />
                                    <Button
                                        type="button"
                                        disabled={
                                            busy || !message.trim() || !ready
                                        }
                                        onClick={() => void send()}
                                        data-test="sop-send"
                                        className="self-end"
                                    >
                                        {busy ? <Spinner /> : <Send />}
                                        {t.send}
                                    </Button>
                                </div>
                                <p className="text-[11px] text-muted-foreground">
                                    {t.enterHint}{' '}
                                    <Link
                                        href={events.index()}
                                        className="underline underline-offset-2 hover:text-foreground"
                                    >
                                        {t.summarizeLink}
                                    </Link>
                                </p>
                            </CardFooter>
                        </Card>
                    ) : (
                        <Card className="flex min-h-0 flex-col gap-0 overflow-hidden py-0">
                            <CardHeader className="shrink-0 space-y-0.5 border-b px-4 py-2.5 sm:px-5">
                                <CardTitle className="text-base">
                                    {t.reportTitle}
                                </CardTitle>
                                <CardDescription>
                                    {t.reportHint}
                                </CardDescription>
                            </CardHeader>

                            <CardContent className="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden px-4 py-3 sm:px-5">
                                <div className="grid shrink-0 gap-2 sm:grid-cols-3">
                                    {focusOptions.map((opt) => {
                                        const selected = focus === opt.value;
                                        return (
                                            <button
                                                key={opt.value}
                                                type="button"
                                                onClick={() =>
                                                    setFocus(opt.value)
                                                }
                                                className={cn(
                                                    'rounded-lg border px-3 py-2 text-left transition-colors',
                                                    selected
                                                        ? 'border-primary bg-primary/5'
                                                        : 'hover:bg-muted/50',
                                                )}
                                                data-test={`report-focus-${opt.value}`}
                                            >
                                                <p className="text-sm font-medium">
                                                    {opt.label}
                                                </p>
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {opt.hint}
                                                </p>
                                            </button>
                                        );
                                    })}
                                </div>

                                <div className="flex shrink-0 flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        disabled={!ready || reportBusy}
                                        onClick={() => void generateReport()}
                                        data-test="daily-report"
                                    >
                                        {reportBusy ? (
                                            <Spinner />
                                        ) : (
                                            <Sparkles />
                                        )}
                                        {reportBusy
                                            ? t.generating
                                            : t.generate}
                                    </Button>
                                    {report && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => void copyReport()}
                                            data-test="daily-report-copy"
                                        >
                                            {copied ? <Check /> : <Copy />}
                                            {copied ? t.copied : t.copy}
                                        </Button>
                                    )}
                                </div>

                                <div
                                    className="min-h-0 flex-1 overflow-y-auto rounded-lg border bg-card p-3 shadow-xs"
                                    data-test="daily-report-text"
                                >
                                    {reportBusy && !report ? (
                                        <div className="flex h-full min-h-24 flex-col items-center justify-center gap-2 text-muted-foreground">
                                            <Spinner className="size-5" />
                                            <p>{t.generating}</p>
                                        </div>
                                    ) : report ? (
                                        <AssistantMarkdown content={report} />
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            {t.reportEmpty}
                                        </p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>

            <Dialog open={helpOpen} onOpenChange={setHelpOpen}>
                <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t.helpTitle}</DialogTitle>
                        <DialogDescription>
                            {t.helpDescription}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        {t.helpers.map((group) => (
                            <div key={group.category} className="space-y-2">
                                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {group.category}
                                </p>
                                <div className="space-y-1.5">
                                    {group.prompts.map((prompt) => (
                                        <button
                                            key={prompt}
                                            type="button"
                                            disabled={!ready || busy}
                                            onClick={() => void send(prompt)}
                                            className="flex w-full items-start justify-between gap-3 rounded-lg border px-3 py-2.5 text-left text-sm transition hover:border-primary/40 hover:bg-primary/5 disabled:opacity-50"
                                        >
                                            <span>{prompt}</span>
                                            <span className="shrink-0 text-xs font-medium text-primary">
                                                {t.helpAsk}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setHelpOpen(false)}
                        >
                            {t.helpClose}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

AssistantIndex.layout = {
    breadcrumbs: [{ title: 'Assistant', href: assistant.index() }],
};
