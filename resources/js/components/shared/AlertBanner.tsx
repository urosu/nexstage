import { useState } from 'react';
import { X, AlertTriangle, Info, AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

type Severity = 'info' | 'warning' | 'critical';

interface AlertBannerProps {
    message: string;
    severity?: Severity;
    action?: { label: string; href: string };
    onDismiss?: () => void;
}

const severityConfig: Record<Severity, {
    bg: string;
    border: string;
    text: string;
    icon: React.ComponentType<{ className?: string }>;
}> = {
    info: {
        bg: 'bg-blue-50',
        border: 'border-blue-200',
        text: 'text-blue-800',
        icon: Info,
    },
    warning: {
        bg: 'bg-amber-50',
        border: 'border-amber-200',
        text: 'text-amber-800',
        icon: AlertTriangle,
    },
    critical: {
        bg: 'bg-red-50',
        border: 'border-red-200',
        text: 'text-red-800',
        icon: AlertCircle,
    },
};

/**
 * Transient top-of-page notice. Renders at most one; highest severity wins when
 * multiple alerts are present — pass only the single highest-priority alert.
 */
export function AlertBanner({
    message,
    severity = 'info',
    action,
    onDismiss,
}: AlertBannerProps) {
    const [dismissed, setDismissed] = useState(false);

    if (dismissed) return null;

    const config = severityConfig[severity];
    const Icon = config.icon;

    const handleDismiss = () => {
        setDismissed(true);
        onDismiss?.();
    };

    return (
        <div
            className={cn(
                'mb-4 flex items-start gap-3 rounded-lg border px-4 py-3',
                config.bg,
                config.border,
            )}
            role="alert"
        >
            <Icon className={cn('mt-0.5 h-4 w-4 shrink-0', config.text)} />
            <p className={cn('flex-1 text-sm', config.text)}>{message}</p>
            {action && (
                <a
                    href={action.href}
                    className={cn(
                        'shrink-0 text-sm font-medium underline underline-offset-2',
                        config.text,
                    )}
                >
                    {action.label}
                </a>
            )}
            {onDismiss && (
                <button
                    onClick={handleDismiss}
                    className={cn('shrink-0 rounded p-0.5 hover:opacity-70 transition-opacity', config.text)}
                    aria-label="Dismiss"
                >
                    <X className="h-3.5 w-3.5" />
                </button>
            )}
        </div>
    );
}
