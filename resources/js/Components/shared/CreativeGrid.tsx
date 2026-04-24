import { useState } from 'react';
import { cn } from '@/lib/utils';
import { CreativeCard } from './CreativeCard';
import type { CreativeCardData } from './CreativeCard';

interface Props {
    cards:          CreativeCardData[];
    currency:       string;
    /** When true, only active/delivering ads are shown. Default: true. */
    hideInactive?:  boolean;
    onHideInactiveChange?: (v: boolean) => void;
    /** Called when user clicks a card (e.g. open ad detail modal). */
    onCardClick?:   (card: CreativeCardData) => void;
    className?:     string;
}

function fmt(currency: string) {
    return (v: number | null) => {
        if (v == null || isNaN(v)) return '—';
        return new Intl.NumberFormat(undefined, {
            style: 'currency', currency, minimumFractionDigits: 0, maximumFractionDigits: 0,
        }).format(v);
    };
}

function fmtPct(v: number | null) {
    if (v == null || isNaN(v)) return '—';
    return `${v.toFixed(1)}%`;
}

const MAX_COMPARE = 6;

export function CreativeGrid({
    cards,
    currency,
    hideInactive  = true,
    onHideInactiveChange,
    onCardClick,
    className,
}: Props) {
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());

    const isActive = (c: CreativeCardData) => {
        const eff = (c.effective_status ?? '').toLowerCase();
        const st  = (c.status ?? '').toLowerCase();
        return ['active', 'delivering', 'enabled', 'in process'].some(
            (s) => eff === s || st === s,
        );
    };

    const visible = hideInactive ? cards.filter(isActive) : cards;

    function toggleSelect(adId: number, on: boolean) {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (on) {
                if (next.size < MAX_COMPARE) next.add(adId);
            } else {
                next.delete(adId);
            }
            return next;
        });
    }

    const formatCurrency = fmt(currency);

    if (visible.length === 0) {
        return (
            <div className={cn('flex flex-col items-center justify-center gap-2 py-16 text-zinc-400', className)}>
                <p className="text-sm">No creative data yet.</p>
                <p className="text-xs">Creative cards appear once Meta video metrics sync.</p>
            </div>
        );
    }

    return (
        <div className={cn('flex flex-col', className)}>
            {/* Toolbar */}
            <div className="mb-3 flex items-center justify-between gap-2">
                <span className="text-xs text-zinc-500">
                    {visible.length} ad{visible.length !== 1 ? 's' : ''}
                    {hideInactive ? ' (active)' : ''}
                    {selectedIds.size > 0 && (
                        <span className="ml-1.5 text-primary font-medium">
                            · {selectedIds.size}/{MAX_COMPARE} selected
                        </span>
                    )}
                </span>
                <label className="flex cursor-pointer items-center gap-1.5 text-xs text-zinc-600 select-none">
                    <input
                        type="checkbox"
                        checked={hideInactive}
                        onChange={(e) => onHideInactiveChange?.(e.target.checked)}
                        className="rounded border-zinc-300"
                    />
                    Hide inactive
                </label>
            </div>

            {/* Grid — 2 columns at 40% panel width (each card ~170px) */}
            <div className="grid grid-cols-2 gap-3 overflow-y-auto">
                {visible.map((card) => (
                    <CreativeCard
                        key={card.ad_id}
                        card={card}
                        selected={selectedIds.has(card.ad_id)}
                        currency={currency}
                        formatCurrency={formatCurrency}
                        formatPct={fmtPct}
                        onClick={onCardClick}
                        onSelect={toggleSelect}
                    />
                ))}
            </div>
        </div>
    );
}
