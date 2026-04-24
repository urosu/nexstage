import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { wurl } from '@/lib/workspace-url';
import type { PageProps } from '@/types';

export interface StoreCostSettings {
    tax: {
        deduct_tax: boolean;
        default_tax_rate: number | null;
        country_tax_rates: Record<string, number>;
        zero_tax_is_b2b: boolean;
    };
    shipping: {
        cost_mode: 'order' | 'flat' | 'percentage';
        flat_rate: number | null;
        percentage: number | null;
    };
    fixed_monthly_costs: Array<{ name: string; amount: number; currency: string }>;
    cogs: {
        custom_meta_keys: Array<{ key: string; value_type: 'unit' | 'total' }>;
    };
}

export interface StoreData {
    id: number;
    slug: string;
    name: string;
    domain: string;
    currency: string;
    timezone: string;
    status: string;
    type: string;
    primary_country_code: string | null;
    website_url: string | null;
    cost_settings: StoreCostSettings;
}

// Phase 3.8: Products, Countries, SEO, Performance moved to workspace-level destinations,
// but the store-scoped pages still render with those tab keys.
type StoreTab = 'overview' | 'settings' | 'products' | 'countries' | 'seo' | 'performance';

interface StoreLayoutProps {
    store: StoreData;
    activeTab: StoreTab;
    children: React.ReactNode;
}

const TABS: { key: StoreTab; label: string }[] = [
    { key: 'overview', label: 'Overview' },
    { key: 'settings', label: 'Settings' },
];

export function StoreLayout({ store, activeTab, children }: StoreLayoutProps) {
    const { workspace } = usePage<PageProps>().props;
    const w = (path: string) => wurl(workspace?.slug, path);

    return (
        <div>
            {/* Breadcrumb + store header */}
            <div className="mb-5">
                <div className="mb-1 flex items-center gap-1.5 text-sm text-zinc-400">
                    <Link href={w('/stores')} className="hover:text-zinc-600 transition-colors">
                        Stores
                    </Link>
                    <span>/</span>
                    <span className="text-zinc-600">{store.name}</span>
                </div>
                <h1 className="text-xl font-semibold text-zinc-900">{store.name}</h1>
                <p className="text-sm text-zinc-400">{store.domain}</p>
            </div>

            {/* Tab bar */}
            <div className="mb-6 flex gap-0 border-b border-zinc-200">
                {TABS.map((tab) => (
                    <Link
                        key={tab.key}
                        href={w(`/stores/${store.slug}/${tab.key}`)}
                        className={cn(
                            'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors',
                            activeTab === tab.key
                                ? 'border-primary text-primary'
                                : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300',
                        )}
                    >
                        {tab.label}
                    </Link>
                ))}
            </div>

            {children}
        </div>
    );
}
