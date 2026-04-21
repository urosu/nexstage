import { cn } from '@/lib/utils';

interface Option<T extends string> {
    label: string;
    value: T;
}

interface Props<T extends string> {
    options: Option<T>[];
    value: T;
    onChange: (v: T) => void;
}

export function ToggleGroup<T extends string>({ options, value, onChange }: Props<T>) {
    return (
        <div className="inline-flex rounded-lg border border-zinc-200 bg-zinc-50 p-0.5">
            {options.map((opt) => (
                <button
                    key={opt.value}
                    onClick={() => onChange(opt.value)}
                    className={cn(
                        'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                        value === opt.value
                            ? 'bg-white text-zinc-900 shadow-sm'
                            : 'text-zinc-500 hover:text-zinc-700',
                    )}
                >
                    {opt.label}
                </button>
            ))}
        </div>
    );
}
