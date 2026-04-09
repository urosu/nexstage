import { Info } from 'lucide-react';

/**
 * Inline info icon that shows a balloon tooltip on hover.
 * Place inside a relatively-positioned container — the balloon is absolutely
 * positioned relative to the icon itself.
 */
export function InfoTooltip({ content }: { content: string }) {
    return (
        <span className="group relative inline-flex items-center cursor-default">
            <Info className="h-3.5 w-3.5 text-zinc-300 group-hover:text-zinc-500 transition-colors" />
            {/* Balloon */}
            <span className="invisible group-hover:visible opacity-0 group-hover:opacity-100 transition-opacity duration-150 pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-2 z-50 w-56 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs leading-relaxed text-zinc-600 shadow-lg">
                {content}
                {/* Arrow */}
                <span className="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-zinc-200" style={{ marginTop: 0 }} />
                <span className="absolute top-full left-1/2 -translate-x-1/2 border-[3px] border-transparent border-t-white" style={{ marginTop: '-1px' }} />
            </span>
        </span>
    );
}
