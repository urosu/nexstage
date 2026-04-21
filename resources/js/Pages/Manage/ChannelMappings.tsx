import { useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { GitBranch, Plus, Pencil, Trash2, Zap, Download, Info } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
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
import { formatCurrency, formatNumber } from '@/lib/formatters';
import { wurl } from '@/lib/workspace-url';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

// ── Types ──────────────────────────────────────────────────────────────

interface MappingRow {
    id: number;
    utm_source_pattern: string;
    utm_medium_pattern: string | null;
    channel_name: string;
    channel_type: string;
    is_global: boolean;
}

interface UnrecognizedRow {
    source: string;
    medium: string | null;
    order_count: number;
    revenue: number;
}

interface Props {
    workspace_mappings: MappingRow[];
    global_mappings: MappingRow[];
    unrecognized: UnrecognizedRow[];
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

export default function ChannelMappings({ workspace_mappings, global_mappings, unrecognized }: Props) {
    const { workspace, errors } = usePage<PageProps>().props;
    const currency = workspace?.reporting_currency ?? 'EUR';

    // Build an override set so we can grey out global rows the workspace has
    // replaced — the workspace row wins at classify time.
    const overrideKeys = useMemo(
        () => new Set(workspace_mappings.map(m => `${m.utm_source_pattern}|${m.utm_medium_pattern ?? ''}`)),
        [workspace_mappings],
    );

    // Dialog state
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [form, setForm] = useState<FormData>(emptyForm);
    const [submitting, setSubmitting] = useState(false);

    function openCreate(prefill?: Partial<FormData>): void {
        setEditingId(null);
        setForm({ ...emptyForm, ...prefill });
        setDialogOpen(true);
    }

    function openEdit(row: MappingRow): void {
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
            ? wurl(workspace?.slug, `/manage/channel-mappings/${editingId}`)
            : wurl(workspace?.slug, '/manage/channel-mappings');
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

    function handleDelete(row: MappingRow): void {
        if (!window.confirm(`Delete override "${row.channel_name}" (${row.utm_source_pattern})?`)) return;
        router.delete(wurl(workspace?.slug, `/manage/channel-mappings/${row.id}`), { preserveScroll: true });
    }

    function handleImportDefaults(): void {
        if (!window.confirm('Re-seed global channel mapping defaults? This replaces the ~80 built-in mappings. Your workspace overrides are preserved.')) return;
        router.post(wurl(workspace?.slug, '/manage/channel-mappings/import-defaults'), {}, { preserveScroll: true });
    }

    return (
        <AppLayout>
            <Head title="Channel Mappings" />
            <PageHeader
                title="Channel Mappings"
                subtitle="Map UTM source/medium pairs to named channels on /acquisition"
                action={
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={handleImportDefaults} title="Re-seed global defaults">
                            <Download className="h-4 w-4" data-icon="inline-start" />
                            Import defaults
                        </Button>
                        <Button onClick={() => openCreate()}>
                            <Plus className="h-4 w-4" data-icon="inline-start" />
                            Add mapping
                        </Button>
                    </div>
                }
            />

            {/* ── Explainer ─────────────────────────────────────────────── */}
            <div className="mb-6 flex items-start gap-2 rounded-lg border border-zinc-200 bg-zinc-50/50 px-3 py-2 text-xs text-zinc-600">
                <Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-zinc-400" />
                <p>
                    Your workspace overrides win at classify time when they match a global default.
                    Creating or editing an override re-classifies historical orders in the background.
                </p>
            </div>

            {/* ── Unrecognized Sources ─────────────────────────────────── */}
            {unrecognized.length > 0 && (
                <div className="mb-6 rounded-xl border border-amber-200 bg-amber-50/50 p-4">
                    <div className="mb-3 flex items-center gap-2">
                        <Zap className="h-4 w-4 text-amber-600" />
                        <h3 className="text-sm font-semibold text-amber-800">
                            Unclassified traffic in the last 90 days ({unrecognized.length})
                        </h3>
                    </div>
                    <div className="rounded-lg border border-amber-200 bg-white overflow-x-auto">
                        <table className="w-full text-sm min-w-[500px]">
                            <thead>
                                <tr className="border-b border-amber-100 bg-amber-50/50 text-left">
                                    <th className="px-3 py-2 font-medium text-amber-700">Source</th>
                                    <th className="px-3 py-2 font-medium text-amber-700">Medium</th>
                                    <th className="px-3 py-2 font-medium text-amber-700 text-right">Orders</th>
                                    <th className="px-3 py-2 font-medium text-amber-700 text-right">Revenue</th>
                                    <th className="px-3 py-2 font-medium text-amber-700 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-amber-100">
                                {unrecognized.map((u, i) => (
                                    <tr key={i} className="hover:bg-amber-50/30 transition-colors">
                                        <td className="px-3 py-2 font-mono text-xs text-zinc-800">{u.source}</td>
                                        <td className="px-3 py-2 font-mono text-xs text-zinc-500">{u.medium ?? '—'}</td>
                                        <td className="px-3 py-2 text-right tabular-nums text-zinc-600">{formatNumber(u.order_count)}</td>
                                        <td className="px-3 py-2 text-right tabular-nums text-zinc-700">{formatCurrency(u.revenue, currency)}</td>
                                        <td className="px-3 py-2 text-right">
                                            <button
                                                onClick={() => openCreate({
                                                    utm_source_pattern: u.source,
                                                    utm_medium_pattern: u.medium ?? '',
                                                })}
                                                className="rounded px-2 py-1 text-xs font-medium text-amber-700 hover:bg-amber-100 transition-colors"
                                            >
                                                Classify
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* ── Workspace overrides ──────────────────────────────────── */}
            <div className="mb-8">
                <h2 className="mb-2 text-sm font-semibold text-zinc-700">Your overrides</h2>
                {workspace_mappings.length === 0 ? (
                    <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-200 bg-white px-6 py-10 text-center">
                        <GitBranch className="mb-2 h-6 w-6 text-zinc-300" />
                        <p className="text-sm text-zinc-500">No overrides yet — global defaults handle everything below.</p>
                    </div>
                ) : (
                    <div className="rounded-xl border border-zinc-200 bg-white overflow-x-auto">
                        <table className="w-full text-sm min-w-[700px]">
                            <thead>
                                <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                    <th className="px-4 py-3 font-medium text-zinc-400">Source pattern</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400">Medium pattern</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400">Channel name</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400">Type</th>
                                    <th className="px-4 py-3 font-medium text-zinc-400 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-100">
                                {workspace_mappings.map(m => (
                                    <tr key={m.id} className="hover:bg-zinc-50 transition-colors">
                                        <td className="px-4 py-2.5 font-mono text-xs text-zinc-800">{m.utm_source_pattern}</td>
                                        <td className="px-4 py-2.5 font-mono text-xs text-zinc-500">
                                            {m.utm_medium_pattern ?? <span className="text-zinc-300 italic">wildcard</span>}
                                        </td>
                                        <td className="px-4 py-2.5 text-zinc-700">{m.channel_name}</td>
                                        <td className="px-4 py-2.5"><ChannelTypeBadge type={m.channel_type} /></td>
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
            </div>

            {/* ── Global defaults (read-only reference) ────────────────── */}
            <div>
                <h2 className="mb-2 text-sm font-semibold text-zinc-700">
                    Global defaults
                    <span className="ml-2 text-xs font-normal text-zinc-400">
                        {global_mappings.length} built-in mappings
                    </span>
                </h2>
                <div className="rounded-xl border border-zinc-200 bg-white overflow-x-auto">
                    <table className="w-full text-sm min-w-[700px]">
                        <thead>
                            <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                <th className="px-4 py-3 font-medium text-zinc-400">Source pattern</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Medium pattern</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Channel name</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Type</th>
                                <th className="px-4 py-3 font-medium text-zinc-400 text-right">Override</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {global_mappings.map(m => {
                                const key = `${m.utm_source_pattern}|${m.utm_medium_pattern ?? ''}`;
                                const overridden = overrideKeys.has(key);
                                return (
                                    <tr
                                        key={m.id}
                                        className={cn(
                                            'hover:bg-zinc-50 transition-colors',
                                            overridden && 'opacity-40',
                                        )}
                                    >
                                        <td className="px-4 py-2.5 font-mono text-xs text-zinc-800">{m.utm_source_pattern}</td>
                                        <td className="px-4 py-2.5 font-mono text-xs text-zinc-500">
                                            {m.utm_medium_pattern ?? <span className="text-zinc-300 italic">wildcard</span>}
                                        </td>
                                        <td className="px-4 py-2.5 text-zinc-700">{m.channel_name}</td>
                                        <td className="px-4 py-2.5"><ChannelTypeBadge type={m.channel_type} /></td>
                                        <td className="px-4 py-2.5 text-right">
                                            {overridden ? (
                                                <span className="text-xs text-zinc-400 italic">overridden</span>
                                            ) : (
                                                <button
                                                    onClick={() => openCreate({
                                                        utm_source_pattern: m.utm_source_pattern,
                                                        utm_medium_pattern: m.utm_medium_pattern ?? '',
                                                        channel_name: m.channel_name,
                                                        channel_type: m.channel_type,
                                                    })}
                                                    className="rounded px-2 py-1 text-xs font-medium text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 transition-colors"
                                                >
                                                    Override
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* ── Create / Edit Dialog ────────────────────────────────── */}
            <Dialog open={dialogOpen} onOpenChange={(open) => { if (!open) setDialogOpen(false); }}>
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{editingId ? 'Edit override' : 'New channel mapping'}</DialogTitle>
                        <DialogDescription>
                            {editingId
                                ? 'Update the UTM pattern and channel classification.'
                                : 'Map a UTM source/medium pair to a named channel for this workspace.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="utm_source_pattern">Source pattern</Label>
                            <Input
                                id="utm_source_pattern"
                                value={form.utm_source_pattern}
                                onChange={(e) => setForm({ ...form, utm_source_pattern: e.target.value })}
                                placeholder="e.g. klaviyo, hey, newsletter"
                            />
                            {(errors as Record<string, string>).utm_source_pattern && (
                                <p className="text-xs text-red-500">{(errors as Record<string, string>).utm_source_pattern}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="utm_medium_pattern">Medium pattern</Label>
                            <Input
                                id="utm_medium_pattern"
                                value={form.utm_medium_pattern}
                                onChange={(e) => setForm({ ...form, utm_medium_pattern: e.target.value })}
                                placeholder="Leave empty for wildcard"
                            />
                            <p className="text-xs text-zinc-400">Empty = match any medium for this source</p>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="channel_name">Channel name</Label>
                            <Input
                                id="channel_name"
                                value={form.channel_name}
                                onChange={(e) => setForm({ ...form, channel_name: e.target.value })}
                                placeholder="e.g. Email — Hey, Paid — Facebook"
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="channel_type">Channel type</Label>
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
