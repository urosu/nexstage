import { Head, useForm, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { formatDateOnly } from '@/lib/formatters';
import { type PageProps } from '@/types';
import { wurl } from '@/lib/workspace-url';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
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
    workspaceInfo: WorkspaceBilling;
    subscription: Subscription | null;
    upcoming_invoice: UpcomingInvoice | null;
    payment_methods: PaymentMethod[];
    invoices: Invoice[];
    last_month_revenue: number;
    current_month_revenue: number;
    projected_month_revenue: number | null;
    last_month_bill: number;
    projected_bill: number | null;
    plan_rate: number;
    plan_minimum: number;
    days_until_billing: number;
    day_of_month: number;
    days_in_month: number;
    /** 'gmv' for ecom workspaces (has_store=true), 'ad_spend' for non-ecom */
    billing_basis: 'gmv' | 'ad_spend';
    status?: string;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

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
        indigo: 'bg-primary/15 text-primary',
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
    const { workspace: ws } = usePage<PageProps>().props;
    const w = (path: string) => wurl(ws?.slug, path);

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
        const res = await fetch(w('/settings/billing/payment-methods/setup-intent'), {
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
                    className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors"
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
    workspaceInfo,
    subscription,
    upcoming_invoice,
    payment_methods,
    invoices,
    last_month_revenue,
    current_month_revenue,
    projected_month_revenue,
    last_month_bill,
    projected_bill,
    plan_rate,
    plan_minimum,
    days_until_billing,
    day_of_month,
    days_in_month,
    billing_basis,
    status,
}: Props) {
    const { errors, workspace: ws } = usePage<PageProps<{ errors: Record<string, string> }>>().props;
    const w = (path: string) => wurl(ws?.slug, path);

    const [tab, setTab]             = useState<Tab>('overview');
    const [subscribing, setSubscribing] = useState(false);
    const [showAddCard, setShowAddCard] = useState(false);

    const detailsForm = useForm({
        billing_name: workspaceInfo.billing_name ?? '',
        billing_email: workspaceInfo.billing_email ?? '',
        'billing_address.company': workspaceInfo.billing_address?.company ?? '',
        'billing_address.country': workspaceInfo.billing_address?.country ?? '',
        'billing_address.line1': workspaceInfo.billing_address?.line1 ?? '',
        'billing_address.line2': workspaceInfo.billing_address?.line2 ?? '',
        'billing_address.city': workspaceInfo.billing_address?.city ?? '',
        'billing_address.state': workspaceInfo.billing_address?.state ?? '',
        'billing_address.postal_code': workspaceInfo.billing_address?.postal_code ?? '',
        vat_number: workspaceInfo.vat_number ?? '',
    });

    const selectedCountry = detailsForm.data['billing_address.country'];
    const stateLabel = selectedCountry ? STATE_LABEL[selectedCountry] : undefined;

    const submitDetails: React.FormEventHandler<HTMLFormElement> = (e) => {
        e.preventDefault();
        detailsForm.patch(w('/settings/billing/details'));
    };

    const handleSubscribe = () => {
        setSubscribing(true);
        router.post(w('/settings/billing/subscribe'), {}, {
            onFinish: () => setSubscribing(false),
        });
    };

    const handleCancel = () => {
        if (!confirm('Cancel your subscription? You retain access until the end of the billing period.')) return;
        router.delete(w('/settings/billing/cancel'));
    };

    const handleResume = () => {
        router.post(w('/settings/billing/resume'));
    };

    // ── Derived state ──────────────────────────────────────────────────────

    const isOnTrial = workspaceInfo.trial_ends_at
        ? new Date(workspaceInfo.trial_ends_at) > new Date()
        : false;

    const trialEnded = workspaceInfo.trial_ends_at
        ? new Date(workspaceInfo.trial_ends_at) <= new Date()
        : false;

    const currentPlan = workspaceInfo.billing_plan;

    // Label for the billable amount metric (GMV or ad spend)
    const billableLabel = billing_basis === 'ad_spend' ? 'ad spend' : 'GMV';
    const ratePct = plan_rate * 100;
    const priceDescription = `€${plan_minimum}/mo minimum + ${ratePct}% of ${billableLabel}`;

    // Days remaining in trial (for countdown warning)
    const trialDaysLeft = workspaceInfo.trial_ends_at
        ? Math.ceil((new Date(workspaceInfo.trial_ends_at).getTime() - Date.now()) / 86_400_000)
        : null;

    const billingImminent = days_until_billing <= 7;

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

                {/* Trial expiry warning — shown when ≤7 days left and no paid plan */}
                {trialDaysLeft !== null && trialDaysLeft > 0 && trialDaysLeft <= 7 && !workspaceInfo.billing_plan && (
                    <div className="mb-5 flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                        <svg className="mt-0.5 h-4 w-4 shrink-0 text-red-500" viewBox="0 0 20 20" fill="currentColor">
                            <path fillRule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5zm0 9a1 1 0 1 1 0-2 1 1 0 0 1 0 2z" clipRule="evenodd" />
                        </svg>
                        <div className="flex-1">
                            <p className="text-sm font-medium text-red-700">
                                Trial ends in {trialDaysLeft} {trialDaysLeft === 1 ? 'day' : 'days'}
                            </p>
                            <p className="mt-0.5 text-xs text-red-600">
                                Subscribe below to keep your syncs running and avoid data gaps.
                            </p>
                        </div>
                    </div>
                )}

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

                                {/* Plan name & price description */}
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <p className="text-base font-semibold text-zinc-900">
                                            {currentPlan === 'enterprise' ? 'Enterprise' : 'Standard'}
                                        </p>
                                        {currentPlan !== 'enterprise' && (
                                            <p className="mt-0.5 text-sm text-zinc-500">{priceDescription}</p>
                                        )}
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        {isOnTrial && !currentPlan && <StatusBadge variant="indigo">Trial</StatusBadge>}
                                        {trialEnded && !currentPlan && <StatusBadge variant="red">Trial expired</StatusBadge>}
                                        {currentPlan && subscription?.on_grace_period && <StatusBadge variant="yellow">Cancelling</StatusBadge>}
                                        {currentPlan && !subscription?.on_grace_period && <StatusBadge variant="green">Active</StatusBadge>}
                                    </div>
                                </div>

                                {/* Revenue → bill grid (hidden for enterprise) */}
                                {currentPlan !== 'enterprise' && (
                                <div className="grid grid-cols-2 divide-x divide-zinc-100 -mx-6 px-6">

                                    {/* Last month */}
                                    <div className="pr-6 space-y-1">
                                        <p className="text-xs font-medium text-zinc-400 uppercase tracking-wide">Last month</p>
                                        {last_month_revenue > 0 ? (
                                            <>
                                                <p className="text-xs text-zinc-400">{billableLabel}</p>
                                                <p className="text-base font-semibold text-zinc-900">{formatEur(last_month_revenue)}</p>
                                                <p className="mt-2 text-xs text-zinc-400">Bill</p>
                                                <p className="text-xl font-semibold text-zinc-900">
                                                    {formatEur(last_month_bill)}
                                                    <span className="ml-1.5 text-xs font-normal text-zinc-400">billed</span>
                                                </p>
                                            </>
                                        ) : (
                                            <p className="mt-1 text-sm text-zinc-400">No data yet</p>
                                        )}
                                    </div>

                                    {/* This month */}
                                    <div className="pl-6 space-y-1">
                                        <p className="text-xs font-medium text-zinc-400 uppercase tracking-wide">This month so far</p>

                                        {current_month_revenue > 0 ? (
                                            <>
                                                <p className="text-xs text-zinc-400">{billableLabel}</p>
                                                <p className="text-base font-semibold text-zinc-900">{formatEur(current_month_revenue)}</p>
                                                {projected_month_revenue !== null && projected_bill !== null ? (
                                                    <>
                                                        <p className="mt-2 text-xs text-zinc-400">Projected bill</p>
                                                        <p className="text-xl font-semibold text-zinc-900">
                                                            {formatEur(projected_bill)}
                                                            <span className="ml-1.5 text-xs font-normal text-zinc-400">est.</span>
                                                        </p>
                                                        <p className="text-xs text-zinc-400">
                                                            Projected {billableLabel}: {formatEur(projected_month_revenue)} · day {day_of_month} of {days_in_month}
                                                        </p>
                                                    </>
                                                ) : (
                                                    <p className="mt-1 text-xs text-zinc-400">
                                                        Projection available from day 7 · day {day_of_month} of {days_in_month}
                                                    </p>
                                                )}
                                            </>
                                        ) : (
                                            <p className="mt-1 text-sm text-zinc-400">—</p>
                                        )}

                                        {billingImminent && (
                                            <p className="mt-1 text-xs text-zinc-400">
                                                Billing in {days_until_billing} {days_until_billing === 1 ? 'day' : 'days'}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                )}

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
                                {isOnTrial && !currentPlan && workspaceInfo.trial_ends_at && (
                                    <p className="text-sm text-zinc-500">
                                        Trial ends {formatDateOnly(workspaceInfo.trial_ends_at)}
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
                                            className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 transition-colors"
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

                                        <div className="flex items-center gap-3">
                                            <button
                                                type="button"
                                                disabled={subscribing}
                                                onClick={handleSubscribe}
                                                className="rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors"
                                            >
                                                {subscribing ? 'Redirecting…' : `Subscribe — €${plan_minimum}/mo + ${ratePct}% of ${billableLabel}`}
                                            </button>
                                        </div>
                                        <p className="text-xs text-zinc-400">
                                            Usage-based billing. You're charged €{plan_minimum}/mo minimum plus {ratePct}% of {billableLabel} each month.
                                        </p>
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
                                        <Label htmlFor="billing_name">Full name</Label>
                                        <Input id="billing_name" value={detailsForm.data.billing_name} onChange={(e) => detailsForm.setData('billing_name', e.target.value)} className="mt-1" placeholder="Jane Smith" required />
                                        {detailsForm.errors.billing_name && <p className="mt-1 text-sm text-red-600">{detailsForm.errors.billing_name}</p>}
                                    </div>
                                    <div>
                                        <Label htmlFor="billing_email">Billing email</Label>
                                        <Input id="billing_email" type="email" value={detailsForm.data.billing_email} onChange={(e) => detailsForm.setData('billing_email', e.target.value)} className="mt-1" required />
                                        {detailsForm.errors.billing_email && <p className="mt-1 text-sm text-red-600">{detailsForm.errors.billing_email}</p>}
                                    </div>
                                </div>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="company">Company (optional)</Label>
                                        <Input id="company" value={detailsForm.data['billing_address.company']} onChange={(e) => detailsForm.setData('billing_address.company', e.target.value as never)} className="mt-1" placeholder={workspaceInfo.name} />
                                    </div>
                                    <div>
                                        <Label htmlFor="vat_number">VAT number (optional)</Label>
                                        <Input id="vat_number" value={detailsForm.data.vat_number} onChange={(e) => detailsForm.setData('vat_number', e.target.value)} className="mt-1" placeholder="DE123456789" />
                                        {detailsForm.errors.vat_number && <p className="mt-1 text-sm text-red-600">{detailsForm.errors.vat_number}</p>}
                                    </div>
                                </div>
                                <div className="sm:max-w-xs">
                                    <Label htmlFor="country">Country</Label>
                                    <select id="country" value={detailsForm.data['billing_address.country']} onChange={(e) => { detailsForm.setData('billing_address.country', e.target.value as never); detailsForm.setData('billing_address.state', '' as never); }} className="mt-1 block w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                                        <option value="">Select country…</option>
                                        {COUNTRIES.map((c) => (<option key={c.code} value={c.code}>{c.name}</option>))}
                                    </select>
                                    {detailsForm.errors['billing_address.country'] && <p className="mt-1 text-sm text-red-600">{detailsForm.errors['billing_address.country']}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="line1">Address line 1</Label>
                                    <Input id="line1" value={detailsForm.data['billing_address.line1']} onChange={(e) => detailsForm.setData('billing_address.line1', e.target.value as never)} className="mt-1" placeholder="Street address" />
                                </div>
                                <div>
                                    <Label htmlFor="line2">Address line 2 (optional)</Label>
                                    <Input id="line2" value={detailsForm.data['billing_address.line2']} onChange={(e) => detailsForm.setData('billing_address.line2', e.target.value as never)} className="mt-1" placeholder="Suite, floor, etc." />
                                </div>
                                <div className="grid grid-cols-3 gap-4">
                                    <div className="col-span-2">
                                        <Label htmlFor="city">City</Label>
                                        <Input id="city" value={detailsForm.data['billing_address.city']} onChange={(e) => detailsForm.setData('billing_address.city', e.target.value as never)} className="mt-1" />
                                    </div>
                                    <div>
                                        <Label htmlFor="postal_code">Postcode</Label>
                                        <Input id="postal_code" value={detailsForm.data['billing_address.postal_code']} onChange={(e) => detailsForm.setData('billing_address.postal_code', e.target.value as never)} className="mt-1" />
                                    </div>
                                </div>
                                {stateLabel && (
                                    <div className="sm:max-w-xs">
                                        <Label htmlFor="state">{stateLabel}</Label>
                                        <Input id="state" value={detailsForm.data['billing_address.state']} onChange={(e) => detailsForm.setData('billing_address.state', e.target.value as never)} className="mt-1" placeholder={selectedCountry === 'US' ? 'CA' : ''} />
                                        {detailsForm.errors['billing_address.state'] && <p className="mt-1 text-sm text-red-600">{detailsForm.errors['billing_address.state']}</p>}
                                    </div>
                                )}
                                <div className="flex items-center gap-4 pt-1">
                                    <button type="submit" disabled={detailsForm.processing} className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors">
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
                                    <button type="button" onClick={() => setShowAddCard(true)} className="mt-3 text-sm text-primary hover:text-primary/70 transition-colors">
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
                                            <button type="button" onClick={() => router.post(w(`/settings/billing/payment-methods/${pm.id}/default`))} className="text-xs text-primary hover:text-primary/70 transition-colors">
                                                Set as default
                                            </button>
                                        )}
                                        <button type="button" onClick={() => { if (confirm('Remove this payment method?')) router.delete(w(`/settings/billing/payment-methods/${pm.id}`)); }} className="text-xs text-red-500 hover:text-red-700 transition-colors">
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
                                                router.post(w(`/settings/billing/payment-methods/${pmId}/confirm`));
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
                                                <a href={inv.download_url} className="text-xs text-primary hover:text-primary/70 transition-colors mr-3">PDF</a>
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
