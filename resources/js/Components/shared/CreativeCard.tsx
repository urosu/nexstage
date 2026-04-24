import { useState } from 'react';
import { Camera } from 'lucide-react';
import { cn } from '@/lib/utils';
import { PlatformBadge } from './PlatformBadge';
import { MotionScoreGauge, VerdictPill } from './MotionScoreGauge';
import type { MotionScore } from './MotionScoreGauge';
import { InfoTooltip } from './Tooltip';

export interface VideoRetention {
    p25:  number | null;
    p50:  number | null;
    p75:  number | null;
    p100: number | null;
}

export interface CreativeCardData {
    ad_id:             number;
    ad_name:           string;
    campaign_id:       number;
    campaign_name:     string;
    platform:          string;
    status:            string | null;
    effective_status:  string | null;
    thumbnail_url:     string | null;
    headline:          string | null;
    spend:             number;
    impressions:       number;
    clicks:            number;
    real_roas:         number | null;
    attributed_orders: number;
    thumbstop_pct:     number | null;
    hold_rate_pct:     number | null;
    outbound_ctr:      number | null;
    thumbstop_ctr:     number | null;
    cvr:               number | null;
    video_retention:   VideoRetention | null;
    motion_score:      MotionScore | null;
    verdict:           string | null;
    tags:              Record<string, string>;
}

interface Props {
    card:      CreativeCardData;
    selected?: boolean;
    currency:  string;
    formatCurrency: (v: number | null) => string;
    formatPct: (v: number | null) => string;
    onClick?: (card: CreativeCardData) => void;
    onSelect?: (adId: number, selected: boolean) => void;
}

function StatLine({ label, value, tooltip }: { label: string; value: string; tooltip?: string }) {
    return (
        <div className="flex items-center justify-between text-xs">
            <span className="text-zinc-500">{label}</span>
            {tooltip ? (
                <InfoTooltip content={tooltip}>
                    <span className="font-medium text-zinc-800 underline decoration-dotted">{value}</span>
                </InfoTooltip>
            ) : (
                <span className="font-medium text-zinc-800">{value}</span>
            )}
        </div>
    );
}

export function CreativeCard({ card, selected, currency, formatCurrency, formatPct, onClick, onSelect }: Props) {
    const [thumbError, setThumbError] = useState(false);
    const showThumb = card.thumbnail_url && !thumbError;

    const isActive = card.effective_status
        ? ['ACTIVE', 'active', 'delivering', 'enabled'].includes(card.effective_status)
        : ['active', 'enabling', 'delivering'].includes(card.status ?? '');

    const clickRateLabel = card.thumbstop_ctr != null ? 'Thumbstop CTR' : 'Outbound CTR';
    const clickRateValue = card.thumbstop_ctr ?? card.outbound_ctr;

    return (
        <div
            role="button"
            tabIndex={0}
            onClick={() => onClick?.(card)}
            onKeyDown={(e) => e.key === 'Enter' && onClick?.(card)}
            className={cn(
                'relative flex cursor-pointer flex-col rounded-xl border bg-white transition-shadow',
                'hover:shadow-md focus:outline-none focus:ring-2 focus:ring-primary/40',
                selected && 'ring-2 ring-primary border-primary',
                !isActive && 'opacity-60',
            )}
        >
            {/* Multi-select checkbox */}
            {onSelect && (
                <button
                    onClick={(e) => { e.stopPropagation(); onSelect(card.ad_id, !selected); }}
                    className="absolute right-2 top-2 z-10 flex h-5 w-5 items-center justify-center rounded border border-white/70 bg-white/80 shadow-sm backdrop-blur-sm"
                    aria-label={selected ? 'Deselect' : 'Select for comparison'}
                >
                    {selected && <span className="block h-2.5 w-2.5 rounded-sm bg-primary" />}
                </button>
            )}

            {/* Verdict pill — first thing users read per §F11 display order */}
            <div className="px-3 pt-3 pb-1 flex items-center gap-2">
                {card.motion_score?.verdict
                    ? <VerdictPill verdict={card.motion_score.verdict as any} />
                    : <span className="h-5" />
                }
                <PlatformBadge platform={card.platform} />
                {!isActive && (
                    <span className="ml-auto text-[10px] font-medium text-zinc-400 uppercase tracking-wide">
                        Inactive
                    </span>
                )}
            </div>

            {/* Thumbnail */}
            <div className="mx-3 mb-2 aspect-video overflow-hidden rounded-lg bg-zinc-100 flex items-center justify-center">
                {showThumb ? (
                    <img
                        src={card.thumbnail_url!}
                        alt={card.headline ?? card.ad_name}
                        className="h-full w-full object-cover"
                        onError={() => setThumbError(true)}
                    />
                ) : (
                    <div className="flex flex-col items-center gap-1 text-zinc-400">
                        <Camera className="h-6 w-6" />
                        <span className="text-[10px]">No preview</span>
                    </div>
                )}
            </div>

            {/* Motion Score grades — fixed order: Hook → Hold → Click → Convert → Profit */}
            <div className="px-3 pb-2 flex items-center justify-between">
                <MotionScoreGauge score={card.motion_score} showLabels size="md" />
            </div>

            {/* Ad name / headline */}
            <div className="px-3 pb-2">
                <p className="text-xs text-zinc-600 truncate" title={card.headline ?? card.ad_name}>
                    {card.headline ?? card.ad_name}
                </p>
                <p className="text-[10px] text-zinc-400 truncate" title={card.campaign_name}>
                    {card.campaign_name}
                </p>
            </div>

            {/* Stats */}
            <div className="border-t border-zinc-100 px-3 py-2 space-y-1">
                <StatLine
                    label="Spend"
                    value={formatCurrency(card.spend)}
                />
                <StatLine
                    label="Real ROAS"
                    value={card.real_roas != null ? `${card.real_roas}×` : '—'}
                    tooltip="Revenue attributed to this ad's campaign ÷ this ad's spend. Campaign-level proxy — UTM attribution doesn't distinguish individual ads."
                />
                {card.thumbstop_pct != null && (
                    <StatLine label="Thumbstop" value={formatPct(card.thumbstop_pct)} />
                )}
                {card.hold_rate_pct != null && (
                    <StatLine label="Hold Rate" value={formatPct(card.hold_rate_pct)} />
                )}
                <StatLine
                    label={clickRateLabel}
                    value={formatPct(clickRateValue)}
                    tooltip={
                        card.thumbstop_ctr != null
                            ? 'Outbound clicks ÷ 3-second views. Higher = creative drives intent after hook.'
                            : 'Outbound clicks ÷ impressions. Static ad fallback — no video data.'
                    }
                />
            </div>
        </div>
    );
}
