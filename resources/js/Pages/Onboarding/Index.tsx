import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface ImportStatus {
    status: 'pending' | 'running' | 'completed' | 'failed' | null;
    progress: number | null;
    total_orders: number | null;
    started_at: string | null;
    completed_at: string | null;
    duration_seconds: number | null;
    error_message: string | null;
}

interface Props {
    step: 1 | 2 | 3;
    store_id?: number;
    store_slug?: string;
    store_name?: string;
}

// ---------------------------------------------------------------------------
// Shared layout wrapper — clean centred card, no sidebar
// ---------------------------------------------------------------------------

function OnboardingLayout({
    children,
    currentStep,
}: {
    children: React.ReactNode;
    currentStep: 1 | 2 | 3;
}) {
    const steps = ['Connect store', 'Choose history', 'Importing data'];

    return (
        <div className="flex min-h-screen flex-col items-center bg-zinc-50 px-4 py-12">
            {/* Logo */}
            <Link
                href="/"
                className="mb-8 text-xl font-bold tracking-tight text-zinc-900"
            >
                Nexstage
            </Link>

            {/* Step indicator */}
            <div className="mb-8 flex items-center gap-3">
                {steps.map((label, idx) => {
                    const num = idx + 1;
                    const done = num < currentStep;
                    const active = num === currentStep;
                    return (
                        <div key={label} className="flex items-center gap-3">
                            <div className="flex items-center gap-2">
                                <div
                                    className={[
                                        'flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold',
                                        done
                                            ? 'bg-indigo-600 text-white'
                                            : active
                                              ? 'bg-indigo-600 text-white ring-2 ring-indigo-600 ring-offset-2'
                                              : 'bg-zinc-200 text-zinc-500',
                                    ].join(' ')}
                                >
                                    {done ? (
                                        <svg
                                            className="h-3.5 w-3.5"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            strokeWidth={3}
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M5 13l4 4L19 7"
                                            />
                                        </svg>
                                    ) : (
                                        num
                                    )}
                                </div>
                                <span
                                    className={[
                                        'text-sm font-medium',
                                        active
                                            ? 'text-zinc-900'
                                            : 'text-zinc-400',
                                    ].join(' ')}
                                >
                                    {label}
                                </span>
                            </div>
                            {idx < steps.length - 1 && (
                                <div className="h-px w-8 bg-zinc-300" />
                            )}
                        </div>
                    );
                })}
            </div>

            {/* Card */}
            <div className="w-full max-w-lg rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                {children}
            </div>
        </div>
    );
}

// ---------------------------------------------------------------------------
// Step 1 — Connect WooCommerce store
// ---------------------------------------------------------------------------

function StepConnect() {
    const { data, setData, post, processing, errors } = useForm({
        domain: '',
        consumer_key: '',
        consumer_secret: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(route('onboarding.store'));
    }

    return (
        <>
            <Head title="Connect your store" />

            <h1 className="text-lg font-semibold text-zinc-900">
                Connect your WooCommerce store
            </h1>
            <p className="mt-1 text-sm text-zinc-500">
                You'll need a REST API key from WooCommerce. Go to{' '}
                <strong>Settings → Advanced → REST API</strong> and create a key
                with <strong>Read/Write</strong> permissions.
            </p>

            <form onSubmit={submit} className="mt-6 space-y-4">
                <div>
                    <InputLabel htmlFor="domain" value="Store URL" />
                    <TextInput
                        id="domain"
                        type="url"
                        placeholder="https://yourstore.com"
                        value={data.domain}
                        className="mt-1 block w-full"
                        onChange={(e) => setData('domain', e.target.value)}
                        required
                        isFocused
                    />
                    <InputError message={errors.domain} className="mt-1.5" />
                </div>

                <div>
                    <InputLabel htmlFor="consumer_key" value="Consumer key" />
                    <TextInput
                        id="consumer_key"
                        placeholder="ck_…"
                        value={data.consumer_key}
                        className="mt-1 block w-full font-mono text-sm"
                        onChange={(e) => setData('consumer_key', e.target.value)}
                        required
                    />
                    <InputError
                        message={errors.consumer_key}
                        className="mt-1.5"
                    />
                </div>

                <div>
                    <InputLabel
                        htmlFor="consumer_secret"
                        value="Consumer secret"
                    />
                    <TextInput
                        id="consumer_secret"
                        type="password"
                        placeholder="cs_…"
                        value={data.consumer_secret}
                        className="mt-1 block w-full font-mono text-sm"
                        onChange={(e) =>
                            setData('consumer_secret', e.target.value)
                        }
                        required
                    />
                    <InputError
                        message={errors.consumer_secret}
                        className="mt-1.5"
                    />
                </div>

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-60"
                    >
                        {processing ? 'Connecting…' : 'Connect store'}
                    </button>
                </div>
            </form>
        </>
    );
}

// ---------------------------------------------------------------------------
// Step 2 — Choose import date range
// ---------------------------------------------------------------------------

const PERIODS = [
    {
        value: '30days',
        label: 'Last 30 days',
        description: 'Quick start — good for recent trends',
    },
    {
        value: '90days',
        label: 'Last 90 days',
        description: 'Three months of order history',
    },
    {
        value: '1year',
        label: 'Last year',
        description: 'Full year for seasonal comparisons',
    },
    {
        value: 'all',
        label: 'All history',
        description: 'Everything since your store opened',
    },
] as const;

function StepDateRange({
    storeId,
    storeName,
}: {
    storeId: number;
    storeName: string;
}) {
    const { data, setData, post, processing } = useForm<{
        store_id: number;
        period: string;
    }>({
        store_id: storeId,
        period: '90days',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(route('onboarding.import'));
    }

    return (
        <>
            <Head title="Choose import range" />

            <h1 className="text-lg font-semibold text-zinc-900">
                How much history should we import?
            </h1>
            <p className="mt-1 text-sm text-zinc-500">
                Connected to <span className="font-medium text-zinc-700">{storeName}</span>.
                We'll count your orders before starting — you'll see a time estimate on
                the next screen.
            </p>

            <form onSubmit={submit} className="mt-6 space-y-3">
                <input type="hidden" name="store_id" value={data.store_id} />

                {PERIODS.map((p) => (
                    <label
                        key={p.value}
                        className={[
                            'flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors',
                            data.period === p.value
                                ? 'border-indigo-500 bg-indigo-50'
                                : 'border-zinc-200 hover:border-zinc-300 hover:bg-zinc-50',
                        ].join(' ')}
                    >
                        <input
                            type="radio"
                            name="period"
                            value={p.value}
                            checked={data.period === p.value}
                            onChange={() => setData('period', p.value)}
                            className="mt-0.5 h-4 w-4 accent-indigo-600"
                        />
                        <div>
                            <div className="text-sm font-medium text-zinc-900">
                                {p.label}
                            </div>
                            <div className="text-xs text-zinc-500">
                                {p.description}
                            </div>
                        </div>
                    </label>
                ))}

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-60"
                    >
                        {processing ? 'Starting…' : 'Start import'}
                    </button>
                </div>
            </form>

            <div className="mt-4 border-t border-zinc-100 pt-4 text-center">
                <button
                    type="button"
                    onClick={() => router.post(route('onboarding.reset'))}
                    className="text-sm text-zinc-400 hover:text-zinc-600"
                >
                    ← Start over
                </button>
            </div>
        </>
    );
}

// ---------------------------------------------------------------------------
// Step 3 — Import progress polling
// ---------------------------------------------------------------------------

function formatDuration(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return s > 0 ? `${m}m ${s}s` : `${m}m`;
}

function formatEstimate(totalOrders: number, startedAt: string): string {
    const elapsed = (Date.now() - new Date(startedAt).getTime()) / 1000;
    // Very rough: if we have no progress yet, fall back to 1 min per 1000 orders
    const estimatedTotal = Math.max(elapsed * 2, (totalOrders / 1000) * 60);
    const remaining = Math.max(0, estimatedTotal - elapsed);
    if (remaining < 60) return 'less than a minute';
    return `~${Math.ceil(remaining / 60)} min`;
}

function StepProgress({ storeSlug }: { storeSlug: string }) {
    const [status, setStatus] = useState<ImportStatus>({
        status: null,
        progress: null,
        total_orders: null,
        started_at: null,
        completed_at: null,
        duration_seconds: null,
        error_message: null,
    });
    const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

    useEffect(() => {
        function poll() {
            fetch(route('api.stores.import-status', { slug: storeSlug }), {
                headers: { Accept: 'application/json' },
            })
                .then((r) => r.json())
                .then((data: ImportStatus) => {
                    setStatus(data);

                    if (data.status === 'completed') {
                        if (intervalRef.current) clearInterval(intervalRef.current);
                        router.visit(route('dashboard'));
                    } else if (data.status === 'failed') {
                        if (intervalRef.current) clearInterval(intervalRef.current);
                    }
                })
                .catch(() => {
                    // Network error — keep polling, will recover
                });
        }

        poll(); // immediate first tick
        intervalRef.current = setInterval(poll, 5000);

        return () => {
            if (intervalRef.current) clearInterval(intervalRef.current);
        };
    }, [storeSlug]);

    const progress = status.progress ?? 0;
    const isFailed = status.status === 'failed';

    return (
        <>
            <Head title="Importing data" />

            <h1 className="text-lg font-semibold text-zinc-900">
                {isFailed ? 'Import failed' : 'Importing your order history…'}
            </h1>

            {isFailed ? (
                <>
                    <p className="mt-2 text-sm text-red-600">
                        {status.error_message ?? 'An unexpected error occurred.'}
                    </p>
                    <div className="mt-6 flex flex-col gap-3">
                        <button
                            onClick={() => router.post(route('onboarding.import.reset'))}
                            className="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
                        >
                            Try again
                        </button>
                        <button
                            onClick={() => router.post(route('onboarding.reset'))}
                            className="text-sm text-zinc-400 hover:text-zinc-600"
                        >
                            ← Start over
                        </button>
                    </div>
                </>
            ) : (
                <>
                    {/* Progress bar */}
                    <div className="mt-4">
                        <div className="flex items-center justify-between text-xs text-zinc-500">
                            <span>
                                {status.status === 'pending'
                                    ? 'Queued…'
                                    : 'Importing orders'}
                            </span>
                            <span>{progress}%</span>
                        </div>
                        <div className="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-zinc-100">
                            <div
                                className="h-full rounded-full bg-indigo-600 transition-all duration-500"
                                style={{ width: `${progress}%` }}
                            />
                        </div>
                    </div>

                    {/* Stats */}
                    <div className="mt-4 grid grid-cols-2 gap-4 text-sm">
                        {status.total_orders !== null && (
                            <div>
                                <div className="text-zinc-400">Total orders</div>
                                <div className="font-medium text-zinc-900">
                                    {status.total_orders.toLocaleString()}
                                </div>
                            </div>
                        )}
                        {status.started_at && status.total_orders && progress < 100 && (
                            <div>
                                <div className="text-zinc-400">
                                    Time remaining
                                </div>
                                <div className="font-medium text-zinc-900">
                                    {formatEstimate(
                                        status.total_orders,
                                        status.started_at,
                                    )}
                                </div>
                            </div>
                        )}
                        {status.duration_seconds !== null && (
                            <div>
                                <div className="text-zinc-400">Duration</div>
                                <div className="font-medium text-zinc-900">
                                    {formatDuration(status.duration_seconds)}
                                </div>
                            </div>
                        )}
                    </div>

                    <p className="mt-6 text-xs text-zinc-400">
                        You can close this tab — the import runs in the
                        background. Come back at any time to check progress.
                    </p>

                    <div className="mt-4 border-t border-zinc-100 pt-4 text-center">
                        <button
                            type="button"
                            onClick={() => router.post(route('onboarding.reset'))}
                            className="text-sm text-zinc-400 hover:text-zinc-600"
                        >
                            ← Start over
                        </button>
                    </div>
                </>
            )}
        </>
    );
}

// ---------------------------------------------------------------------------
// Root page component
// ---------------------------------------------------------------------------

export default function OnboardingIndex({
    step,
    store_id,
    store_slug,
    store_name,
}: Props) {
    return (
        <OnboardingLayout currentStep={step}>
            {step === 1 && <StepConnect />}
            {step === 2 && store_id && (
                <StepDateRange storeId={store_id} storeName={store_name ?? ''} />
            )}
            {step === 3 && store_slug && <StepProgress storeSlug={store_slug} />}
        </OnboardingLayout>
    );
}
