import { ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    subtitle?: string;
    action?: ReactNode;
}

/** Shared page header — title + optional subtitle + optional action slot. */
export function PageHeader({ title, subtitle, action }: PageHeaderProps) {
    return (
        <div className="mb-6 flex items-start justify-between gap-4">
            <div className="min-w-0">
                <h1 className="text-xl font-semibold text-zinc-900 truncate">{title}</h1>
                {subtitle && (
                    <p className="mt-0.5 text-sm text-zinc-600">{subtitle}</p>
                )}
            </div>
            {action && (
                <div className="flex shrink-0 items-center gap-3">
                    {action}
                </div>
            )}
        </div>
    );
}
