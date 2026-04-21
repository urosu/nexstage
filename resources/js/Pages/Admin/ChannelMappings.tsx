import { useEffect, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';

// Why: When Inertia swaps components via flushSync mid-navigation, the new component
// initialises with useState(false) and renders stale cached data before the real server
// response arrives. Tracking navigation state at module level lets us start with
// navigating=true so the skeleton stays visible until the real data is ready.
let _inertiaNavigating = false;
router.on('start',  () => { _inertiaNavigating = true; });
router.on('finish', () => { _inertiaNavigating = false; });

import { GitBranch, ShieldAlert, Plus, Pencil, Trash2, Zap } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
    DialogFooter,
} from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

// ── Types ──────────────────────────────────────────────────────────────

interface ChannelMappingRow {
    id: number;
    utm_source_pattern: string;
    utm_medium_pattern: string | null;
    channel_name: string;
    channel_type: string;
    is_global: boolean;
    created_at: string;
}

interface UnrecognizedSource {
    source: string;
    medium: string | null;
    order_count: number;
    workspace_count: number;
}

interface PaginatedMappings {
    data: ChannelMappingRow[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Props {
    mappings: PaginatedMappings;
    unrecognized: UnrecognizedSource[];
    filters: { search: string };
}

// ── Channel type config ────────────────────────────────────────────────

const CHANNEL_TYPES = [
    'email', 'paid_social', 'paid_search', 'organic_search',
    'organic_social', 'direct', 'referral', 'affiliate', 'sms', 'other',
] as const;

const CHANNEL_TYPE_COLORS: Record<string, string> = {
    email:           'bg-violet-100 text-violet-700',
    paid_social:     'bg-blue-100 text-blue-700',
    paid_search:     'bg-emerald-100 text-emerald-700',
    organic_search:  'bg-green-100 text-green-700',
    organic_social:  'bg-sky-100 text-sky-700',
    direct:          'bg-zinc-100 text-zinc-600',
    referral:        'bg-amber-100 text-amber-700',
    affiliate:       'bg-orange-100 text-orange-700',
    sms:             'bg-pink-100 text-pink-700',
    other:           'bg-zinc-100 text-zinc-500',
};

function ChannelTypeBadge({ type }: { type: string }) {
    const colors = CHANNEL_TYPE_COLORS[type] ?? CHANNEL_TYPE_COLORS.other;
    return (
        <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', colors)}>
            {type.replace('_', ' ')}
        </span>
    );
}

// ── Form state ─────────────────────────────────────────────────────────

interface FormData {
    utm_source_pattern: string;
    utm_medium_pattern: string;
    channel_name: string;
    channel_type: string;
}

const emptyForm: FormData = {
    utm_source_pattern: '',
    utm_medium_pattern: '',
    channel_name: '',
    channel_type: 'other',
};

// ── Component ──────────────────────────────────────────────────────────

export default function ChannelMappings({ mappings, unrecognized, filters }: Props) {
    const { errors } = usePage().props;
    const [search, setSearch] = useState(filters.search);
    const [navigating, setNavigating] = useState(() => _inertiaNavigating);
    const searchTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Dialog state
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [form, setForm] = useState<FormData>(emptyForm);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        const removeStart  = router.on('start',  () => setNavigating(true));
        const removeFinish = router.on('finish', () => setNavigating(false));
        return () => { removeStart(); removeFinish(); };
    }, []);

    function handleSearch(value: string): void {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get('/admin/channel-mappings', { search: value }, { preserveState: true, replace: true });
        }, 350);
    }

    function openCreate(prefill?: Partial<FormData>): void {
        setEditingId(null);
        setForm({ ...emptyForm, ...prefill });
        setDialogOpen(true);
    }

    function openEdit(row: ChannelMappingRow): void {
        setEditingId(row.id);
        setForm({
            utm_source_pattern: row.utm_source_pattern,
            utm_medium_pattern: row.utm_medium_pattern ?? '',
            channel_name: row.channel_name,
            channel_type: row.channel_type,
        });
        setDialogOpen(true);
    }

    function handleSubmit(): void {
        setSubmitting(true);
        const url = editingId
            ? `/admin/channel-mappings/${editingId}`
            : '/admin/channel-mappings';
        const method = editingId ? 'put' : 'post';

        router[method](url, form as unknown as Record<string, string>, {
            preserveScroll: true,
            onSuccess: () => {
                setDialogOpen(false);
                setSubmitting(false);
            },
            onError: () => setSubmitting(false),
        });
    }

    function handleDelete(row: ChannelMappingRow): void {
        if (!window.confirm(`Delete mapping "${row.channel_name}" (${row.utm_source_pattern})?`)) return;
        router.delete(`/admin/channel-mappings/${row.id}`, { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="Admin — Channel Mappings" />
            <PageHeader
                title="Channel Mappings"
                subtitle={`${mappings.total} global mappings`}
                action={
                    <Button variant="outline" onClick={() => openCreate()}>
                        <Plus className="h-4 w-4" data-icon="inline-start" />
                        Add Mapping
                    </Button>
                }
            />

            {/* Admin badge */}
            <div className="mb-4 flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700">
                <ShieldAlert className="h-3.5 w-3.5" />
                Global channel mappings — changes affect all workspaces
            </div>

            {/* ── Unrecognized Sources ─────────────────────────────────── */}
            {unrecognized.length > 0 && (
                <div className="mb-6 rounded-xl border border-amber-200 bg-amber-50/50 p-4">
                    <div className="mb-3 flex items-center gap-2">
                        <Zap className="h-4 w-4 text-amber-600" />
                        <h3 className="text-sm font-semibold text-amber-800">
                            Unrecognized Sources ({unrecognized.length})
                        </h3>
                        <span className="text-xs text-amber-600">Last 90 days</span>
                    </div>
                    <div className="rounded-lg border border-amber-200 bg-white overflow-x-auto">
                        <table className="w-full text-sm min-w-[500px]">
                            <thead>
                                <tr className="border-b border-amber-100 bg-amber-50/50 text-left">
                                    <th className="px-3 py-2 font-medium text-amber-700">Source</th>
                                    <th className="px-3 py-2 font-medium text-amber-700">Medium</th>
                                    <th className="px-3 py-2 font-medium text-amber-700 text-right">Orders</th>
                                    <th className="px-3 py-2 font-medium text-amber-700 text-right">Workspaces</th>
                                    <th className="px-3 py-2 font-medium text-amber-700 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-amber-100">
                                {unrecognized.map((u, i) => (
                                    <tr key={i} className="hover:bg-amber-50/30 transition-colors">
                                        <td className="px-3 py-2 font-mono text-xs text-zinc-800">{u.source}</td>
                                        <td className="px-3 py-2 font-mono text-xs text-zinc-500">{u.medium ?? '—'}</td>
                                        <td className="px-3 py-2 text-right tabular-nums text-zinc-600">{u.order_count}</td>
                                        <td className="px-3 py-2 text-right tabular-nums text-zinc-600">{u.workspace_count}</td>
                                        <td className="px-3 py-2 text-right">
                                            <button
                                                onClick={() => openCreate({
                                                    utm_source_pattern: u.source,
                                                    utm_medium_pattern: u.medium ?? '',
                                                })}
                                                className="rounded px-2 py-1 text-xs font-medium text-amber-700 hover:bg-amber-100 transition-colors"
                                            >
                                                Map
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* ── Search ──────────────────────────────────────────────── */}
            <div className="mb-4">
                <input
                    type="text"
                    value={search}
                    onChange={(e) => handleSearch(e.target.value)}
                    placeholder="Search by source, channel name, or type..."
                    className="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm placeholder-zinc-400 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary sm:max-w-xs"
                />
            </div>

            {/* ── Mappings Table ───────────────────────────────────────── */}
            {navigating ? (
                <div className="space-y-2">
                    {[...Array(8)].map((_, i) => (
                        <div key={i} className="h-12 animate-pulse rounded-xl bg-zinc-100" />
                    ))}
                </div>
            ) : mappings.data.length === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-zinc-200 bg-white px-6 py-16 text-center">
                    <GitBranch className="mb-3 h-8 w-8 text-zinc-300" />
                    <p className="text-sm text-zinc-500">No mappings found.</p>
                </div>
            ) : (
                <div className="rounded-xl border border-zinc-200 bg-white overflow-x-auto">
                    <table className="w-full text-sm min-w-[700px]">
                        <thead>
                            <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                <th className="px-4 py-3 font-medium text-zinc-400">Source Pattern</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Medium Pattern</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Channel Name</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Type</th>
                                <th className="px-4 py-3 font-medium text-zinc-400 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {mappings.data.map((m) => (
                                <tr key={m.id} className="hover:bg-zinc-50 transition-colors">
                                    <td className="px-4 py-2.5 font-mono text-xs text-zinc-800">{m.utm_source_pattern}</td>
                                    <td className="px-4 py-2.5 font-mono text-xs text-zinc-500">
                                        {m.utm_medium_pattern ?? <span className="text-zinc-300 italic">wildcard</span>}
                                    </td>
                                    <td className="px-4 py-2.5 text-zinc-700">{m.channel_name}</td>
                                    <td className="px-4 py-2.5">
                                        <ChannelTypeBadge type={m.channel_type} />
                                    </td>
                                    <td className="px-4 py-2.5 text-right">
                                        <div className="flex items-center justify-end gap-1">
                                            <button
                                                onClick={() => openEdit(m)}
                                                className="rounded p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 transition-colors"
                                                title="Edit"
                                            >
                                                <Pencil className="h-3.5 w-3.5" />
                                            </button>
                                            <button
                                                onClick={() => handleDelete(m)}
                                                className="rounded p-1 text-zinc-400 hover:bg-red-50 hover:text-red-500 transition-colors"
                                                title="Delete"
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* ── Pagination ──────────────────────────────────────────── */}
            {mappings.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-zinc-500">
                    <span>Page {mappings.current_page} of {mappings.last_page}</span>
                    <div className="flex gap-2">
                        {mappings.current_page > 1 && (
                            <button
                                onClick={() => router.get('/admin/channel-mappings', { search, page: mappings.current_page - 1 })}
                                className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium hover:bg-zinc-50 transition-colors"
                            >
                                Previous
                            </button>
                        )}
                        {mappings.current_page < mappings.last_page && (
                            <button
                                onClick={() => router.get('/admin/channel-mappings', { search, page: mappings.current_page + 1 })}
                                className="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium hover:bg-zinc-50 transition-colors"
                            >
                                Next
                            </button>
                        )}
                    </div>
                </div>
            )}

            {/* ── Create / Edit Dialog ────────────────────────────────── */}
            <Dialog open={dialogOpen} onOpenChange={(open) => { if (!open) setDialogOpen(false); }}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{editingId ? 'Edit Mapping' : 'New Channel Mapping'}</DialogTitle>
                        <DialogDescription>
                            {editingId
                                ? 'Update the UTM pattern and channel classification.'
                                : 'Map a UTM source/medium pair to a named channel.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="utm_source_pattern">Source Pattern</Label>
                            <Input
                                id="utm_source_pattern"
                                value={form.utm_source_pattern}
                                onChange={(e) => setForm({ ...form, utm_source_pattern: e.target.value })}
                                placeholder="e.g. google.com, fb, klaviyo"
                            />
                            {(errors as Record<string, string>).utm_source_pattern && (
                                <p className="text-xs text-red-500">{(errors as Record<string, string>).utm_source_pattern}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="utm_medium_pattern">Medium Pattern</Label>
                            <Input
                                id="utm_medium_pattern"
                                value={form.utm_medium_pattern}
                                onChange={(e) => setForm({ ...form, utm_medium_pattern: e.target.value })}
                                placeholder="Leave empty for wildcard"
                            />
                            <p className="text-xs text-zinc-400">Empty = match any medium for this source</p>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="channel_name">Channel Name</Label>
                            <Input
                                id="channel_name"
                                value={form.channel_name}
                                onChange={(e) => setForm({ ...form, channel_name: e.target.value })}
                                placeholder="e.g. Organic — Google, Paid — Facebook"
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="channel_type">Channel Type</Label>
                            <select
                                id="channel_type"
                                value={form.channel_type}
                                onChange={(e) => setForm({ ...form, channel_type: e.target.value })}
                                className="h-8 w-full rounded-lg border border-input bg-transparent px-2.5 py-1 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                            >
                                {CHANNEL_TYPES.map((t) => (
                                    <option key={t} value={t}>{t.replace('_', ' ')}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDialogOpen(false)} disabled={submitting}>
                            Cancel
                        </Button>
                        <Button onClick={handleSubmit} disabled={submitting || !form.utm_source_pattern || !form.channel_name}>
                            {submitting ? 'Saving...' : editingId ? 'Update' : 'Create'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
