import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Check, Clipboard } from 'lucide-react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';

// ─── Data ────────────────────────────────────────────────────────────────────

const DEV_ACCOUNTS = [
    { label: 'Super Admin', role: 'super_admin', email: 'superadmin@nexstage.dev', note: 'admin panel + fake data' },
    { label: 'Admin',       role: 'admin',        email: 'admin@nexstage.dev',      note: 'clean account, real data' },
    { label: 'Owner',       role: 'owner',        email: 'owner@nexstage.dev',      note: 'Growth plan, full data' },
    { label: 'Trial Owner', role: 'owner',        email: 'trial@nexstage.dev',      note: '10 days left, no data' },
    { label: 'Member',      role: 'member',       email: 'member@nexstage.dev',     note: 'limited permissions' },
];

const DEV_COMMANDS = [
    { label: 'Reseed database', cmd: 'docker exec -it nexstage-php php artisan migrate:fresh --seed' },
    { label: 'Run tests',       cmd: 'docker exec -it nexstage-php php artisan test' },
    { label: 'Clear caches',    cmd: 'docker exec -it nexstage-php php artisan optimize:clear' },
    { label: 'Horizon',         cmd: 'docker exec -it nexstage-php php artisan horizon' },
    { label: 'Schedule',        cmd: 'docker exec -it nexstage-php php artisan schedule:work' },
    { label: 'Tinker',          cmd: 'docker exec -it nexstage-php php artisan tinker' },
];

// ─── Copy button ─────────────────────────────────────────────────────────────

function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);

    const handleCopy = () => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <button
            onClick={handleCopy}
            className="shrink-0 rounded p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 transition-colors"
            title="Copy"
        >
            {copied
                ? <Check className="h-3.5 w-3.5 text-green-600" />
                : <Clipboard className="h-3.5 w-3.5" />
            }
        </button>
    );
}

// ─── Page ────────────────────────────────────────────────────────────────────

export default function Snippets() {
    return (
        <AppLayout>
            <Head title="Dev Snippets" />
            <PageHeader title="Dev Snippets" subtitle="Quick reference for local development" />

            <div className="mt-6 grid gap-6 lg:grid-cols-2">
                {/* Dev accounts */}
                <div className="rounded-xl border border-zinc-200 bg-white">
                    <div className="border-b border-zinc-100 px-5 py-4">
                        <h2 className="text-sm font-semibold text-zinc-900">Dev accounts</h2>
                        <p className="mt-0.5 text-xs text-zinc-500">Password: <span className="font-mono">password</span></p>
                    </div>
                    <div className="divide-y divide-zinc-100">
                        {DEV_ACCOUNTS.map(({ label, role, email, note }) => (
                            <div key={email} className="flex items-center gap-3 px-5 py-3">
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium text-zinc-900">{label}</span>
                                        <span className="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500">{role}</span>
                                    </div>
                                    <div className="mt-0.5 font-mono text-xs text-zinc-500">{email}</div>
                                    <div className="mt-0.5 text-xs text-zinc-400">{note}</div>
                                </div>
                                <CopyButton text={email} />
                            </div>
                        ))}
                    </div>
                </div>

                {/* Commands */}
                <div className="rounded-xl border border-zinc-200 bg-white">
                    <div className="border-b border-zinc-100 px-5 py-4">
                        <h2 className="text-sm font-semibold text-zinc-900">Commands</h2>
                        <p className="mt-0.5 text-xs text-zinc-500">Docker-based dev environment</p>
                    </div>
                    <div className="divide-y divide-zinc-100">
                        {DEV_COMMANDS.map(({ label, cmd }) => (
                            <div key={cmd} className="px-5 py-3">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-xs font-medium text-zinc-600">{label}</span>
                                    <CopyButton text={cmd} />
                                </div>
                                <div className="mt-1 rounded bg-zinc-50 px-3 py-2 font-mono text-xs text-zinc-700 break-all">
                                    {cmd}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
