import { Info } from 'lucide-react';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

/**
 * Inline info icon that shows a balloon tooltip on hover.
 * Place inside a relatively-positioned container — the balloon is absolutely
 * positioned relative to the icon itself.
 *
 * When helpLink is provided the balloon becomes interactive so the user can
 * click the "Learn more" link. The balloon stays visible while the cursor is
 * inside it because it is a child of the .group container.
 *
 * When children are provided they replace the default info icon as the hover
 * trigger — useful for turning an arbitrary element (dot, text, image) into a
 * tooltip target.
 */
export function InfoTooltip({
    content,
    helpLink,
    children,
}: {
    content: ReactNode;
    helpLink?: string;
    children?: ReactNode;
}) {
    return (
        <span className="group relative inline-flex items-center cursor-default">
            {children ?? (
                <Info className="h-3.5 w-3.5 text-zinc-300 group-hover:text-zinc-500 transition-colors" />
            )}
            {/* Balloon */}
            <span
                className={[
                    'invisible group-hover:visible opacity-0 group-hover:opacity-100 transition-opacity duration-150',
                    'absolute bottom-full left-1/2 -translate-x-1/2 mb-2 z-50 w-56',
                    'rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs leading-relaxed text-zinc-600 shadow-lg',
                    helpLink ? 'pointer-events-auto' : 'pointer-events-none',
                ].join(' ')}
            >
                {content}
                {helpLink && (
                    <Link
                        href={helpLink}
                        className="mt-1.5 block font-medium text-primary hover:underline"
                        onClick={(e) => e.stopPropagation()}
                    >
                        Learn more →
                    </Link>
                )}
                {/* Arrow */}
                <span className="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-zinc-200" style={{ marginTop: 0 }} />
                <span className="absolute top-full left-1/2 -translate-x-1/2 border-[3px] border-transparent border-t-white" style={{ marginTop: '-1px' }} />
            </span>
        </span>
    );
}
