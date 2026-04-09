import React from 'react';
import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export interface StoreData {
    id: number;
    slug: string;
    name: string;
    domain: string;
    currency: string;
    timezone: string;
    status: string;
    type: string;
}

type StoreTab = 'overview' | 'products' | 'countries' | 'seo' | 'settings';

interface StoreLayoutProps {
    store: StoreData;
    activeTab: StoreTab;
    children: React.ReactNode;
}

const TABS: { key: StoreTab; label: string }[] = [
    { key: 'overview',   label: 'Overview'  },
    { key: 'products',   label: 'Products'  },
    { key: 'countries',  label: 'Countries' },
    { key: 'seo',        label: 'SEO'       },
    { key: 'settings',   label: 'Settings'  },
];

export function StoreLayout({ store, activeTab, children }: StoreLayoutProps) {
    return (
        <div>
            {/* Breadcrumb + store header */}
            <div className="mb-5">
                <div className="mb-1 flex items-center gap-1.5 text-sm text-zinc-400">
                    <Link href="/stores" className="hover:text-zinc-600 transition-colors">
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
                        href={`/stores/${store.slug}/${tab.key}`}
                        className={cn(
                            'px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors',
                            activeTab === tab.key
                                ? 'border-indigo-600 text-indigo-600'
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
