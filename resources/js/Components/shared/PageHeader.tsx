import { ReactNode } from 'react';
import { PageNarrative } from './PageNarrative';

interface PageHeaderProps {
    title: string;
    subtitle?: string;
    action?: ReactNode;
    /**
     * One-sentence server-generated narrative from NarrativeTemplateService.
     * Rendered below the title. Null/undefined → not shown.
     */
    narrative?: string | null;
}

/** Shared page header — title + optional subtitle + optional one-sentence narrative + action slot. */
export function PageHeader({ title, subtitle, action, narrative }: PageHeaderProps) {
    return (
        <div className="mb-6 flex items-start justify-between gap-4">
            <div className="min-w-0 space-y-1">
                <h1 className="text-xl font-semibold text-zinc-900 truncate">{title}</h1>
                {subtitle && (
                    <p className="text-sm text-zinc-600">{subtitle}</p>
                )}
                {narrative && <PageNarrative text={narrative} />}
            </div>
            {action && (
                <div className="flex shrink-0 items-center gap-3">
                    {action}
                </div>
            )}
        </div>
    );
}
