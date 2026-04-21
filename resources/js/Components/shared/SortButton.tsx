import { cn } from '@/lib/utils';

interface Props {
    col: string;
    label: string;
    currentSort: string;
    currentDir: 'asc' | 'desc';
    onSort: (col: string) => void;
}

export function SortButton({ col, label, currentSort, currentDir, onSort }: Props) {
    const active = currentSort === col;
    return (
        <button
            onClick={() => onSort(col)}
            className={cn(
                'inline-flex items-center gap-1 hover:text-zinc-700 transition-colors whitespace-nowrap',
                active ? 'text-primary' : 'text-zinc-400',
            )}
        >
            {label}
            {active && <span className="text-[10px]">{currentDir === 'desc' ? '↓' : '↑'}</span>}
        </button>
    );
}
