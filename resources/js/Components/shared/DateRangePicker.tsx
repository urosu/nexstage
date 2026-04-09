import React, { useState, useCallback } from 'react';
import { format, subDays, startOfMonth, endOfMonth, startOfYear, subMonths, subYears, startOfDay } from 'date-fns';
import type { DateRange as DayPickerRange } from 'react-day-picker';
import { usePage } from '@inertiajs/react';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { CalendarIcon, ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useDateRange, type DateRange, type Granularity } from '@/Hooks/useDateRange';
import type { PageProps } from '@/types';

interface Preset {
    label: string;
    range: () => { from: Date; to: Date };
}

function toIso(date: Date): string {
    return format(date, 'yyyy-MM-dd');
}

function parseIso(str: string): Date {
    const [y, m, d] = str.split('-').map(Number);
    return new Date(y, m - 1, d);
}

function formatDisplayRange(from: string, to: string): string {
    if (!from || !to) return 'Select dates';
    const f = parseIso(from);
    const t = parseIso(to);
    if (toIso(f) === toIso(t)) return format(f, 'MMM d, yyyy');
    if (f.getFullYear() === t.getFullYear()) {
        return `${format(f, 'MMM d')} – ${format(t, 'MMM d, yyyy')}`;
    }
    return `${format(f, 'MMM d, yyyy')} – ${format(t, 'MMM d, yyyy')}`;
}

function detectGranularity(from: string, to: string): Granularity {
    if (!from || !to) return 'daily';
    const days = Math.round((parseIso(to).getTime() - parseIso(from).getTime()) / 86400000);
    if (days <= 3) return 'hourly';
    if (days <= 90) return 'daily';
    return 'weekly';
}

type ComparisonOption = 'previous_period' | 'same_period_last_year' | 'custom' | 'none';

function computeComparisonRange(
    from: string,
    to: string,
    option: ComparisonOption,
): { compare_from: string; compare_to: string } | null {
    if (option === 'none') return null;
    const f = parseIso(from);
    const t = parseIso(to);
    const days = Math.round((t.getTime() - f.getTime()) / 86400000) + 1;

    if (option === 'previous_period') {
        const cf = subDays(f, days);
        const ct = subDays(f, 1);
        return { compare_from: toIso(cf), compare_to: toIso(ct) };
    }
    if (option === 'same_period_last_year') {
        return {
            compare_from: toIso(new Date(f.getFullYear() - 1, f.getMonth(), f.getDate())),
            compare_to: toIso(new Date(t.getFullYear() - 1, t.getMonth(), t.getDate())),
        };
    }
    return null;
}

interface DateRangePickerProps {
    className?: string;
}

export function DateRangePicker({ className }: DateRangePickerProps) {
    const { range, setRange } = useDateRange();
    const { earliest_date } = usePage<PageProps>().props;

    const presets: Preset[] = [
        { label: 'Today', range: () => { const d = new Date(); return { from: d, to: d }; } },
        { label: 'Yesterday', range: () => { const d = subDays(new Date(), 1); return { from: d, to: d }; } },
        { label: 'Last 7 days', range: () => ({ from: subDays(new Date(), 6), to: new Date() }) },
        { label: 'Last 30 days', range: () => ({ from: subDays(new Date(), 29), to: new Date() }) },
        { label: 'Last 90 days', range: () => ({ from: subDays(new Date(), 89), to: new Date() }) },
        { label: 'This month', range: () => ({ from: startOfMonth(new Date()), to: new Date() }) },
        { label: 'Last month', range: () => {
            const last = subMonths(new Date(), 1);
            return { from: startOfMonth(last), to: endOfMonth(last) };
        }},
        { label: 'Last year', range: () => {
            const last = subYears(new Date(), 1);
            return { from: startOfYear(last), to: new Date(last.getFullYear(), 11, 31) };
        }},
        { label: 'All time', range: () => ({
            from: earliest_date ? parseIso(earliest_date) : subYears(new Date(), 5),
            to: new Date(),
        })},
    ];

    // Internal state while the popover is open (not committed yet)
    const [tempFrom, setTempFrom] = useState<Date | undefined>(
        range.from ? parseIso(range.from) : undefined,
    );
    const [tempTo, setTempTo] = useState<Date | undefined>(
        range.to ? parseIso(range.to) : undefined,
    );
    const [comparisonOption, setComparisonOption] = useState<ComparisonOption>(
        range.compare_from ? 'previous_period' : 'none',
    );
    const [customCompareFrom, setCustomCompareFrom] = useState<Date | undefined>(
        range.compare_from ? parseIso(range.compare_from) : undefined,
    );
    const [customCompareTo, setCustomCompareTo] = useState<Date | undefined>(
        range.compare_to ? parseIso(range.compare_to) : undefined,
    );
    const [open, setOpen] = useState(false);

    const handlePreset = useCallback((preset: Preset) => {
        const { from, to } = preset.range();
        setTempFrom(from);
        setTempTo(to);
    }, []);

    const handleCalendarSelect = useCallback((selected: DayPickerRange | undefined) => {
        setTempFrom(selected?.from);
        setTempTo(selected?.to);
    }, []);

    const handleApply = useCallback(() => {
        if (!tempFrom || !tempTo) return;

        const from = toIso(tempFrom);
        const to = toIso(tempTo);
        const granularity = detectGranularity(from, to);

        const newRange: Partial<DateRange> = { from, to, granularity };

        if (comparisonOption === 'custom') {
            if (customCompareFrom && customCompareTo) {
                newRange.compare_from = toIso(customCompareFrom);
                newRange.compare_to = toIso(customCompareTo);
            }
        } else if (comparisonOption !== 'none') {
            const computed = computeComparisonRange(from, to, comparisonOption);
            if (computed) {
                newRange.compare_from = computed.compare_from;
                newRange.compare_to = computed.compare_to;
            }
        } else {
            // Clear comparison
            newRange.compare_from = undefined;
            newRange.compare_to = undefined;
        }

        setRange(newRange);
        setOpen(false);
    }, [tempFrom, tempTo, comparisonOption, customCompareFrom, customCompareTo, setRange]);

    const displayLabel = formatDisplayRange(range.from, range.to);
    const hasComparison = !!range.compare_from;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                className={cn(
                    'flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm text-zinc-700 shadow-xs hover:bg-zinc-50 transition-colors',
                    className,
                )}
            >
                <CalendarIcon className="h-3.5 w-3.5 text-zinc-400 shrink-0" />
                <span className="font-medium">{displayLabel}</span>
                {hasComparison && (
                    <span className="rounded bg-indigo-50 px-1.5 py-0.5 text-xs font-medium text-indigo-600">
                        vs {formatDisplayRange(range.compare_from!, range.compare_to!)}
                    </span>
                )}
                <ChevronDown className="h-3.5 w-3.5 text-zinc-400 shrink-0" />
            </PopoverTrigger>

            <PopoverContent
                side="bottom"
                align="start"
                className="w-auto max-w-[700px] p-0"
            >
                <div className="flex">
                    {/* Presets */}
                    <div className="w-36 shrink-0 border-r border-zinc-100 p-2 space-y-0.5">
                        <div className="px-2 py-1 text-xs font-medium text-zinc-400 uppercase tracking-wide">
                            Presets
                        </div>
                        {presets.map((preset) => {
                            const { from, to } = preset.range();
                            const active =
                                tempFrom && tempTo &&
                                toIso(from) === toIso(tempFrom) &&
                                toIso(to) === toIso(tempTo);
                            return (
                                <button
                                    key={preset.label}
                                    onClick={() => handlePreset(preset)}
                                    className={cn(
                                        'w-full rounded-md px-2 py-1.5 text-left text-sm transition-colors',
                                        active
                                            ? 'bg-indigo-50 text-indigo-700 font-medium'
                                            : 'text-zinc-600 hover:bg-zinc-50',
                                    )}
                                >
                                    {preset.label}
                                </button>
                            );
                        })}
                    </div>

                    {/* Calendar + comparison + actions */}
                    <div className="flex flex-col">
                        {/* Two-month calendar for range selection */}
                        <div className="p-3">
                            <Calendar
                                mode="range"
                                numberOfMonths={2}
                                selected={
                                    tempFrom
                                        ? { from: tempFrom, to: tempTo }
                                        : undefined
                                }
                                onSelect={handleCalendarSelect}
                                disabled={{ after: new Date() }}
                                defaultMonth={
                                    tempFrom
                                        ? new Date(tempFrom.getFullYear(), tempFrom.getMonth() - 1)
                                        : subMonths(new Date(), 1)
                                }
                            />
                        </div>

                        <Separator />

                        {/* Comparison */}
                        <div className="px-4 py-3 space-y-2">
                            <div className="text-xs font-medium text-zinc-500 uppercase tracking-wide">
                                Compare to
                            </div>
                            <div className="flex gap-2 flex-wrap">
                                {(
                                    [
                                        { value: 'none', label: 'None' },
                                        { value: 'previous_period', label: 'Previous period' },
                                        { value: 'same_period_last_year', label: 'Same period last year' },
                                        { value: 'custom', label: 'Custom' },
                                    ] as { value: ComparisonOption; label: string }[]
                                ).map(({ value, label }) => (
                                    <button
                                        key={value}
                                        onClick={() => setComparisonOption(value)}
                                        className={cn(
                                            'rounded-full px-3 py-1 text-xs font-medium border transition-colors',
                                            comparisonOption === value
                                                ? 'border-indigo-600 bg-indigo-50 text-indigo-700'
                                                : 'border-zinc-200 text-zinc-600 hover:border-zinc-300 hover:bg-zinc-50',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>

                            {comparisonOption === 'custom' && (
                                <div className="pt-1">
                                    <Calendar
                                        mode="range"
                                        numberOfMonths={2}
                                        selected={
                                            customCompareFrom
                                                ? { from: customCompareFrom, to: customCompareTo }
                                                : undefined
                                        }
                                        onSelect={(r) => {
                                            setCustomCompareFrom(r?.from);
                                            setCustomCompareTo(r?.to);
                                        }}
                                        disabled={{ after: new Date() }}
                                    />
                                </div>
                            )}
                        </div>

                        <Separator />

                        {/* Actions */}
                        <div className="flex items-center justify-between px-4 py-3">
                            <span className="text-sm text-zinc-500">
                                {tempFrom && tempTo
                                    ? formatDisplayRange(toIso(tempFrom), toIso(tempTo))
                                    : 'Select a date range'}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    size="sm"
                                    disabled={!tempFrom || !tempTo}
                                    onClick={handleApply}
                                    className="bg-indigo-600 text-white hover:bg-indigo-700"
                                >
                                    Apply
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    );
}
