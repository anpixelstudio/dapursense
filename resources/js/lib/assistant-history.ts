export type ChatTurn = { role: 'user' | 'assistant'; content: string };

export type ChatThread = {
    id: string;
    title: string;
    updatedAt: string;
    turns: ChatTurn[];
};

const STORAGE_KEY = 'vera.assistant.chats.v1';
const LEGACY_STORAGE_KEY = 'dapursense.assistant.chats.v1';
const MAX_THREADS = 30;

function readAll(): ChatThread[] {
    try {
        const raw =
            localStorage.getItem(STORAGE_KEY) ??
            localStorage.getItem(LEGACY_STORAGE_KEY);
        if (!raw) {
            return [];
        }
        const parsed = JSON.parse(raw) as ChatThread[];
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function writeAll(threads: ChatThread[]): void {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(threads.slice(0, MAX_THREADS)));
}

export function listThreads(): ChatThread[] {
    return readAll().sort((a, b) => b.updatedAt.localeCompare(a.updatedAt));
}

export function getThread(id: string): ChatThread | null {
    return readAll().find((t) => t.id === id) ?? null;
}

export function createThread(seed?: Partial<ChatThread>): ChatThread {
    const thread: ChatThread = {
        id: crypto.randomUUID(),
        title: seed?.title ?? 'New chat',
        updatedAt: new Date().toISOString(),
        turns: seed?.turns ?? [],
    };
    writeAll([thread, ...readAll()]);
    return thread;
}

export function saveThread(thread: ChatThread): void {
    const others = readAll().filter((t) => t.id !== thread.id);
    writeAll([{ ...thread, updatedAt: new Date().toISOString() }, ...others]);
}

export function deleteThread(id: string): void {
    writeAll(readAll().filter((t) => t.id !== id));
}

export function titleFromTurns(turns: ChatTurn[]): string {
    const firstUser = turns.find((t) => t.role === 'user');
    if (!firstUser) {
        return 'New chat';
    }
    const text = firstUser.content.trim().replace(/\s+/g, ' ');
    return text.length > 48 ? `${text.slice(0, 48)}…` : text;
}
