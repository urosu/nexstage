import { Head, usePage } from '@inertiajs/react';
import {
    TrendingUp,
    TrendingDown,
    BarChart2,
    Package,
    Trophy,
} from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { DateRangePicker } from '@/Components/shared/DateRangePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { AnalyticsTabBar } from '@/Components/shared/AnalyticsTabBar';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { cn } from '@/lib/utils';
import { InfoTooltip } from '@/Components/shared/Tooltip';
import type { PageProps } from '@/types';

// ── Types ──────────────────────────────────────────────────────────────────

interface CampaignRow {
    id: number;
    name: string;
    platform: 'facebook' | 'google' | string;
    spend: number;
    revenue: number | null;
    real_roas: number | null;
}

interface ProductRow {
    external_id: string;
    name: string;
    image_url: string | null;
    revenue: number;
    units: number;
    stock_status: string;
}

interface Props {
    campaigns: { winners: CampaignRow[]; losers: CampaignRow[]; peer_avg_roas: number | null };
    products:  { winners: ProductRow[];  losers: ProductRow[]  };
    from: string;
    to: string;
}

// ── Helpers ────────────────────────────────────────────────────────────────

const PLATFORM_LABEL: Record<string, string> = {
    facebook: 'Meta',
    google:   'Google',
};

// ── Sub-components ─────────────────────────────────────────────────────────

function SectionHeader({
    icon: Icon,
    title,
    tooltip,
    empty,
}: {
    icon: React.ComponentType<{ className?: string }>;
    title: string;
    tooltip?: string;
    empty?: boolean;
}) {
    return (
        <div className={cn('flex items-center gap-2 mb-3', empty && 'opacity-50')}>
            <Icon className="h-4 w-4 text-zinc-400" />
            <h2 className="text-sm font-semibold text-zinc-700 uppercase tracking-wider">{title}</h2>
            {tooltip && <InfoTooltip content={tooltip} />}
        </div>
    );
}

function ColumnHeader({ variant }: { variant: 'winners' | 'losers' }) {
    const isWinners = variant === 'winners';
    return (
        <div className={cn(
            'flex items-center gap-1.5 px-3 py-2 rounded-t-lg text-xs font-semibold uppercase tracking-wider',
            isWinners
                ? 'bg-emerald-50 text-emerald-700 border border-emerald-100'
                : 'bg-red-50 text-red-700 border border-red-100',
        )}>
            {isWinners
                ? <TrendingUp className="h-3.5 w-3.5" />
                : <TrendingDown className="h-3.5 w-3.5" />
            }
            {isWinners ? 'Winners' : 'Losers'}
        </div>
    );
}

function EmptyColumn({ variant }: { variant: 'winners' | 'losers' }) {
    return (
        <div className={cn(
            'flex flex-col rounded-b-lg border border-t-0 px-3 py-6 items-center justify-center',
            variant === 'winners' ? 'border-emerald-100' : 'border-red-100',
        )}>
            <span className="text-xs text-zinc-400">No data for this period</span>
        </div>
    );
}

// Campaign rows: name, platform badge, spend, real_roas
function CampaignList({
    items,
    variant,
    currency,
    peerAvg,
}: {
    items: CampaignRow[];
    variant: 'winners' | 'losers';
    currency: string;
    peerAvg: number | null;
}) {
    if (items.length === 0) return <EmptyColumn variant={variant} />;

    const isWinners = variant === 'winners';
    const borderColor = isWinners ? 'border-emerald-100' : 'border-red-100';

    return (
        <div className={cn('rounded-b-lg border border-t-0 divide-y divide-zinc-50', borderColor)}>
            {items.map((c, i) => (
                <div key={c.id} className="flex items-center gap-3 px-3 py-2.5 hover:bg-zinc-50 transition-colors">
                    <span className="w-4 shrink-0 text-xs text-zinc-300 font-medium text-right">
                        {i + 1}
                    </span>
                    <div className="flex-1 min-w-0">
                        <div className="text-sm text-zinc-800 truncate font-medium">{c.name}</div>
                        <div className="text-xs text-zinc-400">
                            {PLATFORM_LABEL[c.platform] ?? c.platform}
                            {' · '}
                            {formatCurrency(c.spend, currency, true)} spend
                        </div>
                    </div>
                    <div className="shrink-0 text-right">
                        <div className={cn(
                            'text-sm font-semibold tabular-nums',
                            isWinners ? 'text-emerald-700' : 'text-red-600',
                        )}>
                            {c.real_roas !== null ? `${c.real_roas}×` : '—'}
                        </div>
                        {c.real_roas !== null && peerAvg !== null && (
                            <div className="text-[10px] text-zinc-400 tabular-nums">vs avg {peerAvg}×</div>
                        )}
                    </div>
                </div>
            ))}
        </div>
    );
}

// Product rows: image, name, units, revenue
function ProductList({
    items,
    variant,
    currency,
}: {
    items: ProductRow[];
    variant: 'winners' | 'losers';
    currency: string;
}) {
    if (items.length === 0) return <EmptyColumn variant={variant} />;

    const isWinners = variant === 'winners';
    const borderColor = isWinners ? 'border-emerald-100' : 'border-red-100';

    return (
        <div className={cn('rounded-b-lg border border-t-0 divide-y divide-zinc-50', borderColor)}>
            {items.map((p, i) => (
                <div key={p.external_id} className="flex items-center gap-3 px-3 py-2.5 hover:bg-zinc-50 transition-colors">
                    <span className="w-4 shrink-0 text-xs text-zinc-300 font-medium text-right">
                        {i + 1}
                    </span>
                    {p.image_url ? (
                        <img src={p.image_url} alt="" className="h-7 w-7 shrink-0 rounded object-cover bg-zinc-100" />
                    ) : (
                        <div className="h-7 w-7 shrink-0 rounded bg-zinc-100 flex items-center justify-center">
                            <Package className="h-3.5 w-3.5 text-zinc-300" />
                        </div>
                    )}
                    <div className="flex-1 min-w-0">
                        <div className="text-sm text-zinc-800 truncate font-medium">{p.name}</div>
                        <div className="text-xs text-zinc-400">{formatNumber(p.units)} units</div>
                    </div>
                    <div className={cn(
                        'text-sm font-semibold tabular-nums shrink-0',
                        isWinners ? 'text-emerald-700' : 'text-red-600',
                    )}>
                        {formatCurrency(p.revenue, currency, true)}
                    </div>
                </div>
            ))}
        </div>
    );
}

// ── Page ───────────────────────────────────────────────────────────────────

export default function WinnersLosers({ campaigns, products }: Props) {
    const { workspace } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'USD';
    const peerAvg  = campaigns.peer_avg_roas ?? null;

    const hasCampaigns = campaigns.winners.length > 0 || campaigns.losers.length > 0;
    const hasProducts  = products.winners.length > 0  || products.losers.length > 0;

    return (
        <AppLayout dateRangePicker={<DateRangePicker />}>
            <Head title="Winners & Losers" />

            <PageHeader title="Reports" subtitle="Winners & Losers" />

            <AnalyticsTabBar />

            <div className="space-y-10">
                {/* Campaigns */}
                {hasCampaigns && (
                    <section>
                        <SectionHeader
                            icon={BarChart2}
                            title="Campaigns"
                            tooltip="Ranked by Real ROAS — revenue attributed to the campaign divided by its ad spend. Winners are the top half; losers are the bottom half, relative to the average across all active campaigns."
                        />
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <ColumnHeader variant="winners" />
                                <CampaignList items={campaigns.winners} variant="winners" currency={currency} peerAvg={peerAvg} />
                            </div>
                            <div>
                                <ColumnHeader variant="losers" />
                                <CampaignList items={campaigns.losers} variant="losers" currency={currency} peerAvg={peerAvg} />
                            </div>
                        </div>
                        <p className="mt-2 text-xs text-zinc-400">
                            Ranked by Real ROAS (revenue ÷ ad spend){peerAvg !== null ? ` · peer avg ${peerAvg}×` : ''} · top half are winners, bottom half losers
                        </p>
                    </section>
                )}

                {/* Products */}
                {hasProducts && (
                    <section>
                        <SectionHeader
                            icon={Package}
                            title="Products"
                            tooltip="Ranked by revenue for the selected period. Top half by revenue are winners; bottom half are losers. Out-of-stock products are excluded from losers since you can't act on them."
                        />
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <ColumnHeader variant="winners" />
                                <ProductList items={products.winners} variant="winners" currency={currency} />
                            </div>
                            <div>
                                <ColumnHeader variant="losers" />
                                <ProductList items={products.losers} variant="losers" currency={currency} />
                            </div>
                        </div>
                        <p className="mt-2 text-xs text-zinc-400">
                            Ranked by revenue for this period · top half = winners, bottom half = losers · out-of-stock products excluded from losers
                        </p>
                    </section>
                )}

                {/* Nothing connected yet */}
                {!hasCampaigns && !hasProducts && (
                    <div className="rounded-xl border border-zinc-100 bg-zinc-50 px-6 py-16 text-center">
                        <Trophy className="mx-auto h-8 w-8 text-zinc-300 mb-3" />
                        <p className="text-sm font-medium text-zinc-500">No data yet</p>
                        <p className="text-xs text-zinc-400 mt-1">
                            Connect your store and ad accounts to start seeing winners and losers.
                        </p>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
