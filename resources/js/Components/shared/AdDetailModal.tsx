import { Camera, X } from 'lucide-react';
import { useState } from 'react';
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { VideoDropoffChart } from '@/Components/charts/VideoDropoffChart';
import { MotionScoreGauge, VerdictPill } from './MotionScoreGauge';
import { PlatformBadge } from './PlatformBadge';
import type { CreativeCardData } from './CreativeCard';

interface Props {
    card:     CreativeCardData | null;
    currency: string;
    onClose:  () => void;
}

function MetricStat({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col items-center gap-0.5">
            <span className="text-xs text-zinc-500">{label}</span>
            <span className="text-sm font-semibold text-zinc-900">{value}</span>
        </div>
    );
}

export function AdDetailModal({ card, currency, onClose }: Props) {
    const [thumbError, setThumbError] = useState(false);

    if (card === null) return null;

    const showThumb = card.thumbnail_url && !thumbError;
    const hasVideo  = card.video_retention !== null || card.hold_rate_pct !== null;

    const fmtRoas = card.real_roas != null ? `${card.real_roas.toFixed(2)}x` : '—';
    const fmtThumbstop = card.thumbstop_pct != null
        ? `${card.thumbstop_pct.toFixed(1)}%` : '—';
    const fmtHoldRate = card.hold_rate_pct != null
        ? `${card.hold_rate_pct.toFixed(1)}%` : '—';

    return (
        <>
            {/* Backdrop */}
            <div
                className="fixed inset-0 z-40 bg-black/40 backdrop-blur-sm"
                onClick={onClose}
                aria-hidden="true"
            />

            {/* Modal */}
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="ad-detail-title"
                className="fixed left-1/2 top-1/2 z-50 w-full max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white shadow-2xl"
                style={{ maxHeight: 'calc(100dvh - 2rem)' }}
            >
                {/* Close */}
                <button
                    onClick={onClose}
                    className="absolute right-4 top-4 z-10 rounded-lg p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600"
                    aria-label="Close"
                >
                    <X className="h-4 w-4" />
                </button>

                <div className="p-6">
                    {/* ── Header: thumbnail + meta ── */}
                    <div className="flex gap-4">
                        {/* Thumbnail */}
                        <div className="h-28 w-24 shrink-0 overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100">
                            {showThumb ? (
                                <img
                                    src={card.thumbnail_url!}
                                    alt=""
                                    className="h-full w-full object-cover"
                                    onError={() => setThumbError(true)}
                                />
                            ) : (
                                <div className="flex h-full items-center justify-center">
                                    <Camera className="h-6 w-6 text-zinc-300" />
                                </div>
                            )}
                        </div>

                        {/* Meta */}
                        <div className="min-w-0 flex-1 pt-0.5">
                            <div className="mb-1 flex items-center gap-2">
                                <PlatformBadge platform={card.platform} />
                                {card.effective_status && (
                                    <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-zinc-500">
                                        {card.effective_status}
                                    </span>
                                )}
                            </div>
                            <h2
                                id="ad-detail-title"
                                className="mb-0.5 line-clamp-2 text-sm font-semibold text-zinc-900"
                                title={card.ad_name}
                            >
                                {card.ad_name}
                            </h2>
                            <p className="line-clamp-1 text-xs text-zinc-500" title={card.campaign_name}>
                                {card.campaign_name}
                            </p>

                            {/* Verdict + grades */}
                            <div className="mt-3 flex items-center gap-3">
                                <VerdictPill verdict={card.motion_score?.verdict ?? null} />
                                <MotionScoreGauge
                                    score={card.motion_score}
                                    showLabels
                                    size="md"
                                />
                            </div>
                        </div>
                    </div>

                    {/* ── Key metrics ── */}
                    <div className="mt-5 flex items-start justify-around rounded-xl border border-zinc-100 bg-zinc-50 px-4 py-3">
                        <MetricStat
                            label="Spend"
                            value={formatCurrency(card.spend, currency, true)}
                        />
                        <div className="w-px self-stretch bg-zinc-200" />
                        <MetricStat
                            label="Real ROAS"
                            value={fmtRoas}
                        />
                        <div className="w-px self-stretch bg-zinc-200" />
                        <MetricStat
                            label="Impressions"
                            value={formatNumber(card.impressions, true)}
                        />
                        <div className="w-px self-stretch bg-zinc-200" />
                        <MetricStat
                            label="Thumbstop"
                            value={fmtThumbstop}
                        />
                        <div className="w-px self-stretch bg-zinc-200" />
                        <MetricStat
                            label="Hold Rate"
                            value={fmtHoldRate}
                        />
                    </div>

                    {/* ── Retention curve ── */}
                    <div className="mt-5">
                        <div className="mb-2 flex items-center justify-between">
                            <span className="text-xs font-medium text-zinc-600">
                                Video retention curve
                            </span>
                            <span className="text-[10px] text-zinc-400">
                                % of 3-second viewers remaining at each milestone
                            </span>
                        </div>
                        {hasVideo ? (
                            <VideoDropoffChart
                                holdRatePct={card.hold_rate_pct}
                                retention={card.video_retention}
                            />
                        ) : (
                            <div className="flex items-center justify-center rounded-lg border border-dashed border-zinc-200 py-8 text-sm text-zinc-400">
                                Image or carousel ad — no video retention data
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
