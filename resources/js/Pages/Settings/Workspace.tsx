import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Head, useForm, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

interface WorkspaceProps {
    id: number;
    name: string;
    slug: string;
    reporting_currency: string;
    reporting_timezone: string;
    billing_plan: string | null;
    trial_ends_at: string | null;
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

    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        name: workspace.name,
        reporting_currency: workspace.reporting_currency,
        reporting_timezone: workspace.reporting_timezone,
    });

    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const deleteForm = useForm({ confirmation: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('settings.workspace.update'));
    };

    const submitDelete: FormEventHandler = (e) => {
        e.preventDefault();
        deleteForm.delete(route('settings.workspace.destroy'));
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
                            <InputLabel htmlFor="name" value="Workspace name" />
                            <TextInput
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="mt-1 block w-full"
                                disabled={!canEdit}
                                required
                            />
                            <InputError message={errors.name} className="mt-2" />
                        </div>

                        <div>
                            <InputLabel htmlFor="slug" value="Slug" />
                            <TextInput
                                id="slug"
                                value={workspace.slug}
                                className="mt-1 block w-full bg-zinc-50 text-zinc-500"
                                disabled
                                readOnly
                            />
                            <p className="mt-1 text-xs text-zinc-400">The slug is permanent and cannot be changed.</p>
                        </div>

                        <div>
                            <InputLabel htmlFor="reporting_currency" value="Reporting currency" />
                            <select
                                id="reporting_currency"
                                value={data.reporting_currency}
                                onChange={(e) => setData('reporting_currency', e.target.value)}
                                disabled={!canEdit}
                                className="mt-1 block w-full rounded-md border-zinc-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:opacity-50"
                            >
                                {CURRENCIES.map((c) => (
                                    <option key={c} value={c}>{c}</option>
                                ))}
                            </select>
                            <p className="mt-1 text-xs text-zinc-400">
                                Changing this triggers a background recomputation of all revenue figures.
                            </p>
                            <InputError message={errors.reporting_currency} className="mt-2" />
                        </div>

                        <div>
                            <InputLabel htmlFor="reporting_timezone" value="Reporting timezone" />
                            <TextInput
                                id="reporting_timezone"
                                value={data.reporting_timezone}
                                onChange={(e) => setData('reporting_timezone', e.target.value)}
                                className="mt-1 block w-full"
                                disabled={!canEdit}
                                placeholder="Europe/Berlin"
                            />
                            <p className="mt-1 text-xs text-zinc-400">
                                IANA timezone identifier, e.g. Europe/Berlin, America/New_York.
                            </p>
                            <InputError message={errors.reporting_timezone} className="mt-2" />
                        </div>

                        {canEdit && (
                            <div className="flex items-center gap-4 pt-1">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors"
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
                                <a href={route('settings.billing')} className="text-indigo-600 hover:underline">
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
                                        <InputLabel
                                            htmlFor="confirmation"
                                            value={`Type "${workspace.name}" to confirm`}
                                        />
                                        <TextInput
                                            id="confirmation"
                                            value={deleteForm.data.confirmation}
                                            onChange={(e) =>
                                                deleteForm.setData('confirmation', e.target.value)
                                            }
                                            className="mt-1 block w-full"
                                            placeholder={workspace.name}
                                        />
                                        <InputError message={deleteForm.errors.confirmation} className="mt-2" />
                                        <InputError message={(deleteForm.errors as Record<string, string>).deletion} className="mt-2" />
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
