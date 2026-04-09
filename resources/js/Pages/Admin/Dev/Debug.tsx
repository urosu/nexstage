import { useState } from 'react';
import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    CheckCircle,
    ChevronDown,
    ChevronRight,
    Clipboard,
    Clock,
    RefreshCw,
    WifiOff,
    XCircle,
} from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { formatDatetime } from '@/lib/formatters';
import type { PageProps } from '@/types';

// ─── Types ────────────────────────────────────────────────────────────────────

interface WorkspaceInfo {
    id: number;
    name: string;
    slug: string;
    billing_plan: string | null;
    trial_ends_at: string | null;
    reporting_currency: string;
    reporting_timezone: string;
    is_orphaned: boolean;
    deleted_at: string | null;
    created_at: string;
}

interface StoreInfo {
    id: number;
    name: string;
    slug: string;
    status: string;
    consecutive_sync_failures: number;
    last_synced_at: string | null;
    historical_import_status: string | null;
}

interface AdAccountInfo {
    id: number;
    platform: string;
    external_id: string;
    name: string;
    currency: string;
    status: string;
    consecutive_sync_failures: number;
    last_synced_at: string | null;
}

interface GscPropertyInfo {
    id: number;
    property_url: string;
    status: string;
    consecutive_sync_failures: number;
    last_synced_at: string | null;
}

interface DebugContext {
    workspace_id: number | null;
    workspace: WorkspaceInfo | null;
    stores: StoreInfo[];
    ad_accounts: AdAccountInfo[];
    gsc_properties: GscPropertyInfo[];
    impersonating: boolean;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

const fmt = formatDatetime;

function StatusBadge({ status }: { status: string }) {
    const map: Record<string, { cls: string; Icon: React.ComponentType<{ className?: string }> }> = {
        active:       { cls: 'bg-green-100 text-green-700',   Icon: CheckCircle },
        completed:    { cls: 'bg-green-100 text-green-700',   Icon: CheckCircle },
        running:      { cls: 'bg-blue-100 text-blue-700',     Icon: RefreshCw },
        pending:      { cls: 'bg-zinc-100 text-zinc-600',     Icon: Clock },
        error:        { cls: 'bg-red-100 text-red-700',       Icon: XCircle },
        failed:       { cls: 'bg-red-100 text-red-700',       Icon: XCircle },
        disconnected: { cls: 'bg-zinc-100 text-zinc-500',     Icon: WifiOff },
        connecting:   { cls: 'bg-amber-100 text-amber-700',   Icon: Clock },
        token_expired:{ cls: 'bg-orange-100 text-orange-700', Icon: AlertTriangle },
    };
    const entry = map[status] ?? { cls: 'bg-zinc-100 text-zinc-600', Icon: Clock };
    const { cls, Icon } = entry;
    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}>
            <Icon className="h-3 w-3" />
            {status}
        </span>
    );
}

function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);
    return (
        <button
            onClick={() => { navigator.clipboard.writeText(text); setCopied(true); setTimeout(() => setCopied(false), 1500); }}
            className="shrink-0 rounded p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 transition-colors"
            title="Copy JSON"
        >
            {copied ? <Check className="h-3.5 w-3.5 text-green-600" /> : <Clipboard className="h-3.5 w-3.5" />}
        </button>
    );
}

function JsonExpander({ data, label }: { data: unknown; label: string }) {
    const [open, setOpen] = useState(false);
    const json = JSON.stringify(data, null, 2);
    return (
        <div className="mt-3 rounded-lg border border-zinc-100 bg-zinc-50">
            <button
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center gap-1.5 px-3 py-2 text-xs font-medium text-zinc-500 hover:text-zinc-700 transition-colors"
            >
                {open ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
                {label}
                <span className="ml-auto">
                    {open && <CopyButton text={json} />}
                </span>
            </button>
            {open && (
                <pre className="overflow-x-auto px-3 pb-3 text-xs text-zinc-700 leading-relaxed">
                    {json}
                </pre>
            )}
        </div>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="rounded-xl border border-zinc-200 bg-white">
            <div className="border-b border-zinc-100 px-5 py-4">
                <h2 className="text-sm font-semibold text-zinc-900">{title}</h2>
            </div>
            <div className="px-5 py-4">{children}</div>
        </div>
    );
}

function KV({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-start gap-4 py-1.5 text-sm">
            <span className="w-40 shrink-0 text-zinc-400">{label}</span>
            <span className="font-medium text-zinc-800 break-all">{value ?? '—'}</span>
        </div>
    );
}

// ─── Page ────────────────────────────────────────────────────────────────────

interface Props extends PageProps {
    context: DebugContext;
}

export default function Debug({ context }: Props) {
    const { workspace_id, workspace, stores, ad_accounts, gsc_properties, impersonating } = context;

    return (
        <AppLayout>
            <Head title="Dev Debug" />
            <PageHeader
                title="Debug"
                subtitle="Current workspace runtime state"
            />

            {impersonating && (
                <div className="mt-4 flex items-center gap-2 rounded-lg border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-700">
                    <AlertTriangle className="h-4 w-4 shrink-0" />
                    Impersonation active — data shown is for the impersonated user's workspace
                </div>
            )}

            <div className="mt-6 space-y-5">
                {/* Workspace */}
                <Section title="Workspace">
                    {!workspace ? (
                        <p className="text-sm text-zinc-500">No active workspace context.</p>
                    ) : (
                        <>
                            <div className="divide-y divide-zinc-50">
                                <KV label="workspace_id" value={<span className="font-mono">{workspace_id}</span>} />
                                <KV label="name"         value={workspace.name} />
                                <KV label="slug"         value={<span className="font-mono">{workspace.slug}</span>} />
                                <KV label="billing_plan" value={workspace.billing_plan ?? <span className="text-zinc-400 font-normal">null</span>} />
                                <KV label="trial_ends_at" value={fmt(workspace.trial_ends_at)} />
                                <KV label="reporting_currency" value={workspace.reporting_currency} />
                                <KV label="reporting_timezone" value={workspace.reporting_timezone} />
                                <KV label="is_orphaned" value={String(workspace.is_orphaned)} />
                                <KV label="deleted_at"  value={fmt(workspace.deleted_at)} />
                                <KV label="created_at"  value={fmt(workspace.created_at)} />
                            </div>
                            <JsonExpander data={workspace} label="Raw JSON" />
                        </>
                    )}
                </Section>

                {/* Stores */}
                <Section title={`Stores (${stores.length})`}>
                    {stores.length === 0 ? (
                        <p className="text-sm text-zinc-500">No stores in this workspace.</p>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-zinc-100 text-left text-xs font-medium text-zinc-400">
                                            <th className="pb-2 pr-4">ID</th>
                                            <th className="pb-2 pr-4">Name</th>
                                            <th className="pb-2 pr-4">Status</th>
                                            <th className="pb-2 pr-4">Failures</th>
                                            <th className="pb-2 pr-4">Import</th>
                                            <th className="pb-2">Last synced</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-zinc-50">
                                        {stores.map((s) => (
                                            <tr key={s.id}>
                                                <td className="py-2 pr-4 font-mono text-zinc-500">{s.id}</td>
                                                <td className="py-2 pr-4 font-medium text-zinc-800">{s.name}</td>
                                                <td className="py-2 pr-4"><StatusBadge status={s.status} /></td>
                                                <td className="py-2 pr-4 text-zinc-600">{s.consecutive_sync_failures}</td>
                                                <td className="py-2 pr-4">
                                                    {s.historical_import_status
                                                        ? <StatusBadge status={s.historical_import_status} />
                                                        : <span className="text-zinc-400">—</span>
                                                    }
                                                </td>
                                                <td className="py-2 text-zinc-500 text-xs">{fmt(s.last_synced_at)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <JsonExpander data={stores} label="Raw JSON" />
                        </>
                    )}
                </Section>

                {/* Ad Accounts */}
                <Section title={`Ad Accounts (${ad_accounts.length})`}>
                    {ad_accounts.length === 0 ? (
                        <p className="text-sm text-zinc-500">No ad accounts in this workspace.</p>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-zinc-100 text-left text-xs font-medium text-zinc-400">
                                            <th className="pb-2 pr-4">ID</th>
                                            <th className="pb-2 pr-4">Platform</th>
                                            <th className="pb-2 pr-4">External ID</th>
                                            <th className="pb-2 pr-4">Name</th>
                                            <th className="pb-2 pr-4">Currency</th>
                                            <th className="pb-2 pr-4">Status</th>
                                            <th className="pb-2 pr-4">Failures</th>
                                            <th className="pb-2">Last synced</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-zinc-50">
                                        {ad_accounts.map((a) => (
                                            <tr key={a.id}>
                                                <td className="py-2 pr-4 font-mono text-zinc-500">{a.id}</td>
                                                <td className="py-2 pr-4 text-zinc-600 capitalize">{a.platform}</td>
                                                <td className="py-2 pr-4 font-mono text-zinc-500 text-xs">{a.external_id}</td>
                                                <td className="py-2 pr-4 font-medium text-zinc-800">{a.name}</td>
                                                <td className="py-2 pr-4 text-zinc-600">{a.currency}</td>
                                                <td className="py-2 pr-4"><StatusBadge status={a.status} /></td>
                                                <td className="py-2 pr-4 text-zinc-600">{a.consecutive_sync_failures}</td>
                                                <td className="py-2 text-zinc-500 text-xs">{fmt(a.last_synced_at)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <JsonExpander data={ad_accounts} label="Raw JSON" />
                        </>
                    )}
                </Section>

                {/* GSC Properties */}
                <Section title={`GSC Properties (${gsc_properties.length})`}>
                    {gsc_properties.length === 0 ? (
                        <p className="text-sm text-zinc-500">No GSC properties in this workspace.</p>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b border-zinc-100 text-left text-xs font-medium text-zinc-400">
                                            <th className="pb-2 pr-4">ID</th>
                                            <th className="pb-2 pr-4">Property URL</th>
                                            <th className="pb-2 pr-4">Status</th>
                                            <th className="pb-2 pr-4">Failures</th>
                                            <th className="pb-2">Last synced</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-zinc-50">
                                        {gsc_properties.map((p) => (
                                            <tr key={p.id}>
                                                <td className="py-2 pr-4 font-mono text-zinc-500">{p.id}</td>
                                                <td className="py-2 pr-4 text-zinc-800 break-all">{p.property_url}</td>
                                                <td className="py-2 pr-4"><StatusBadge status={p.status} /></td>
                                                <td className="py-2 pr-4 text-zinc-600">{p.consecutive_sync_failures}</td>
                                                <td className="py-2 text-zinc-500 text-xs">{fmt(p.last_synced_at)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <JsonExpander data={gsc_properties} label="Raw JSON" />
                        </>
                    )}
                </Section>

                {/* Full context */}
                <Section title="Full context JSON">
                    <p className="text-xs text-zinc-500 mb-3">Everything passed from the controller — paste into bug reports.</p>
                    <JsonExpander data={context} label="Expand full context" />
                </Section>
            </div>
        </AppLayout>
    );
}
