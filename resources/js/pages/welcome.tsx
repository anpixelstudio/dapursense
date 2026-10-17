import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login } from '@/routes';
import { register } from '@/routes';

/**
 * DapurSense marketing landing.
 * Dark event language (AI Builder Cup reference) + Anpixel orange accent.
 * Off-black surfaces, pill CTA, one full-bleed hero photo, no rainbow card soup.
 */

const STACK = ['Cloud Run', 'Cloud SQL', 'Gemini 2.5 Flash', 'YOLOX'];

const STEPS = [
    {
        title: 'Capture',
        body: 'Kitchen video enters the pipeline from CCTV or upload for analysis.',
    },
    {
        title: 'Detect',
        body: 'YOLOX tracks people and flags zone or SOP rule violations.',
    },
    {
        title: 'Assist',
        body: 'Gemini summarizes anomalies, answers SOP questions, and drafts daily reports.',
    },
];

function prefersReducedMotion(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export default function Welcome() {
    const { auth, name } = usePage().props;
    const [entered, setEntered] = useState(false);

    useEffect(() => {
        if (prefersReducedMotion()) {
            setEntered(true);
            return;
        }
        const id = requestAnimationFrame(() => setEntered(true));
        return () => cancelAnimationFrame(id);
    }, []);

    const primaryHref = auth.user ? dashboard() : login();
    const primaryLabel = auth.user ? 'Open dashboard' : 'Sign in';

    return (
        <>
            <Head title="DapurSense">
                <meta
                    name="description"
                    content="Anpixel DapurSense - kitchen intelligence with computer vision and Gemini Gen AI on Google Cloud."
                />
            </Head>

            <div className="min-h-[100dvh] bg-[#0b0d0f] text-[#f4f1ec] antialiased">
                <header className="absolute inset-x-0 top-0 z-30">
                    <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-5 sm:px-8">
                        <Link href="/" className="flex items-center gap-2.5">
                            <AppLogoIcon gradient className="h-7 w-auto" />
                            <span className="text-sm font-semibold tracking-tight">
                                {String(name)}
                            </span>
                        </Link>
                        <nav className="flex items-center gap-2 sm:gap-3">
                            <a
                                href="#how-it-works"
                                className="hidden rounded-full px-3 py-2 text-sm text-white/55 transition hover:text-white sm:inline"
                            >
                                How it works
                            </a>
                            <a
                                href="#stack"
                                className="hidden rounded-full px-3 py-2 text-sm text-white/55 transition hover:text-white md:inline"
                            >
                                Stack
                            </a>
                            {!auth.user && (
                                <Link
                                    href={register()}
                                    className="hidden rounded-full px-3 py-2 text-sm text-white/55 transition hover:text-white sm:inline"
                                >
                                    Register
                                </Link>
                            )}
                            <Link
                                href={primaryHref}
                                className="rounded-full bg-[#ef5023] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#f9a619] hover:text-[#0b0d0f] active:scale-[0.98]"
                            >
                                {primaryLabel}
                            </Link>
                        </nav>
                    </div>
                </header>

                <section className="relative flex min-h-[100dvh] items-end overflow-hidden">
                    <img
                        src="/images/dapursense-hero.jpg"
                        alt="Dark commercial kitchen with a CCTV camera"
                        className="absolute inset-0 size-full object-cover"
                        fetchPriority="high"
                    />
                    <div
                        className="absolute inset-0 bg-gradient-to-t from-[#0b0d0f] via-[#0b0d0f]/70 to-[#0b0d0f]/25"
                        aria-hidden
                    />

                    <div
                        className={`relative z-10 mx-auto w-full max-w-6xl px-5 pb-16 pt-24 sm:px-8 sm:pb-20 transition duration-700 ease-[cubic-bezier(0.16,1,0.3,1)] ${entered ? 'translate-y-0 opacity-100' : 'translate-y-5 opacity-0'}`}
                    >
                        <p className="mb-3 text-[11px] font-semibold tracking-[0.18em] text-[#f9a619] uppercase">
                            Anpixel DapurSense
                        </p>
                        <h1 className="max-w-3xl text-[clamp(2.5rem,8vw,5.5rem)] leading-[0.95] font-semibold tracking-[-0.04em]">
                            Kitchen intelligence
                            <span className="block text-white/55">
                                on Google Cloud
                            </span>
                        </h1>
                        <p className="mt-5 max-w-md text-base text-white/70 sm:text-lg">
                            YOLOX watches the floor. Gemini explains what
                            happened. Ready to demo on Cloud Run.
                        </p>
                        <div className="mt-8 flex flex-wrap gap-3">
                            <Link
                                href={primaryHref}
                                className="inline-flex rounded-full bg-[#ef5023] px-6 py-3 text-sm font-semibold text-white transition hover:bg-[#f9a619] hover:text-[#0b0d0f] active:scale-[0.98]"
                            >
                                {primaryLabel}
                            </Link>
                            <a
                                href="#how-it-works"
                                className="inline-flex rounded-full border border-white/20 px-6 py-3 text-sm font-medium text-white/85 transition hover:border-white/40 hover:bg-white/5 active:scale-[0.98]"
                            >
                                See how it works
                            </a>
                        </div>
                    </div>
                </section>

                <section
                    id="stack"
                    className="border-y border-white/10 bg-[#101317]"
                >
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-8 gap-y-3 px-5 py-5 sm:px-8">
                        <p className="text-xs text-white/40">Stack</p>
                        {STACK.map((item) => (
                            <p
                                key={item}
                                className="text-sm font-medium tracking-tight text-white/80"
                            >
                                {item}
                            </p>
                        ))}
                    </div>
                </section>

                <section className="mx-auto grid max-w-6xl gap-12 px-5 py-20 sm:px-8 lg:grid-cols-2 lg:gap-16 lg:py-28">
                    <div>
                        <h2 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                            Operators need answers, not just detection boxes.
                        </h2>
                    </div>
                    <div className="space-y-6 text-sm leading-relaxed text-white/60 sm:text-base">
                        <p>
                            Kitchen video creates technical anomalies that are
                            hard to read at speed. DapurSense combines computer
                            vision with Gen AI so events can be summarized,
                            queried against SOP, and reported in plain language.
                        </p>
                        <p>
                            Deploy target: Cloud Run + Cloud SQL Postgres +
                            Gemini 2.5 Flash - the pattern AI Builder Cup judges
                            look for under Technical Merit & Gen AI.
                        </p>
                    </div>
                </section>

                <section
                    id="how-it-works"
                    className="border-t border-white/10 bg-[#101317]"
                >
                    <div className="mx-auto max-w-6xl px-5 py-20 sm:px-8 lg:py-28">
                        <h2 className="max-w-xl text-3xl font-semibold tracking-tight sm:text-4xl">
                            From frame to decision
                        </h2>
                        <ol className="mt-14 grid gap-0 md:grid-cols-3">
                            {STEPS.map((step, i) => (
                                <li
                                    key={step.title}
                                    className={`border-white/10 py-8 md:pr-10 ${i > 0 ? 'border-t md:border-t-0 md:border-l md:pl-10' : ''}`}
                                >
                                    <p className="font-mono text-xs text-[#ef5023]">
                                        {String(i + 1).padStart(2, '0')}
                                    </p>
                                    <h3 className="mt-3 text-xl font-semibold tracking-tight">
                                        {step.title}
                                    </h3>
                                    <p className="mt-3 max-w-xs text-sm leading-relaxed text-white/55">
                                        {step.body}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </div>
                </section>

                <section className="border-t border-white/10">
                    <div className="mx-auto grid max-w-6xl items-center gap-12 px-5 py-20 sm:px-8 lg:grid-cols-[1.15fr_0.85fr] lg:py-28">
                        <div>
                            <h2 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                                Gen AI assistant inside the app
                            </h2>
                            <p className="mt-4 max-w-lg text-sm leading-relaxed text-white/55 sm:text-base">
                                Chat SOP rules, summarize a single anomaly, and
                                draft a 7-day report. One surface that proves
                                Gemini is used meaningfully - not just named in
                                a deck.
                            </p>
                        </div>
                        <ul className="space-y-2 font-mono text-xs text-white/65 sm:text-sm">
                            {[
                                'GET  /assistant',
                                'POST /assistant/chat',
                                'POST /assistant/events/{id}/summarize',
                                'POST /assistant/daily-report',
                            ].map((line) => (
                                <li
                                    key={line}
                                    className="rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3"
                                >
                                    {line}
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                <section className="border-t border-white/10 bg-[#101317]">
                    <div className="mx-auto flex max-w-6xl flex-col items-start justify-between gap-8 px-5 py-16 sm:flex-row sm:items-center sm:px-8 sm:py-20">
                        <div>
                            <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                                Ready for an end-to-end demo
                            </h2>
                            <p className="mt-2 max-w-md text-sm text-white/50">
                                Dashboard, anomalies, then Gen AI assistant -
                                one flow for your submission video.
                            </p>
                        </div>
                        <Link
                            href={primaryHref}
                            className="shrink-0 rounded-full bg-[#ef5023] px-6 py-3 text-sm font-semibold text-white transition hover:bg-[#f9a619] hover:text-[#0b0d0f] active:scale-[0.98]"
                        >
                            {primaryLabel}
                        </Link>
                    </div>
                </section>

                <footer className="border-t border-white/10">
                    <div className="mx-auto flex max-w-6xl flex-col gap-2 px-5 py-8 text-xs text-white/35 sm:flex-row sm:justify-between sm:px-8">
                        <p>© {new Date().getFullYear()} Anpixel DapurSense</p>
                        <p>Google Cloud · Gemini · Cloud Run</p>
                    </div>
                </footer>
            </div>
        </>
    );
}
