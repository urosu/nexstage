import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { TimezoneSelect } from '@/Components/shared/TimezoneSelect';
import { Head, useForm, router } from '@inertiajs/react';
import { wurl } from '@/lib/workspace-url';
import { FormEventHandler, useState } from 'react';

interface WorkspaceProps {
    id: number;
    name: string;
    slug: string;
    reporting_currency: string;
    reporting_timezone: string;
    billing_plan: string | null;
    trial_ends_at: string | null;
    target_roas: number | null;
    target_cpo: number | null;
    holiday_lead_days: number;
    holiday_notification_days: number;
    commercial_notification_days: number;
}

const CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF', 'PLN', 'CZK', 'HUF', 'SEK', 'NOK', 'DKK'];

export default function WorkspaceSettings({
    workspace,
    userRole,
}: {
    workspace: WorkspaceProps;
    userRole: string;
}) {
    const canEdit = userRole === 'owner' || userRole === 'admin';
    const isOwner = userRole === 'owner';
    const w = (path: string) => wurl(workspace.slug, path);

    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        name: workspace.name,
        slug: workspace.slug,
        reporting_currency: workspace.reporting_currency,
        reporting_timezone: workspace.reporting_timezone,
        target_roas: workspace.target_roas !== null ? String(workspace.target_roas) : '',
        target_cpo:  workspace.target_cpo  !== null ? String(workspace.target_cpo)  : '',
        holiday_lead_days: String(workspace.holiday_lead_days),
        holiday_notification_days: String(workspace.holiday_notification_days),
        commercial_notification_days: String(workspace.commercial_notification_days),
    });

    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const deleteForm = useForm({ confirmation: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(w('/settings/workspace'));
    };

    const submitDelete: FormEventHandler = (e) => {
        e.preventDefault();
        deleteForm.delete(w('/settings/workspace'));
    };

    return (
        <AppLayout>
            <Head title="Workspace Settings" />

            <PageHeader title="Workspace" subtitle="General settings for this workspace" />

            <div className="mt-6 max-w-2xl space-y-6">

                {/* General settings */}
                <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                    <div className="border-b border-zinc-200 px-6 py-4">
                        <h3 className="text-base font-semibold text-zinc-900">General</h3>
                    </div>
                    <form onSubmit={submit} className="space-y-5 px-6 py-5">
                        <div>
                            <Label htmlFor="name">Workspace name</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="mt-1"
                                disabled={!canEdit}
                                required
                            />
                            {errors.name && <p className="mt-2 text-sm text-red-600">{errors.name}</p>}
                        </div>

                        <div>
                            <Label htmlFor="slug">Workspace ID (slug)</Label>
                            <Input
                                id="slug"
                                value={data.slug}
                                onChange={(e) => setData('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-'))}
                                className="mt-1 font-mono"
                                disabled={!isOwner}
                            />
                            {errors.slug ? (
                                <p className="mt-1 text-xs text-red-600">{errors.slug}</p>
                            ) : isOwner ? (
                                <p className="mt-1 text-xs text-zinc-400">
                                    Changing this updates all workspace URLs and invalidates any bookmarks.
                                    Lowercase letters, numbers, and hyphens only.
                                </p>
                            ) : (
                                <p className="mt-1 text-xs text-zinc-400">Only the workspace owner can change this.</p>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="reporting_currency">Reporting currency</Label>
                            <select
                                id="reporting_currency"
                                value={data.reporting_currency}
                                onChange={(e) => setData('reporting_currency', e.target.value)}
                                disabled={!canEdit}
                                className="mt-1 block w-full rounded-md border-zinc-300 shadow-sm focus:border-primary focus:ring-primary disabled:opacity-50"
                            >
                                {CURRENCIES.map((c) => (
                                    <option key={c} value={c}>{c}</option>
                                ))}
                            </select>
                            <p className="mt-1 text-xs text-zinc-400">
                                Changing this triggers a background recomputation of all revenue figures.
                            </p>
                            {errors.reporting_currency && <p className="mt-2 text-sm text-red-600">{errors.reporting_currency}</p>}
                        </div>

                        <div>
                            <Label htmlFor="reporting_timezone">Reporting timezone</Label>
                            <TimezoneSelect
                                id="reporting_timezone"
                                value={data.reporting_timezone}
                                onChange={(tz) => setData('reporting_timezone', tz)}
                                disabled={!canEdit}
                                className="mt-1"
                            />
                            {errors.reporting_timezone && <p className="mt-2 text-sm text-red-600">{errors.reporting_timezone}</p>}
                        </div>

                        <div className="border-t border-zinc-100 pt-5">
                            <p className="mb-4 text-sm font-medium text-zinc-700">Performance targets</p>
                            <p className="mb-4 text-xs text-zinc-400">
                                Used to classify campaigns as Winners or Losers. Leave blank to use break-even defaults (1.0× ROAS).
                            </p>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="target_roas">Target ROAS (×)</Label>
                                    <Input
                                        id="target_roas"
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.1"
                                        value={data.target_roas}
                                        onChange={(e) => setData('target_roas', e.target.value)}
                                        className="mt-1"
                                        disabled={!canEdit}
                                        placeholder="e.g. 3.5"
                                    />
                                    {errors.target_roas && <p className="mt-2 text-sm text-red-600">{errors.target_roas}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="target_cpo">Target CPO ({workspace.reporting_currency})</Label>
                                    <Input
                                        id="target_cpo"
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={data.target_cpo}
                                        onChange={(e) => setData('target_cpo', e.target.value)}
                                        className="mt-1"
                                        disabled={!canEdit}
                                        placeholder="e.g. 25.00"
                                    />
                                    {errors.target_cpo && <p className="mt-2 text-sm text-red-600">{errors.target_cpo}</p>}
                                </div>
                            </div>
                        </div>

                        <div className="border-t border-zinc-100 pt-5">
                            <p className="mb-1 text-sm font-medium text-zinc-700">Holiday settings</p>
                            <p className="mb-4 text-xs text-zinc-400">
                                Holidays are detected based on your workspace country. Configure how they appear in charts and when you receive reminders.
                            </p>
                            <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
                                <div>
                                    <Label htmlFor="holiday_lead_days">Chart lead time (days)</Label>
                                    <Input
                                        id="holiday_lead_days"
                                        type="number"
                                        min="0"
                                        max="90"
                                        step="1"
                                        value={data.holiday_lead_days}
                                        onChange={(e) => setData('holiday_lead_days', e.target.value)}
                                        className="mt-1"
                                        disabled={!canEdit}
                                        placeholder="0"
                                    />
                                    <p className="mt-1 text-xs text-zinc-400">
                                        Show chart markers this many days early. 0 = show on the actual date.
                                    </p>
                                    {errors.holiday_lead_days && <p className="mt-2 text-sm text-red-600">{errors.holiday_lead_days}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="holiday_notification_days">Email reminder (days before)</Label>
                                    <Input
                                        id="holiday_notification_days"
                                        type="number"
                                        min="0"
                                        max="90"
                                        step="1"
                                        value={data.holiday_notification_days}
                                        onChange={(e) => setData('holiday_notification_days', e.target.value)}
                                        className="mt-1"
                                        disabled={!canEdit}
                                        placeholder="0"
                                    />
                                    <p className="mt-1 text-xs text-zinc-400">
                                        Send a reminder email to the workspace owner this many days before each holiday. 0 = disabled.
                                    </p>
                                    {errors.holiday_notification_days && <p className="mt-2 text-sm text-red-600">{errors.holiday_notification_days}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="commercial_notification_days">Sale events reminder (days before)</Label>
                                    <Input
                                        id="commercial_notification_days"
                                        type="number"
                                        min="0"
                                        max="90"
                                        step="1"
                                        value={data.commercial_notification_days}
                                        onChange={(e) => setData('commercial_notification_days', e.target.value)}
                                        className="mt-1"
                                        disabled={!canEdit}
                                        placeholder="0"
                                    />
                                    <p className="mt-1 text-xs text-zinc-400">
                                        Remind before Black Friday, Singles' Day, and other ecommerce sale events. 0 = disabled.
                                    </p>
                                    {errors.commercial_notification_days && <p className="mt-2 text-sm text-red-600">{errors.commercial_notification_days}</p>}
                                </div>
                            </div>
                        </div>

                        {canEdit && (
                            <div className="flex items-center gap-4 pt-1">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors"
                                >
                                    Save changes
                                </button>
                                {recentlySuccessful && (
                                    <span className="text-sm text-green-600">Saved.</span>
                                )}
                            </div>
                        )}
                    </form>
                </div>

                {/* Danger zone — owner only */}
                {isOwner && (
                    <div className="overflow-hidden rounded-lg border border-red-200 bg-white">
                        <div className="border-b border-red-200 px-6 py-4">
                            <h3 className="text-base font-semibold text-red-700">Danger zone</h3>
                        </div>
                        <div className="px-6 py-5">
                            <p className="text-sm text-zinc-600">
                                Deleting this workspace permanently removes all data after 30 days.
                                You can restore it within that window.
                            </p>
                            <p className="mt-1 text-sm text-zinc-600">
                                Before deleting, you must cancel any active subscription in{' '}
                                <a href={w('/settings/billing')} className="text-primary hover:underline">
                                    Billing settings
                                </a>
                                .
                            </p>

                            {!showDeleteConfirm ? (
                                <button
                                    type="button"
                                    onClick={() => setShowDeleteConfirm(true)}
                                    className="mt-4 rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 transition-colors"
                                >
                                    Delete workspace
                                </button>
                            ) : (
                                <form onSubmit={submitDelete} className="mt-4 space-y-4">
                                    <div>
                                        <Label htmlFor="confirmation">Type "{workspace.name}" to confirm</Label>
                                        <Input
                                            id="confirmation"
                                            value={deleteForm.data.confirmation}
                                            onChange={(e) =>
                                                deleteForm.setData('confirmation', e.target.value)
                                            }
                                            className="mt-1"
                                            placeholder={workspace.name}
                                        />
                                        {deleteForm.errors.confirmation && <p className="mt-2 text-sm text-red-600">{deleteForm.errors.confirmation}</p>}
                                        {(deleteForm.errors as Record<string, string>).deletion && <p className="mt-2 text-sm text-red-600">{(deleteForm.errors as Record<string, string>).deletion}</p>}
                                    </div>
                                    <div className="flex gap-3">
                                        <button
                                            type="submit"
                                            disabled={
                                                deleteForm.processing ||
                                                deleteForm.data.confirmation !== workspace.name
                                            }
                                            className="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50 transition-colors"
                                        >
                                            Confirm deletion
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setShowDeleteConfirm(false)}
                                            className="text-sm text-zinc-500 hover:text-zinc-700"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </form>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
