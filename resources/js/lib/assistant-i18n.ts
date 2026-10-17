export type AssistantLocale = 'en' | 'id';

const LOCALE_KEY = 'dapursense.assistant.locale';

export function loadAssistantLocale(): AssistantLocale {
    if (typeof window === 'undefined') {
        return 'en';
    }
    const raw = localStorage.getItem(LOCALE_KEY);
    return raw === 'id' ? 'id' : 'en';
}

export function saveAssistantLocale(locale: AssistantLocale): void {
    localStorage.setItem(LOCALE_KEY, locale);
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
    brand: 'Anpixel DapurSense',
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
        'Pick a ready question. Answers use your tenant SOP when available. Switch EN/ID anytime.',
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

const ID: Copy = {
    title: 'Asisten',
    brand: 'Anpixel DapurSense',
    chat: 'Chat',
    report: 'Laporan',
    history: 'Riwayat',
    newChat: 'Baru',
    newThread: 'Chat baru',
    help: 'Bantuan',
    helper: 'Helper cepat',
    chatTitleFallback: 'Chat baru',
    chatHint: 'Tanya jawab SOP · jailbreak ditolak',
    emptyTitle: 'Helper dapur siap',
    emptyBody:
        'Tanya SOP, APD, atau zona — atau buka Helper cepat untuk pertanyaan siap pakai.',
    placeholder: 'Tanya tentang SOP dapur…',
    send: 'Kirim',
    enterHint: 'Enter untuk kirim ·',
    summarizeLink: 'Ringkas anomali',
    reportTitle: 'Laporan operasional harian',
    reportHint: 'Prompt terkunci · hanya statistik · tanpa input bebas',
    generate: 'Buat laporan',
    generating: 'Membuat…',
    copy: 'Salin',
    copied: 'Tersalin',
    reportEmpty: 'Pilih fokus fokus, lalu buat laporan.',
    thinking: 'Memikirkan…',
    geminiMissingTitle: 'Gemini belum terhubung',
    geminiMissingBody: 'Minta admin mengatur GEMINI_API_KEY.',
    requestFailed: 'Permintaan gagal',
    demoMode: 'Mode demo',
    helpTitle: 'Ada yang bisa dibantu?',
    helpDescription:
        'Pilih pertanyaan siap pakai. Jawaban memakai SOP tenant bila tersedia. Ganti EN/ID kapan saja.',
    helpClose: 'Tutup',
    helpAsk: 'Tanyakan ini',
    focusOps: 'Operasional',
    focusOpsHint: 'Ringkasan seimbang 7 hari',
    focusSafety: 'Keselamatan',
    focusSafetyHint: 'Zona terlarang & review terbuka',
    focusThroughput: 'Throughput',
    focusThroughputHint: 'Kehadiran orang & video teranalisis',
    helpers: [
        {
            category: 'Laporan & karyawan',
            prompts: [
                'Ada report apa hari ini?',
                'Ada report apa kemarin?',
                'Ada report apa bulan ini?',
                'Berapa jumlah karyawan dan orang terdeteksi hari ini?',
            ],
        },
        {
            category: 'SOP & APD',
            prompts: [
                'Apakah celemek wajib di zona masak?',
                'APD apa yang wajib untuk persiapan makanan?',
                'Ringkas aturan SOP aktif kami dalam checklist singkat.',
            ],
        },
        {
            category: 'Zona',
            prompts: [
                'Apa yang termasuk area terlarang?',
                'Siapa yang boleh masuk zona prep?',
                'Buatkan todo list untuk audit kamera zona terlarang.',
            ],
        },
        {
            category: 'Alur review',
            prompts: [
                'Bagaimana cara mereview anomali APD yang masih OPEN?',
                'Apa bedanya konfirmasi dan abaikan (dismiss)?',
                'Buatkan checklist membersihkan review OPEN sebelum jam sibuk.',
            ],
        },
    ],
};

export function assistantCopy(locale: AssistantLocale): Copy {
    return locale === 'id' ? ID : EN;
}
