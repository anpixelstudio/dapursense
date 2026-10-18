export type AssistantLocale = 'en' | 'id';

const LOCALE_KEY = 'vera.assistant.locale';

/** Always English — ID locale is retired. */
export function loadAssistantLocale(): AssistantLocale {
    return 'en';
}

export function saveAssistantLocale(_locale: AssistantLocale): void {
    if (typeof window === 'undefined') {
        return;
    }
    localStorage.setItem(LOCALE_KEY, 'en');
}

type Copy = {
    title: string;
    brand: string;
    chat: string;
    report: string;
    history: string;
    newChat: string;
    newThread: string;
    help: string;
    helper: string;
    chatTitleFallback: string;
    chatHint: string;
    emptyTitle: string;
    emptyBody: string;
    placeholder: string;
    send: string;
    enterHint: string;
    summarizeLink: string;
    reportTitle: string;
    reportHint: string;
    generate: string;
    generating: string;
    copy: string;
    copied: string;
    reportEmpty: string;
    thinking: string;
    geminiMissingTitle: string;
    geminiMissingBody: string;
    requestFailed: string;
    demoMode: string;
    helpTitle: string;
    helpDescription: string;
    helpClose: string;
    helpAsk: string;
    focusOps: string;
    focusOpsHint: string;
    focusSafety: string;
    focusSafetyHint: string;
    focusThroughput: string;
    focusThroughputHint: string;
    helpers: { category: string; prompts: string[] }[];
};

const EN: Copy = {
    title: 'Assistant',
    brand: 'VERA',
    chat: 'Chat',
    report: 'Report',
    history: 'History',
    newChat: 'New',
    newThread: 'New chat',
    help: 'Help',
    helper: 'Quick helper',
    chatTitleFallback: 'New chat',
    chatHint: 'SOP Q&A · jailbreak attempts are refused',
    emptyTitle: 'Kitchen helper ready',
    emptyBody:
        'Ask about SOP, PPE, or zones — or open Quick helper for ready-made questions.',
    placeholder: 'Ask about kitchen SOP…',
    send: 'Send',
    enterHint: 'Enter to send ·',
    summarizeLink: 'Summarize an anomaly',
    reportTitle: 'Daily operations report',
    reportHint: 'Locked prompt · stats only · no free-form input',
    generate: 'Generate report',
    generating: 'Generating…',
    copy: 'Copy',
    copied: 'Copied',
    reportEmpty: 'Choose a focus preset, then generate.',
    thinking: 'Thinking…',
    geminiMissingTitle: 'Gemini not connected',
    geminiMissingBody: 'Ask an admin to set GEMINI_API_KEY.',
    requestFailed: 'Request failed',
    demoMode: 'Demo mode',
    helpTitle: 'How can I help?',
    helpDescription:
        'Pick a ready question. Answers use your tenant SOP when available.',
    helpClose: 'Close',
    helpAsk: 'Ask this',
    focusOps: 'Operations',
    focusOpsHint: 'Balanced 7-day overview',
    focusSafety: 'Safety',
    focusSafetyHint: 'Restricted zones & open reviews',
    focusThroughput: 'Throughput',
    focusThroughputHint: 'People presence & analysed video',
    helpers: [
        {
            category: 'Reports & people',
            prompts: [
                'What reports do we have today?',
                'What reports did we have yesterday?',
                'What reports do we have this month?',
                'How many staff and people detected today?',
            ],
        },
        {
            category: 'SOP & PPE',
            prompts: [
                'Is an apron required in the cook zone?',
                'What PPE is required for food prep?',
                'Summarize our active SOP rules in a short checklist.',
            ],
        },
        {
            category: 'Zones',
            prompts: [
                'What counts as a restricted area?',
                'Who may enter the prep zone?',
                'Give me a todo list to audit restricted-zone cameras.',
            ],
        },
        {
            category: 'Review workflow',
            prompts: [
                'How should I review an open PPE anomaly?',
                'What is the difference between confirm and dismiss?',
                'Give me a checklist to clear OPEN reviews before service peak.',
            ],
        },
    ],
};

export function assistantCopy(_locale: AssistantLocale = 'en'): Copy {
    return EN;
}
