export type Granularity = 'hourly' | 'daily' | 'weekly';

export function formatCurrency(amount: number, currency: string, compact = false): string {
    if (compact && Math.abs(amount) >= 1000) {
        return new Intl.NumberFormat('en', {
            style: 'currency',
            currency,
            notation: 'compact',
            maximumFractionDigits: 1,
        }).format(amount);
    }
    return new Intl.NumberFormat('en', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
}

export function formatNumber(value: number, compact = false): string {
    if (compact && Math.abs(value) >= 1000) {
        return new Intl.NumberFormat('en', {
            notation: 'compact',
            maximumFractionDigits: 1,
        }).format(value);
    }
    return new Intl.NumberFormat('en').format(value);
}

export function formatPercent(value: number): string {
    return new Intl.NumberFormat('en', {
        style: 'percent',
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    }).format(value / 100);
}

/** Format a datetime string or Date as "DD.MM.YYYY HH:mm" in 24h. */
export function formatDatetime(date: string | Date | null | undefined): string {
    if (!date) return '—';
    const d = typeof date === 'string' ? new Date(date) : date;
    const day   = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year  = d.getFullYear();
    const hours = String(d.getHours()).padStart(2, '0');
    const mins  = String(d.getMinutes()).padStart(2, '0');
    return `${day}.${month}.${year} ${hours}:${mins}`;
}

/** Format a date-only string or Date as "DD.MM.YYYY". */
export function formatDateOnly(date: string | Date | null | undefined): string {
    if (!date) return '—';
    const d = typeof date === 'string' ? new Date(date) : date;
    const day   = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year  = d.getFullYear();
    return `${day}.${month}.${year}`;
}

export function formatDate(
    date: string | Date,
    granularity: Granularity,
    timezone?: string,
): string {
    const d = typeof date === 'string' ? new Date(date) : date;
    const options: Intl.DateTimeFormatOptions = {};
    if (timezone) options.timeZone = timezone;

    if (granularity === 'hourly') {
        return new Intl.DateTimeFormat('en', { ...options, hour: 'numeric', hour12: false }).format(d);
    }

    // daily and weekly — D.M.YY
    const day   = new Intl.DateTimeFormat('en', { ...options, day: 'numeric' }).format(d);
    const month = new Intl.DateTimeFormat('en', { ...options, month: 'numeric' }).format(d);
    const year  = String(new Intl.DateTimeFormat('en', { ...options, year: 'numeric' }).format(d)).slice(-2);
    return `${day}.${month}.${year}`;
}
