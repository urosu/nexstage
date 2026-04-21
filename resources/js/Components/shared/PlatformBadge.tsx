import { cn } from '@/lib/utils';

const PLATFORM_COLORS: Record<string, string> = {
    facebook: 'bg-blue-50 text-blue-700',
    google:   'bg-red-50 text-red-700',
};

export function PlatformBadge({ platform }: { platform: string }) {
    return (
        <span className={cn(
            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize',
            PLATFORM_COLORS[platform] ?? 'bg-zinc-100 text-zinc-500',
        )}>
            {platform}
        </span>
    );
}
