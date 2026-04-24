import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export interface DestinationTab {
    key: string;
    label: string;
    href: string;
}

interface Props {
    tabs: DestinationTab[];
    activeKey: string;
}

/**
 * Generic tab strip used by all 8 destinations (Phase 3.2+).
 * Generalises AnalyticsTabBar and CampaignsTabBar into one primitive.
 * Each tab is an Inertia Link; active state is driven by `activeKey` prop.
 */
export function DestinationTabs({ tabs, activeKey }: Props) {
    return (
        <div className="mb-6 flex border-b border-zinc-200 overflow-x-auto">
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    className={cn(
                        'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors whitespace-nowrap',
                        tab.key === activeKey
                            ? 'border-primary text-primary'
                            : 'border-transparent text-zinc-500 hover:text-zinc-800 hover:border-zinc-300',
                    )}
                >
                    {tab.label}
                </Link>
            ))}
        </div>
    );
}
