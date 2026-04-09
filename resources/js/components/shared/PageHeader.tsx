import { ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    subtitle?: string;
    action?: ReactNode;
}

export function PageHeader({ title, subtitle, action }: PageHeaderProps) {
    return (
        <div className="mb-6 flex items-start justify-between gap-4">
            <div className="min-w-0">
                <h1 className="text-xl font-semibold text-zinc-900 truncate">{title}</h1>
                {subtitle && (
                    <p className="mt-0.5 text-sm text-zinc-600">{subtitle}</p>
                )}
            </div>
            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}
