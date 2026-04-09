import { formatCurrency, formatNumber, formatPercent, formatDate } from '@/lib/formatters';

export function useFormatter() {
    return { formatCurrency, formatNumber, formatPercent, formatDate };
}
