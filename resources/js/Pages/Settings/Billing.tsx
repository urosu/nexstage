import { Head, useForm, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { formatDateOnly } from '@/lib/formatters';
import { type PageProps } from '@/types';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import TextInput from '@/Components/TextInput';
import { loadStripe } from '@stripe/stripe-js';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';

const stripePromise = loadStripe(import.meta.env.VITE_STRIPE_KEY as string);

// ─── Types ───────────────────────────────────────────────────────────────────

interface WorkspaceBilling {
    id: number;
    name: string;
    billing_plan: string | null;
    trial_ends_at: string | null;
    stripe_id: string | null;
    pm_type: string | null;
    pm_last_four: string | null;
    billing_name: string | null;
    billing_email: string | null;
    billing_address: {
        company?: string;
        line1?: string;
        line2?: string;
        city?: string;
        state?: string;
        postal_code?: string;
        country?: string;
    } | null;
    vat_number: string | null;
}

// ─── Country / state data ─────────────────────────────────────────────────────

const COUNTRIES: { code: string; name: string }[] = [
    { code: 'AL', name: 'Albania' },
    { code: 'AR', name: 'Argentina' },
    { code: 'AT', name: 'Austria' },
    { code: 'AU', name: 'Australia' },
    { code: 'BA', name: 'Bosnia and Herzegovina' },
    { code: 'BE', name: 'Belgium' },
    { code: 'BG', name: 'Bulgaria' },
    { code: 'BR', name: 'Brazil' },
    { code: 'BY', name: 'Belarus' },
    { code: 'CA', name: 'Canada' },
    { code: 'CH', name: 'Switzerland' },
    { code: 'CL', name: 'Chile' },
    { code: 'CN', name: 'China' },
    { code: 'CO', name: 'Colombia' },
    { code: 'CY', name: 'Cyprus' },
    { code: 'CZ', name: 'Czech Republic' },
    { code: 'DE', name: 'Germany' },
    { code: 'DK', name: 'Denmark' },
    { code: 'EE', name: 'Estonia' },
    { code: 'EG', name: 'Egypt' },
    { code: 'ES', name: 'Spain' },
    { code: 'FI', name: 'Finland' },
    { code: 'FR', name: 'France' },
    { code: 'GB', name: 'United Kingdom' },
    { code: 'GR', name: 'Greece' },
    { code: 'HR', name: 'Croatia' },
    { code: 'HU', name: 'Hungary' },
    { code: 'ID', name: 'Indonesia' },
    { code: 'IE', name: 'Ireland' },
    { code: 'IL', name: 'Israel' },
    { code: 'IN', name: 'India' },
    { code: 'IT', name: 'Italy' },
    { code: 'JP', name: 'Japan' },
    { code: 'KR', name: 'South Korea' },
    { code: 'LT', name: 'Lithuania' },
    { code: 'LU', name: 'Luxembourg' },
    { code: 'LV', name: 'Latvia' },
    { code: 'MA', name: 'Morocco' },
    { code: 'ME', name: 'Montenegro' },
    { code: 'MK', name: 'North Macedonia' },
    { code: 'MT', name: 'Malta' },
    { code: 'MX', name: 'Mexico' },
    { code: 'MY', name: 'Malaysia' },
    { code: 'NG', name: 'Nigeria' },
    { code: 'NL', name: 'Netherlands' },
    { code: 'NO', name: 'Norway' },
    { code: 'NZ', name: 'New Zealand' },
    { code: 'PH', name: 'Philippines' },
    { code: 'PL', name: 'Poland' },
    { code: 'PT', name: 'Portugal' },
    { code: 'RO', name: 'Romania' },
    { code: 'RS', name: 'Serbia' },
    { code: 'RU', name: 'Russia' },
    { code: 'SA', name: 'Saudi Arabia' },
    { code: 'SE', name: 'Sweden' },
    { code: 'SG', name: 'Singapore' },
    { code: 'SI', name: 'Slovenia' },
    { code: 'SK', name: 'Slovakia' },
    { code: 'TH', name: 'Thailand' },
    { code: 'TR', name: 'Turkey' },
    { code: 'TW', name: 'Taiwan' },
    { code: 'UA', name: 'Ukraine' },
    { code: 'US', name: 'United States' },
    { code: 'VN', name: 'Vietnam' },
    { code: 'ZA', name: 'South Africa' },
];

// Countries where a state/province field is required
const STATE_LABEL: Record<string, string> = {
    AR: 'Province',
    AU: 'State / Territory',
    BR: 'State',
    CA: 'Province / Territory',
    IN: 'State',
    MX: 'State',
    MY: 'State',
    NG: 'State',
    PH: 'Province',
    RU: 'Region',
    US: 'State',
};

interface Subscription {
    stripe_status: string;
    stripe_price: string | null;
    ends_at: string | null;
    trial_ends_at: string | null;
    on_grace_period: boolean;
}

interface TierPrices {
    monthly: number | null;
    annual: number | null;
}

interface PaymentMethod {
    id: string;
    brand: string | null;
    last4: string | null;
    exp_month: number | null;
    exp_year: number | null;
    wallet: string | null;  // 'apple_pay' | 'google_pay' | null
    is_default: boolean;
}

interface Invoice {
    id: string;
    number: string | null;
    amount_due: number;
    currency: string;
    status: string;
    date: string;
    download_url: string;
    hosted_url: string | null;
}

interface UpcomingInvoice {
    amount: number;
    currency: string;
    date: string;
}

interface Props {
    workspace: WorkspaceBilling;
    subscription: Subscription | null;
    upcoming_invoice: UpcomingInvoice | null;
    payment_methods: PaymentMethod[];
    invoices: Invoice[];
    last_month_revenue: number;
    current_month_revenue: number;
    projected_month_revenue: number | null;
    resolved_tier: string;
    current_month_tier: string;
    projected_month_tier: string | null;
    days_until_billing: number;
    day_of_month: number;
    days_in_month: number;
    tier_prices: Record<string, TierPrices>;
    status?: string;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

const PLAN_LABELS: Record<string, string> = {
    starter: 'Starter',
    growth: 'Growth',
    scale: 'Scale',
    percentage: 'Growth+',
    enterprise: 'Enterprise',
};

function formatEur(value: number): string {
    return new Intl.NumberFormat('en-IE', {
        style: 'currency',
        currency: 'EUR',
        maximumFractionDigits: 0,
    }).format(value);
}

const CARD_BRAND_LABELS: Record<string, string> = {
    visa: 'Visa',
    mastercard: 'Mastercard',
    amex: 'American Express',
    discover: 'Discover',
    diners: 'Diners Club',
    jcb: 'JCB',
    unionpay: 'UnionPay',
    apple_pay: 'Apple Pay',
    google_pay: 'Google Pay',
};

function cardLabel(brand: string | null, wallet: string | null): string {
    if (wallet) return CARD_BRAND_LABELS[wallet] ?? wallet;
    if (brand)  return CARD_BRAND_LABELS[brand]  ?? brand.charAt(0).toUpperCase() + brand.slice(1);
    return 'Card';
}

function StatusBadge({ children, variant }: { children: React.ReactNode; variant: 'green' | 'yellow' | 'red' | 'indigo' }) {
    const colors: Record<string, string> = {
        green: 'bg-green-100 text-green-700',
        yellow: 'bg-yellow-100 text-yellow-700',
        red: 'bg-red-100 text-red-700',
        indigo: 'bg-indigo-100 text-indigo-700',
    };
    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${colors[variant]}`}>
            {children}
        </span>
    );
}

// ─── Add payment method form (uses Stripe PaymentElement) ────────────────────

function AddPaymentMethodInner({ onSuccess, onCancel }: { onSuccess: (pmId?: string) => void; onCancel: () => void }) {
    const stripe   = useStripe();
    const elements = useElements();
    const [error, setError]         = useState<string | null>(null);
    const [saving, setSaving]       = useState(false);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!stripe || !elements) return;

        setSaving(true);
        setError(null);

        const { error: submitError } = await elements.submit();
        if (submitError) {
            setError(submitError.message ?? 'Validation failed.');
            setSaving(false);
            return;
        }

        // Fetch a fresh SetupIntent client_secret from Laravel
        const res = await fetch(route('settings.billing.setup-intent'), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
        });

        if (!res.ok) {
            setError('Could not initialise payment setup. Please try again.');
            setSaving(false);
            return;
        }

        const { client_secret } = await res.json() as { client_secret: string };

        const { setupIntent, error: confirmError } = await stripe.confirmSetup({
            elements,
            clientSecret: client_secret,
            confirmParams: { return_url: window.location.href },
            redirect: 'if_required',
        });

        if (confirmError) {
            setError(confirmError.message ?? 'Could not save payment method.');
            setSaving(false);
            return;
        }

        const pmId = typeof setupIntent?.payment_method === 'string'
            ? setupIntent.payment_method
            : setupIntent?.payment_method?.id;

        onSuccess(pmId);
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            <PaymentElement />
            {error && <p className="text-sm text-red-600">{error}</p>}
            <div className="flex items-center gap-3 pt-1">
                <button
                    type="submit"
                    disabled={saving || !stripe}
                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors"
                >
                    {saving ? 'Saving…' : 'Save card'}
                </button>
                <button
                    type="button"
                    onClick={onCancel}
                    className="text-sm text-zinc-500 hover:text-zinc-700 transition-colors"
                >
                    Cancel
                </button>
            </div>
        </form>
    );
}

function AddPaymentMethodForm({ onSuccess, onCancel }: { onSuccess: (pmId?: string) => void; onCancel: () => void }) {
    return (
        <Elements stripe={stripePromise} options={{ mode: 'setup', currency: 'eur', paymentMethodCreation: 'manual' }}>
            <AddPaymentMethodInner onSuccess={onSuccess} onCancel={onCancel} />
        </Elements>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

type Tab = 'overview' | 'payment-methods' | 'invoices';

export default function Billing({
    workspace,
    subscription,
    upcoming_invoice,
    payment_methods,
    invoices,
    last_month_revenue,
    current_month_revenue,
    projected_month_revenue,
    resolved_tier,
    current_month_tier,
    projected_month_tier,
    days_until_billing,
    day_of_month,
    days_in_month,
    tier_prices,
    status,
}: Props) {
    const { errors } = usePage<PageProps<{ errors: Record<string, string> }>>().props;

    const [tab, setTab]             = useState<Tab>('overview');
    const [interval, setInterval]   = useState<'monthly' | 'annual'>('monthly');
    const [subscribing, setSubscribing] = useState(false);
    const [showAddCard, setShowAddCard] = useState(false);

    const detailsForm = useForm({
        billing_name: workspace.billing_name ?? '',
        billing_email: workspace.billing_email ?? '',
        'billing_address.company': workspace.billing_address?.company ?? '',
        'billing_address.country': workspace.billing_address?.country ?? '',
        'billing_address.line1': workspace.billing_address?.line1 ?? '',
        'billing_address.line2': workspace.billing_address?.line2 ?? '',
        'billing_address.city': workspace.billing_address?.city ?? '',
        'billing_address.state': workspace.billing_address?.state ?? '',
        'billing_address.postal_code': workspace.billing_address?.postal_code ?? '',
        vat_number: workspace.vat_number ?? '',
    });

    const selectedCountry = detailsForm.data['billing_address.country'];
    const stateLabel = selectedCountry ? STATE_LABEL[selectedCountry] : undefined;

    const submitDetails: React.FormEventHandler<HTMLFormElement> = (e) => {
        e.preventDefault();
        detailsForm.patch(route('settings.billing.details'));
    };

    const handleSubscribe = () => {
        setSubscribing(true);
        router.post(route('settings.billing.subscribe'), { interval }, {
            onFinish: () => setSubscribing(false),
        });
    };

    const handleCancel = () => {
        if (!confirm('Cancel your subscription? You retain access until the end of the billing period.')) return;
        router.delete(route('settings.billing.cancel'));
    };

    const handleResume = () => {
        router.post(route('settings.billing.resume'));
    };

    // ── Derived state ──────────────────────────────────────────────────────

    const isOnTrial = workspace.trial_ends_at
        ? new Date(workspace.trial_ends_at) > new Date()
        : false;

    const trialEnded = workspace.trial_ends_at
        ? new Date(workspace.trial_ends_at) <= new Date()
        : false;

    const currentPlan = workspace.billing_plan;

    function tierCost(tier: string, billingInterval: 'monthly' | 'annual'): number | null {
        if (tier === 'percentage') return null;
        return (billingInterval === 'annual' ? tier_prices[tier]?.annual : tier_prices[tier]?.monthly) ?? null;
    }

    function revenueCost(tier: string, revenue: number, billingInterval: 'monthly' | 'annual'): number | null {
        if (tier === 'percentage') return Math.max(Math.round(revenue * 0.01), 149);
        return tierCost(tier, billingInterval);
    }

    const lastMonthCost     = last_month_revenue > 0 ? revenueCost(resolved_tier, last_month_revenue, interval) : null;
    const displayTier       = projected_month_tier ?? current_month_tier;
    const displayRevenue    = projected_month_revenue ?? current_month_revenue;
    const displayCost       = revenueCost(displayTier, displayRevenue, interval);
    const effectiveTier     = projected_month_tier ?? current_month_tier;
    const tierChangePending = last_month_revenue > 0 && effectiveTier !== resolved_tier;
    const billingImminent   = days_until_billing <= 7;

    const tabs: { key: Tab; label: string; badge?: number }[] = [
        { key: 'overview', label: 'Overview' },
        { key: 'payment-methods', label: 'Payment methods', badge: payment_methods.length || undefined },
        { key: 'invoices', label: 'Invoices', badge: invoices.length || undefined },
    ];

    return (
        <AppLayout>
            <Head title="Billing" />

            <PageHeader
                title="Billing"
                subtitle="Manage your subscription and billing details"
            />

            <div className="mt-6 max-w-3xl">

                {/* Flash / errors */}
                {status && (
                    <div className="mb-5 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-700">
                        {status}
                    </div>
                )}
                {errors.billing && (
                    <div className="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                        {errors.billing}
                    </div>
                )}

                {/* Tab bar */}
                <div className="mb-6 flex gap-1 rounded-lg border border-zinc-200 bg-zinc-100 p-1">
                    {tabs.map((t) => (
                        <button
                            key={t.key}
                            type="button"
                            onClick={() => setTab(t.key)}
                            className={`flex flex-1 items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors ${
                                tab === t.key
                                    ? 'bg-white text-zinc-900 shadow-sm'
                                    : 'text-zinc-500 hover:text-zinc-700'
                            }`}
                        >
                            {t.label}
                            {t.badge !== undefined && (
                                <span className={`rounded-full px-1.5 py-0.5 text-xs font-medium ${
                                    tab === t.key ? 'bg-zinc-100 text-zinc-600' : 'bg-zinc-200 text-zinc-500'
                                }`}>
                                    {t.badge}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                {/* ── Overview tab ───────────────────────────────────────── */}
                {tab === 'overview' && (
                    <div className="space-y-6">

                        {/* Plan overview */}
                        <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                            <div className="border-b border-zinc-200 px-6 py-4">
                                <h3 className="text-base font-semibold text-zinc-900">Plan overview</h3>
                            </div>
                            <div className="px-6 py-5 space-y-5">

                                {/* Revenue → cost grid */}
                                <div className="grid grid-cols-2 divide-x divide-zinc-100 -mx-6 px-6">

                                    {/* Last month */}
                                    <div className="pr-6 space-y-1">
                                        <p className="text-xs font-medium text-zinc-400 uppercase tracking-wide">Last month</p>
                                        {last_month_revenue > 0 ? (
                                            <>
                                                <p className="mt-1 text-base font-semibold text-zinc-900">{PLAN_LABELS[resolved_tier] ?? resolved_tier}</p>
                                                <p className="text-xl font-semibold text-zinc-900">
                                                    {lastMonthCost !== null ? `${formatEur(lastMonthCost)}/mo` : '1% of revenue'}
                                                    <span className="ml-1.5 text-xs font-normal text-zinc-400">billed</span>
                                                </p>
                                                <p className="text-xs text-zinc-400">{formatEur(last_month_revenue)} revenue</p>
                                            </>
                                        ) : (
                                            <p className="mt-1 text-sm text-zinc-400">No data yet</p>
                                        )}
                                    </div>

                                    {/* This month */}
                                    <div className="pl-6 space-y-1">
                                        <div className="flex items-center gap-2">
                                            <p className="text-xs font-medium text-zinc-400 uppercase tracking-wide">This month so far</p>
                                            {isOnTrial && !currentPlan && <StatusBadge variant="indigo">Trial</StatusBadge>}
                                            {trialEnded && !currentPlan && <StatusBadge variant="red">Trial expired</StatusBadge>}
                                            {currentPlan && subscription?.on_grace_period && <StatusBadge variant="yellow">Cancelling</StatusBadge>}
                                            {currentPlan && !subscription?.on_grace_period && <StatusBadge variant="green">Active</StatusBadge>}
                                        </div>

                                        {projected_month_revenue !== null && current_month_revenue > 0 ? (
                                            <>
                                                <p className="mt-1 text-base font-semibold text-zinc-900">{PLAN_LABELS[displayTier] ?? displayTier}</p>
                                                <p className="text-xl font-semibold text-zinc-900">
                                                    {displayCost !== null ? `${formatEur(displayCost)}/mo` : '1% of revenue'}
                                                    <span className="ml-1.5 text-xs font-normal text-zinc-400">est.</span>
                                                </p>
                                                <p className="text-xs text-zinc-400">
                                                    {formatEur(current_month_revenue)} so far · {formatEur(projected_month_revenue!)} projected · day {day_of_month} of {days_in_month}
                                                </p>
                                                {tierChangePending && (
                                                    <p className="mt-1 text-xs font-medium text-yellow-600">
                                                        Changes from {PLAN_LABELS[resolved_tier] ?? resolved_tier} to {PLAN_LABELS[effectiveTier] ?? effectiveTier} on the 1st
                                                    </p>
                                                )}
                                            </>
                                        ) : (
                                            <p className="mt-1 text-sm text-zinc-400">
                                                {current_month_revenue > 0 ? `${formatEur(current_month_revenue)} so far` : '—'}
                                            </p>
                                        )}

                                        {!tierChangePending && billingImminent && (
                                            <p className="mt-1 text-xs text-zinc-400">
                                                Billing in {days_until_billing} {days_until_billing === 1 ? 'day' : 'days'}
                                            </p>
                                        )}
                                    </div>
                                </div>

                                {/* Upcoming invoice */}
                                {upcoming_invoice && (
                                    <div className="flex items-center justify-between rounded-lg border border-zinc-100 bg-zinc-50 px-4 py-3">
                                        <div>
                                            <p className="text-xs font-medium text-zinc-500 uppercase tracking-wide">Next invoice</p>
                                            <p className="mt-0.5 text-sm font-medium text-zinc-800">
                                                {new Intl.NumberFormat('en-IE', { style: 'currency', currency: upcoming_invoice.currency }).format(upcoming_invoice.amount)}
                                            </p>
                                        </div>
                                        <p className="text-sm text-zinc-500">
                                            Due {formatDateOnly(upcoming_invoice.date)}
                                        </p>
                                    </div>
                                )}

                                {/* Trial expiry / grace period detail */}
                                {isOnTrial && !currentPlan && workspace.trial_ends_at && (
                                    <p className="text-sm text-zinc-500">
                                        Trial ends {formatDateOnly(workspace.trial_ends_at)}
                                    </p>
                                )}
                                {currentPlan && subscription?.on_grace_period && subscription.ends_at && (
                                    <p className="text-sm text-zinc-500">
                                        Access until {formatDateOnly(subscription.ends_at)}
                                    </p>
                                )}

                                {/* Actions */}
                                {currentPlan === 'enterprise' && (
                                    <p className="text-sm text-zinc-500">Enterprise plan — contact us to make changes.</p>
                                )}

                                {currentPlan && currentPlan !== 'enterprise' && subscription?.on_grace_period && (
                                    <div className="flex items-center gap-4">
                                        <button
                                            type="button"
                                            onClick={handleResume}
                                            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition-colors"
                                        >
                                            Resume subscription
                                        </button>
                                        <span className="text-xs text-zinc-400">Your subscription will continue as normal.</span>
                                    </div>
                                )}

                                {currentPlan && currentPlan !== 'enterprise' && subscription && !subscription.on_grace_period && (
                                    <button
                                        type="button"
                                        onClick={handleCancel}
                                        className="text-sm text-red-600 hover:text-red-800 transition-colors"
                                    >
                                        Cancel subscription
                                    </button>
                                )}

                                {!currentPlan && (
                                    <div className="space-y-3">
                                        {trialEnded && (
                                            <p className="text-sm text-red-600">Your trial has ended. Subscribe to continue using Nexstage.</p>
                                        )}
                                        {resolved_tier !== 'percentage' && (
                                            <div>
                                                <p className="mb-2 text-xs font-medium text-zinc-500 uppercase tracking-wide">Billing interval</p>
                                                <div className="flex w-48 rounded-md border border-zinc-200 overflow-hidden text-sm">
                                                    <button
                                                        type="button"
                                                        onClick={() => setInterval('monthly')}
                                                        className={`flex-1 py-2 transition-colors ${interval === 'monthly' ? 'bg-zinc-900 text-white' : 'bg-white text-zinc-600 hover:bg-zinc-50'}`}
                                                    >
                                                        Monthly
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => setInterval('annual')}
                                                        className={`flex-1 py-2 transition-colors ${interval === 'annual' ? 'bg-zinc-900 text-white' : 'bg-white text-zinc-600 hover:bg-zinc-50'}`}
                                                    >
                                                        Annual
                                                    </button>
                                                </div>
                                                {interval === 'annual' && (
                                                    <p className="mt-1 text-xs text-green-700">Annual saves 2 months (billed yearly)</p>
                                                )}
                                            </div>
                                        )}
                                        <div className="flex items-center gap-3">
                                            <button
                                                type="button"
                                                disabled={subscribing}
                                                onClick={handleSubscribe}
                                                className="rounded-md bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors"
                                            >
                                                {subscribing ? 'Redirecting…' : 'Subscribe'}
                                            </button>
                                            <span className="text-xs text-zinc-400">Tier is assigned automatically and adjusts monthly.</span>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Billing details */}
                        <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                            <div className="border-b border-zinc-200 px-6 py-4">
                                <h3 className="text-base font-semibold text-zinc-900">Billing details</h3>
                                <p className="mt-0.5 text-xs text-zinc-500">Used on invoices and synced to Stripe.</p>
                            </div>
                            <form onSubmit={submitDetails} className="space-y-4 px-6 py-5">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <InputLabel htmlFor="billing_name" value="Full name" />
                                        <TextInput id="billing_name" value={detailsForm.data.billing_name} onChange={(e) => detailsForm.setData('billing_name', e.target.value)} className="mt-1 block w-full" placeholder="Jane Smith" required />
                                        <InputError message={detailsForm.errors.billing_name} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="billing_email" value="Billing email" />
                                        <TextInput id="billing_email" type="email" value={detailsForm.data.billing_email} onChange={(e) => detailsForm.setData('billing_email', e.target.value)} className="mt-1 block w-full" required />
                                        <InputError message={detailsForm.errors.billing_email} className="mt-1" />
                                    </div>
                                </div>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <InputLabel htmlFor="company" value="Company (optional)" />
                                        <TextInput id="company" value={detailsForm.data['billing_address.company']} onChange={(e) => detailsForm.setData('billing_address.company', e.target.value as never)} className="mt-1 block w-full" placeholder={workspace.name} />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="vat_number" value="VAT number (optional)" />
                                        <TextInput id="vat_number" value={detailsForm.data.vat_number} onChange={(e) => detailsForm.setData('vat_number', e.target.value)} className="mt-1 block w-full" placeholder="DE123456789" />
                                        <InputError message={detailsForm.errors.vat_number} className="mt-1" />
                                    </div>
                                </div>
                                <div className="sm:max-w-xs">
                                    <InputLabel htmlFor="country" value="Country" />
                                    <select id="country" value={detailsForm.data['billing_address.country']} onChange={(e) => { detailsForm.setData('billing_address.country', e.target.value as never); detailsForm.setData('billing_address.state', '' as never); }} className="mt-1 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                        <option value="">Select country…</option>
                                        {COUNTRIES.map((c) => (<option key={c.code} value={c.code}>{c.name}</option>))}
                                    </select>
                                    <InputError message={detailsForm.errors['billing_address.country']} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel htmlFor="line1" value="Address line 1" />
                                    <TextInput id="line1" value={detailsForm.data['billing_address.line1']} onChange={(e) => detailsForm.setData('billing_address.line1', e.target.value as never)} className="mt-1 block w-full" placeholder="Street address" />
                                </div>
                                <div>
                                    <InputLabel htmlFor="line2" value="Address line 2 (optional)" />
                                    <TextInput id="line2" value={detailsForm.data['billing_address.line2']} onChange={(e) => detailsForm.setData('billing_address.line2', e.target.value as never)} className="mt-1 block w-full" placeholder="Suite, floor, etc." />
                                </div>
                                <div className="grid grid-cols-3 gap-4">
                                    <div className="col-span-2">
                                        <InputLabel htmlFor="city" value="City" />
                                        <TextInput id="city" value={detailsForm.data['billing_address.city']} onChange={(e) => detailsForm.setData('billing_address.city', e.target.value as never)} className="mt-1 block w-full" />
                                    </div>
                                    <div>
                                        <InputLabel htmlFor="postal_code" value="Postcode" />
                                        <TextInput id="postal_code" value={detailsForm.data['billing_address.postal_code']} onChange={(e) => detailsForm.setData('billing_address.postal_code', e.target.value as never)} className="mt-1 block w-full" />
                                    </div>
                                </div>
                                {stateLabel && (
                                    <div className="sm:max-w-xs">
                                        <InputLabel htmlFor="state" value={stateLabel} />
                                        <TextInput id="state" value={detailsForm.data['billing_address.state']} onChange={(e) => detailsForm.setData('billing_address.state', e.target.value as never)} className="mt-1 block w-full" placeholder={selectedCountry === 'US' ? 'CA' : ''} />
                                        <InputError message={detailsForm.errors['billing_address.state']} className="mt-1" />
                                    </div>
                                )}
                                <div className="flex items-center gap-4 pt-1">
                                    <button type="submit" disabled={detailsForm.processing} className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                        Save details
                                    </button>
                                    {detailsForm.recentlySuccessful && <span className="text-sm text-green-600">Saved.</span>}
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* ── Payment methods tab ────────────────────────────────── */}
                {tab === 'payment-methods' && (
                    <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                        <div className="flex items-center justify-between border-b border-zinc-200 px-6 py-4">
                            <div>
                                <h3 className="text-base font-semibold text-zinc-900">Payment methods</h3>
                                <p className="mt-0.5 text-xs text-zinc-500">Cards on file for this workspace.</p>
                            </div>
                            {!showAddCard && (
                                <button type="button" onClick={() => setShowAddCard(true)} className="rounded-md border border-zinc-200 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 transition-colors">
                                    + Add card
                                </button>
                            )}
                        </div>
                        <div className="divide-y divide-zinc-100 px-6">
                            {payment_methods.length === 0 && !showAddCard && (
                                <div className="py-8 text-center">
                                    <p className="text-sm text-zinc-500">No payment methods on file.</p>
                                    <button type="button" onClick={() => setShowAddCard(true)} className="mt-3 text-sm text-indigo-600 hover:text-indigo-800 transition-colors">
                                        Add your first card →
                                    </button>
                                </div>
                            )}
                            {payment_methods.map((pm) => (
                                <div key={pm.id} className="flex items-center gap-4 py-4">
                                    <div className="flex h-8 w-12 shrink-0 items-center justify-center rounded border border-zinc-200 bg-white text-xs font-bold text-zinc-700 tracking-wide">
                                        {(pm.wallet ? CARD_BRAND_LABELS[pm.wallet] : CARD_BRAND_LABELS[pm.brand ?? ''])?.slice(0, 4).toUpperCase() ?? 'CARD'}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-medium text-zinc-800">{cardLabel(pm.brand, pm.wallet)} ···· {pm.last4}</p>
                                        {pm.exp_month && pm.exp_year && (
                                            <p className="text-xs text-zinc-400">Expires {String(pm.exp_month).padStart(2, '0')}/{pm.exp_year}</p>
                                        )}
                                    </div>
                                    <div className="flex shrink-0 items-center gap-3">
                                        {pm.is_default ? (
                                            <StatusBadge variant="green">Default</StatusBadge>
                                        ) : (
                                            <button type="button" onClick={() => router.post(route('settings.billing.payment-methods.default', { pmId: pm.id }))} className="text-xs text-indigo-600 hover:text-indigo-800 transition-colors">
                                                Set as default
                                            </button>
                                        )}
                                        <button type="button" onClick={() => { if (confirm('Remove this payment method?')) router.delete(route('settings.billing.payment-methods.delete', { pmId: pm.id })); }} className="text-xs text-red-500 hover:text-red-700 transition-colors">
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            ))}
                            {showAddCard && (
                                <div className="py-5">
                                    <p className="mb-4 text-sm font-medium text-zinc-700">New payment method</p>
                                    <AddPaymentMethodForm
                                        onSuccess={(pmId) => {
                                            setShowAddCard(false);
                                            if (pmId) {
                                                router.post(route('settings.billing.payment-methods.confirm', { pmId }));
                                            } else {
                                                router.reload();
                                            }
                                        }}
                                        onCancel={() => setShowAddCard(false)}
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* ── Invoices tab ───────────────────────────────────────── */}
                {tab === 'invoices' && (
                    <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                        <div className="border-b border-zinc-200 px-6 py-4">
                            <h3 className="text-base font-semibold text-zinc-900">Invoice history</h3>
                        </div>
                        {invoices.length === 0 ? (
                            <div className="py-12 text-center">
                                <p className="text-sm text-zinc-500">No invoices yet.</p>
                            </div>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-zinc-100 bg-zinc-50">
                                        <th className="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase tracking-wide">Date</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase tracking-wide">Invoice</th>
                                        <th className="px-6 py-3 text-right text-xs font-medium text-zinc-400 uppercase tracking-wide">Amount</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-zinc-400 uppercase tracking-wide">Status</th>
                                        <th className="px-6 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-zinc-100">
                                    {invoices.map((inv) => (
                                        <tr key={inv.id} className="hover:bg-zinc-50 transition-colors">
                                            <td className="px-6 py-3 text-zinc-600 whitespace-nowrap">
                                                {formatDateOnly(inv.date)}
                                            </td>
                                            <td className="px-6 py-3 text-zinc-500 font-mono text-xs">{inv.number ?? inv.id}</td>
                                            <td className="px-6 py-3 text-right font-medium text-zinc-800 whitespace-nowrap">
                                                {new Intl.NumberFormat('en-IE', { style: 'currency', currency: inv.currency }).format(inv.amount_due)}
                                            </td>
                                            <td className="px-6 py-3">
                                                <StatusBadge variant={inv.status === 'paid' ? 'green' : inv.status === 'open' ? 'yellow' : 'red'}>
                                                    {inv.status.charAt(0).toUpperCase() + inv.status.slice(1)}
                                                </StatusBadge>
                                            </td>
                                            <td className="px-6 py-3 text-right whitespace-nowrap">
                                                <a href={inv.download_url} className="text-xs text-indigo-600 hover:text-indigo-800 transition-colors mr-3">PDF</a>
                                                {inv.hosted_url && (
                                                    <a href={inv.hosted_url} target="_blank" rel="noopener noreferrer" className="text-xs text-zinc-400 hover:text-zinc-600 transition-colors">View</a>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                )}

            </div>
        </AppLayout>
    );
}
