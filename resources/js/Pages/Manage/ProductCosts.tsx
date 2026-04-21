import { useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { type FormDataConvertible } from '@inertiajs/core';
import { DollarSign, Plus, Pencil, Trash2, Upload, Download, Info, FileSpreadsheet, Calendar } from 'lucide-react';
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
import { formatCurrency } from '@/lib/formatters';
import { wurl } from '@/lib/workspace-url';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

// ── Types ──────────────────────────────────────────────────────────────

interface CostRow {
    id: number;
    store_id: number;
    store_name: string | null;
    product_external_id: string;
    unit_cost: number;
    currency: string;
    effective_from: string | null;
    effective_to: string | null;
    source: 'manual' | 'csv';
}

interface StoreOption {
    id: number;
    name: string;
    currency: string;
}

interface ProductOption {
    id: number;
    external_id: string;
    name: string;
    sku: string | null;
    store_id: number;
}

interface ImportResult {
    inserted: number;
    updated: number;
    failed: number;
    errors: string[];
}

interface Props {
    costs: CostRow[];
    stores: StoreOption[];
    products: ProductOption[];
}

// ── Helpers ────────────────────────────────────────────────────────────

function SourceBadge({ source }: { source: 'manual' | 'csv' }) {
    const cls = source === 'csv'
        ? 'bg-purple-100 text-purple-700'
        : 'bg-blue-100 text-blue-700';
    return (
        <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', cls)}>
            {source}
        </span>
    );
}

function ActiveBadge() {
    return (
        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
            Active
        </span>
    );
}

// ── Form state ─────────────────────────────────────────────────────────

interface CostForm {
    store_id: string;
    product_external_ids: string[];
    unit_cost: string;
    currency: string;
    effective_from: string;
    effective_to: string;
}

const emptyForm: CostForm = {
    store_id: '',
    product_external_ids: [],
    unit_cost: '',
    currency: '',
    effective_from: '',
    effective_to: '',
};

// Edit form reuses the single-product path (product is locked after creation)
interface EditFormData {
    product_external_id: string;
    unit_cost: string;
    currency: string;
    effective_from: string;
    effective_to: string;
}

// ── Component ──────────────────────────────────────────────────────────

export default function ProductCosts({ costs, stores, products }: Props) {
    const { workspace, errors, flash } = usePage<PageProps & { flash?: { import_result?: ImportResult } }>().props;

    // ── Table multi-select ─────────────────────────────────────────────
    const [selected, setSelected] = useState<Set<number>>(new Set());

    const allSelected = costs.length > 0 && selected.size === costs.length;
    const someSelected = selected.size > 0;

    function toggleAll(): void {
        setSelected(allSelected ? new Set() : new Set(costs.map(r => r.id)));
    }

    function toggleOne(id: number): void {
        setSelected(prev => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }

    function handleBulkDelete(): void {
        if (!window.confirm(`Delete ${selected.size} selected cost entr${selected.size === 1 ? 'y' : 'ies'}?`)) return;
        router.delete(wurl(workspace?.slug, '/manage/product-costs'), {
            data: { ids: Array.from(selected) },
            preserveScroll: true,
            onSuccess: () => setSelected(new Set()),
        });
    }

    // ── Create dialog ──────────────────────────────────────────────────
    const [createOpen, setCreateOpen] = useState(false);
    const [form, setForm] = useState<CostForm>(emptyForm);
    const [productSearch, setProductSearch] = useState('');
    const [submitting, setSubmitting] = useState(false);

    // ── Edit dialog ────────────────────────────────────────────────────
    const [editOpen, setEditOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editForm, setEditForm] = useState<EditFormData>({
        product_external_id: '',
        unit_cost: '',
        currency: '',
        effective_from: '',
        effective_to: '',
    });
    const [editSubmitting, setEditSubmitting] = useState(false);

    // ── CSV import dialog ──────────────────────────────────────────────
    const [csvDialogOpen, setCsvDialogOpen] = useState(false);
    const [csvFile, setCsvFile] = useState<File | null>(null);
    const [csvSubmitting, setCsvSubmitting] = useState(false);
    const [importResult, setImportResult] = useState<ImportResult | null>(
        (flash as { import_result?: ImportResult } | undefined)?.import_result ?? null,
    );

    const defaultStoreId = stores.length === 1 ? String(stores[0].id) : '';

    function openCreate(): void {
        const storeId = defaultStoreId;
        const currency = stores.find(s => String(s.id) === storeId)?.currency ?? '';
        setForm({ ...emptyForm, store_id: storeId, currency });
        setProductSearch('');
        setCreateOpen(true);
    }

    function openEdit(row: CostRow): void {
        setEditingId(row.id);
        setEditForm({
            product_external_id: row.product_external_id,
            unit_cost:           String(row.unit_cost),
            currency:            row.currency,
            effective_from:      row.effective_from ?? '',
            effective_to:        row.effective_to ?? '',
        });
        setEditOpen(true);
    }

    function handleCreate(): void {
        setSubmitting(true);
        router.post(
            wurl(workspace?.slug, '/manage/product-costs'),
            form as unknown as Record<string, FormDataConvertible>,
            {
                preserveScroll: true,
                onSuccess: () => { setCreateOpen(false); setSubmitting(false); },
                onError:   () => setSubmitting(false),
            },
        );
    }

    function handleUpdate(): void {
        if (!editingId) return;
        setEditSubmitting(true);
        router.put(
            wurl(workspace?.slug, `/manage/product-costs/${editingId}`),
            editForm as unknown as Record<string, string>,
            {
                preserveScroll: true,
                onSuccess: () => { setEditOpen(false); setEditSubmitting(false); },
                onError:   () => setEditSubmitting(false),
            },
        );
    }

    function handleDelete(row: CostRow): void {
        if (!window.confirm(`Delete cost entry for product "${row.product_external_id}"?`)) return;
        router.delete(wurl(workspace?.slug, `/manage/product-costs/${row.id}`), { preserveScroll: true });
    }

    function handleStoreChange(storeId: string): void {
        const currency = stores.find(s => String(s.id) === storeId)?.currency ?? '';
        setForm(f => ({ ...f, store_id: storeId, product_external_ids: [], currency }));
        setProductSearch('');
    }

    // Filtered products for the create dropdown
    const filteredProducts = useMemo(() => {
        const byStore = form.store_id
            ? products.filter(p => String(p.store_id) === form.store_id)
            : products;
        const q = productSearch.toLowerCase();
        return q
            ? byStore.filter(p =>
                p.name.toLowerCase().includes(q) ||
                p.external_id.includes(q) ||
                (p.sku ?? '').toLowerCase().includes(q),
              )
            : byStore;
    }, [products, form.store_id, productSearch]);

    function toggleProduct(externalId: string): void {
        setForm(f => {
            const ids = f.product_external_ids.includes(externalId)
                ? f.product_external_ids.filter(id => id !== externalId)
                : [...f.product_external_ids, externalId];
            return { ...f, product_external_ids: ids };
        });
    }

    // ── CSV helpers ────────────────────────────────────────────────────

    function handleCsvSubmit(): void {
        if (!csvFile) return;
        setCsvSubmitting(true);
        const data = new FormData();
        data.append('file', csvFile);
        router.post(wurl(workspace?.slug, '/manage/product-costs/import'), data as unknown as Record<string, string>, {
            preserveScroll: true,
            onSuccess: (page) => {
                const result = (page.props as { flash?: { import_result?: ImportResult } }).flash?.import_result;
                setImportResult(result ?? null);
                setCsvFile(null);
                setCsvSubmitting(false);
            },
            onError: () => setCsvSubmitting(false),
        });
    }

    const errs = errors as Record<string, string>;
    const createDisabled = submitting || form.product_external_ids.length === 0 || !form.unit_cost || !form.currency;

    return (
        <AppLayout>
            <Head title="Product Costs" />
            <PageHeader
                title="Product Costs"
                subtitle="Manual COGS fallback when no WooCommerce native COGS data is available"
                action={
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            render={<a href={wurl(workspace?.slug, '/manage/product-costs/template')} />}
                        >
                            <Download className="h-4 w-4" data-icon="inline-start" />
                            CSV template
                        </Button>
                        <Button variant="outline" onClick={() => { setImportResult(null); setCsvDialogOpen(true); }}>
                            <Upload className="h-4 w-4" data-icon="inline-start" />
                            Import CSV
                        </Button>
                        <Button onClick={openCreate}>
                            <Plus className="h-4 w-4" data-icon="inline-start" />
                            Add cost
                        </Button>
                    </div>
                }
            />

            {/* ── Explainer ─────────────────────────────────────────────── */}
            <div className="mb-6 flex items-start gap-2 rounded-lg border border-zinc-200 bg-zinc-50/50 px-3 py-2 text-xs text-zinc-600">
                <Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-zinc-400" />
                <p>
                    Nexstage reads unit cost from WooCommerce's native COGS feature (Analytics → Settings → Cost of Goods Sold).
                    Entries below are used as a fallback when that data isn't available.
                    Costs are date-ranged so historical contribution margin stays accurate when prices change.
                </p>
            </div>

            {/* ── Bulk delete bar ───────────────────────────────────────── */}
            {someSelected && (
                <div className="mb-3 flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5">
                    <span className="text-sm text-red-700 font-medium">
                        {selected.size} selected
                    </span>
                    <Button
                        variant="outline"
                        onClick={handleBulkDelete}
                        className="h-7 border-red-300 px-3 text-xs text-red-600 hover:bg-red-100 hover:text-red-700"
                    >
                        <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                        Delete selected
                    </Button>
                    <button
                        onClick={() => setSelected(new Set())}
                        className="ml-auto text-xs text-red-500 hover:text-red-700"
                    >
                        Clear
                    </button>
                </div>
            )}

            {/* ── Cost table ────────────────────────────────────────────── */}
            {costs.length === 0 ? (
                <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-200 bg-white px-6 py-10 text-center">
                    <DollarSign className="mb-2 h-6 w-6 text-zinc-300" />
                    <p className="text-sm text-zinc-500">No product costs yet — add one manually or import a CSV.</p>
                </div>
            ) : (
                <div className="rounded-xl border border-zinc-200 bg-white overflow-x-auto">
                    <table className="w-full text-sm min-w-[700px]">
                        <thead>
                            <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                                <th className="pl-4 pr-2 py-3 w-8">
                                    <input
                                        type="checkbox"
                                        checked={allSelected}
                                        onChange={toggleAll}
                                        className="rounded border-zinc-300"
                                        title="Select all"
                                    />
                                </th>
                                {stores.length > 1 && <th className="px-4 py-3 font-medium text-zinc-400">Store</th>}
                                <th className="px-4 py-3 font-medium text-zinc-400">Product ID</th>
                                <th className="px-4 py-3 font-medium text-zinc-400 text-right">Unit cost</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Effective from</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Effective to</th>
                                <th className="px-4 py-3 font-medium text-zinc-400">Source</th>
                                <th className="px-4 py-3 font-medium text-zinc-400 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-100">
                            {costs.map(row => (
                                <tr
                                    key={row.id}
                                    className={cn('hover:bg-zinc-50 transition-colors', selected.has(row.id) && 'bg-zinc-50')}
                                >
                                    <td className="pl-4 pr-2 py-2.5">
                                        <input
                                            type="checkbox"
                                            checked={selected.has(row.id)}
                                            onChange={() => toggleOne(row.id)}
                                            className="rounded border-zinc-300"
                                        />
                                    </td>
                                    {stores.length > 1 && (
                                        <td className="px-4 py-2.5 text-zinc-500 text-xs">{row.store_name ?? '—'}</td>
                                    )}
                                    <td className="px-4 py-2.5 font-mono text-xs text-zinc-800">{row.product_external_id}</td>
                                    <td className="px-4 py-2.5 text-right tabular-nums text-zinc-700">
                                        {formatCurrency(row.unit_cost, row.currency)}
                                    </td>
                                    <td className="px-4 py-2.5 text-zinc-500">
                                        {row.effective_from ?? <span className="italic text-zinc-400">Always</span>}
                                    </td>
                                    <td className="px-4 py-2.5 text-zinc-500">
                                        {row.effective_to ?? <ActiveBadge />}
                                    </td>
                                    <td className="px-4 py-2.5"><SourceBadge source={row.source} /></td>
                                    <td className="px-4 py-2.5 text-right">
                                        <div className="flex items-center justify-end gap-1">
                                            <button
                                                onClick={() => openEdit(row)}
                                                className="rounded p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 transition-colors"
                                                title="Edit"
                                            >
                                                <Pencil className="h-3.5 w-3.5" />
                                            </button>
                                            <button
                                                onClick={() => handleDelete(row)}
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

            {/* ── Create dialog ─────────────────────────────────────────── */}
            <Dialog open={createOpen} onOpenChange={(open) => { if (!open) setCreateOpen(false); }}>
                <DialogContent className="sm:max-w-lg flex flex-col max-h-[90dvh]">
                    <DialogHeader>
                        <DialogTitle>Add product cost</DialogTitle>
                        <DialogDescription>
                            Select one or more products and set their unit cost. Used as a fallback when no WooCommerce COGS data is available.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex-1 overflow-y-auto min-h-0 space-y-6 py-1">
                        {/* Store — only when multiple stores */}
                        {stores.length > 1 && (
                            <div className="space-y-2">
                                <Label htmlFor="store_id">Store</Label>
                                <select
                                    id="store_id"
                                    value={form.store_id}
                                    onChange={(e) => handleStoreChange(e.target.value)}
                                    className="h-9 w-full rounded-lg border border-input bg-transparent px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                >
                                    <option value="">Select store…</option>
                                    {stores.map(s => (
                                        <option key={s.id} value={s.id}>{s.name}</option>
                                    ))}
                                </select>
                                {errs.store_id && <p className="text-xs text-red-500">{errs.store_id}</p>}
                            </div>
                        )}

                        {/* Product multi-select */}
                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label>Products</Label>
                                {form.product_external_ids.length > 0 && (
                                    <span className="text-xs text-zinc-500">
                                        {form.product_external_ids.length} selected
                                    </span>
                                )}
                            </div>
                            <Input
                                value={productSearch}
                                onChange={(e) => setProductSearch(e.target.value)}
                                placeholder="Search by name or SKU…"
                            />
                            <div className="max-h-48 overflow-y-auto rounded-lg border border-input bg-transparent divide-y divide-zinc-100">
                                {filteredProducts.length === 0 ? (
                                    <p className="px-3 py-4 text-center text-xs text-zinc-400">No products found</p>
                                ) : (
                                    filteredProducts.map(p => {
                                        const checked = form.product_external_ids.includes(p.external_id);
                                        return (
                                            <label
                                                key={p.external_id}
                                                className={cn(
                                                    'flex cursor-pointer items-center gap-3 px-3 py-2 text-sm transition-colors',
                                                    checked ? 'bg-primary/5' : 'hover:bg-zinc-50',
                                                )}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    onChange={() => toggleProduct(p.external_id)}
                                                    className="rounded border-zinc-300 shrink-0"
                                                />
                                                <span className="flex-1 truncate text-zinc-800">
                                                    {p.name}
                                                    {p.sku && <span className="ml-1.5 text-zinc-400 text-xs">{p.sku}</span>}
                                                </span>
                                                <span className="font-mono text-xs text-zinc-400">#{p.external_id}</span>
                                            </label>
                                        );
                                    })
                                )}
                            </div>
                            {errs['product_external_ids'] && (
                                <p className="text-xs text-red-500">{errs['product_external_ids']}</p>
                            )}
                        </div>

                        {/* Cost + currency */}
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="unit_cost">Unit cost</Label>
                                <Input
                                    id="unit_cost"
                                    type="number"
                                    min="0"
                                    step="0.0001"
                                    value={form.unit_cost}
                                    onChange={(e) => setForm(f => ({ ...f, unit_cost: e.target.value }))}
                                    placeholder="0.00"
                                />
                                {errs.unit_cost && <p className="text-xs text-red-500">{errs.unit_cost}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="currency">Currency</Label>
                                <Input
                                    id="currency"
                                    maxLength={3}
                                    value={form.currency}
                                    onChange={(e) => setForm(f => ({ ...f, currency: e.target.value.toUpperCase() }))}
                                    placeholder="USD"
                                />
                                {errs.currency && <p className="text-xs text-red-500">{errs.currency}</p>}
                            </div>
                        </div>

                        {/* Date range */}
                        <div className="space-y-3">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="effective_from">Effective from</Label>
                                    <Input
                                        id="effective_from"
                                        type="date"
                                        value={form.effective_from}
                                        onChange={(e) => setForm(f => ({ ...f, effective_from: e.target.value }))}
                                    />
                                    <p className="text-xs text-zinc-400">Empty = from the beginning</p>
                                    {errs.effective_from && <p className="text-xs text-red-500">{errs.effective_from}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="effective_to">Effective to</Label>
                                    <Input
                                        id="effective_to"
                                        type="date"
                                        value={form.effective_to}
                                        onChange={(e) => setForm(f => ({ ...f, effective_to: e.target.value }))}
                                    />
                                    <p className="text-xs text-zinc-400">Empty = currently active</p>
                                    {errs.effective_to && <p className="text-xs text-red-500">{errs.effective_to}</p>}
                                </div>
                            </div>
                            <div className="flex items-start gap-2 text-xs text-zinc-400">
                                <Calendar className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <span>
                                    Use date ranges to track cost changes over time — e.g. a supplier increase in July.
                                    Nexstage picks the row covering each order's date so historical margins stay accurate.
                                    Leave both empty if the cost has always been the same.
                                </span>
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCreateOpen(false)} disabled={submitting}>
                            Cancel
                        </Button>
                        <Button onClick={handleCreate} disabled={createDisabled}>
                            {submitting
                                ? 'Saving…'
                                : form.product_external_ids.length > 1
                                    ? `Save ${form.product_external_ids.length} costs`
                                    : 'Save'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ── Edit dialog ───────────────────────────────────────────── */}
            <Dialog open={editOpen} onOpenChange={(open) => { if (!open) setEditOpen(false); }}>
                <DialogContent className="sm:max-w-md flex flex-col max-h-[90dvh]">
                    <DialogHeader>
                        <DialogTitle>Edit product cost</DialogTitle>
                        <DialogDescription>
                            Update the unit cost or date range. Product and store cannot be changed after creation.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex-1 overflow-y-auto min-h-0 space-y-6 py-1">
                        <div className="rounded-lg bg-zinc-50 px-4 py-3 text-xs text-zinc-500">
                            Product ID: <span className="font-mono text-zinc-700">{editForm.product_external_id}</span>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="edit_unit_cost">Unit cost</Label>
                                <Input
                                    id="edit_unit_cost"
                                    type="number"
                                    min="0"
                                    step="0.0001"
                                    value={editForm.unit_cost}
                                    onChange={(e) => setEditForm(f => ({ ...f, unit_cost: e.target.value }))}
                                    placeholder="0.00"
                                />
                                {errs.unit_cost && <p className="text-xs text-red-500">{errs.unit_cost}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="edit_currency">Currency</Label>
                                <Input
                                    id="edit_currency"
                                    maxLength={3}
                                    value={editForm.currency}
                                    onChange={(e) => setEditForm(f => ({ ...f, currency: e.target.value.toUpperCase() }))}
                                    placeholder="USD"
                                />
                                {errs.currency && <p className="text-xs text-red-500">{errs.currency}</p>}
                            </div>
                        </div>

                        <div className="space-y-3">
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="edit_effective_from">Effective from</Label>
                                    <Input
                                        id="edit_effective_from"
                                        type="date"
                                        value={editForm.effective_from}
                                        onChange={(e) => setEditForm(f => ({ ...f, effective_from: e.target.value }))}
                                    />
                                    <p className="text-xs text-zinc-400">Empty = from the beginning</p>
                                    {errs.effective_from && <p className="text-xs text-red-500">{errs.effective_from}</p>}
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="edit_effective_to">Effective to</Label>
                                    <Input
                                        id="edit_effective_to"
                                        type="date"
                                        value={editForm.effective_to}
                                        onChange={(e) => setEditForm(f => ({ ...f, effective_to: e.target.value }))}
                                    />
                                    <p className="text-xs text-zinc-400">Empty = currently active</p>
                                    {errs.effective_to && <p className="text-xs text-red-500">{errs.effective_to}</p>}
                                </div>
                            </div>
                            <div className="flex items-start gap-2 text-xs text-zinc-400">
                                <Calendar className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <span>
                                    Use date ranges to track cost changes over time — e.g. a supplier increase in July.
                                    Nexstage picks the row covering each order's date so historical margins stay accurate.
                                </span>
                            </div>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setEditOpen(false)} disabled={editSubmitting}>
                            Cancel
                        </Button>
                        <Button
                            onClick={handleUpdate}
                            disabled={editSubmitting || !editForm.unit_cost || !editForm.currency}
                        >
                            {editSubmitting ? 'Saving…' : 'Update'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ── CSV import dialog ────────────────────────────────────── */}
            <Dialog open={csvDialogOpen} onOpenChange={(open) => { if (!open) setCsvDialogOpen(false); }}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Import product costs from CSV</DialogTitle>
                        <DialogDescription>
                            Upload a CSV file with your product unit costs. Download the template to see the expected format.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-5 py-1">
                        <div className="rounded-lg bg-zinc-50 px-4 py-3 text-xs text-zinc-500 space-y-1.5">
                            <p className="font-medium text-zinc-600">CSV format rules:</p>
                            <ul className="list-disc pl-4 space-y-1">
                                <li>Either <code>product_external_id</code> or <code>sku</code> required per row</li>
                                <li><code>effective_from</code> empty = applies from the beginning of time</li>
                                <li><code>effective_to</code> empty = currently active</li>
                                <li><code>currency</code> must be a 3-letter ISO code (e.g. USD, EUR)</li>
                            </ul>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="csv_file">CSV file</Label>
                            <label
                                htmlFor="csv_file"
                                className={cn(
                                    'flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed px-4 py-6 text-center transition-colors',
                                    csvFile ? 'border-zinc-300 bg-zinc-50' : 'border-zinc-200 hover:border-zinc-300',
                                )}
                            >
                                <FileSpreadsheet className="h-7 w-7 text-zinc-300" />
                                <span className="text-sm text-zinc-500">
                                    {csvFile ? csvFile.name : 'Click to select a .csv file'}
                                </span>
                                <input
                                    id="csv_file"
                                    type="file"
                                    accept=".csv,text/csv"
                                    className="sr-only"
                                    onChange={(e) => setCsvFile(e.target.files?.[0] ?? null)}
                                />
                            </label>
                        </div>

                        {importResult && (
                            <div className={cn(
                                'rounded-lg border px-4 py-3 text-sm',
                                importResult.failed === 0
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                    : 'border-amber-200 bg-amber-50 text-amber-800',
                            )}>
                                <p className="font-medium mb-2">Import complete</p>
                                <ul className="text-xs space-y-1">
                                    <li>Inserted: {importResult.inserted}</li>
                                    <li>Updated: {importResult.updated}</li>
                                    {importResult.failed > 0 && <li>Failed: {importResult.failed}</li>}
                                </ul>
                                {importResult.errors.length > 0 && (
                                    <ul className="mt-2 text-xs space-y-1 text-amber-700">
                                        {importResult.errors.slice(0, 5).map((e, i) => (
                                            <li key={i}>{e}</li>
                                        ))}
                                        {importResult.errors.length > 5 && (
                                            <li>…and {importResult.errors.length - 5} more</li>
                                        )}
                                    </ul>
                                )}
                            </div>
                        )}
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCsvDialogOpen(false)} disabled={csvSubmitting}>
                            Close
                        </Button>
                        <Button onClick={handleCsvSubmit} disabled={!csvFile || csvSubmitting}>
                            {csvSubmitting ? 'Importing…' : 'Import'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
