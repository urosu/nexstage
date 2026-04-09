import { Head } from '@inertiajs/react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm';
import DeleteUserForm from '@/Pages/Profile/Partials/DeleteUserForm';
import type { PageProps } from '@/types';

export default function Profile({
    mustVerifyEmail,
    status,
}: PageProps<{ mustVerifyEmail: boolean; status?: string }>) {
    return (
        <AppLayout>
            <Head title="Profile" />

            <PageHeader title="Profile" subtitle="Manage your personal account information" />

            <div className="mt-6 max-w-2xl space-y-6">
                <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                    <div className="border-b border-zinc-200 px-6 py-4">
                        <h3 className="text-base font-semibold text-zinc-900">Profile information</h3>
                    </div>
                    <div className="px-6 py-5">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                        />
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
                    <div className="border-b border-zinc-200 px-6 py-4">
                        <h3 className="text-base font-semibold text-zinc-900">Update password</h3>
                    </div>
                    <div className="px-6 py-5">
                        <UpdatePasswordForm />
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border border-red-200 bg-white">
                    <div className="border-b border-red-200 px-6 py-4">
                        <h3 className="text-base font-semibold text-red-700">Delete account</h3>
                    </div>
                    <div className="px-6 py-5">
                        <DeleteUserForm />
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
