import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface AttentionItem {
    text: string;
    href: string;
    severity: 'critical' | 'warning' | 'info';
}

interface Props {
    items: AttentionItem[];
}

const DOT: Record<AttentionItem['severity'], string> = {
    critical: 'bg-red-500',
    warning:  'bg-amber-400',
    info:     'bg-blue-400',
};

/**
 * Home "Today's Attention" list.
 *
 * Shows up to 5 prioritised items (alerts first, then recommendations). Each item
 * is a one-sentence narrative bullet linked to the relevant destination.
 * Empty state: calm "All clear" message — distinguishes a healthy store from a loading state.
 *
 * Items are computed by DashboardController::computeTodaysAttention().
 * @see PROGRESS.md §Phase 3.6 — Home rebuild
 */
export function TodaysAttention({ items }: Props) {
    return (
        <div className="flex h-full flex-col rounded-lg border border-zinc-200 bg-white p-4">
            <h3 className="mb-3 text-sm font-semibold text-zinc-800">Today's Attention</h3>

            {items.length === 0 ? (
                <div className="flex flex-1 flex-col items-center justify-center gap-2 py-6 text-center">
                    <CheckCircle className="h-8 w-8 text-green-400" />
                    <p className="text-sm font-medium text-zinc-700">All clear</p>
                    <p className="text-xs text-zinc-400">No issues detected today</p>
                </div>
            ) : (
                <ul className="space-y-2">
                    {items.map((item, i) => (
                        <li key={i}>
                            <Link
                                href={item.href}
                                className="group flex items-start gap-2.5 rounded-md px-2 py-1.5 text-sm text-zinc-700 transition-colors hover:bg-zinc-50"
                            >
                                <span
                                    className={cn(
                                        'mt-1.5 h-2 w-2 flex-shrink-0 rounded-full',
                                        DOT[item.severity],
                                    )}
                                />
                                <span className="flex-1 leading-snug">{item.text}</span>
                                <ArrowRight className="mt-0.5 h-3.5 w-3.5 flex-shrink-0 text-zinc-300 transition-colors group-hover:text-zinc-500" />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
