import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { formatDateOnly } from '@/lib/formatters';
import { FormEventHandler } from 'react';

interface InvitationProps {
    token: string;
    workspace_name: string;
    role: string;
    expires_at: string;
}

const ROLE_LABELS: Record<string, string> = {
    owner: 'Owner',
    admin: 'Admin',
    member: 'Member',
};

export default function Show({ invitation }: { invitation: InvitationProps }) {
    const { post, processing } = useForm({});

    const accept: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('invitations.accept', invitation.token));
    };

    return (
        <GuestLayout>
            <Head title="Workspace Invitation" />

            <div className="text-center">
                <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-indigo-100">
                    <svg
                        className="h-7 w-7 text-indigo-600"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={1.5}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"
                        />
                    </svg>
                </div>

                <h1 className="text-lg font-semibold text-zinc-900">
                    Join {invitation.workspace_name}
                </h1>
                <p className="mt-2 text-sm text-zinc-600">
                    You've been invited as{' '}
                    <strong>{ROLE_LABELS[invitation.role] ?? invitation.role}</strong>.
                </p>
                <p className="mt-1 text-xs text-zinc-400">
                    Invitation expires {formatDateOnly(invitation.expires_at)}
                </p>

                <form onSubmit={accept} className="mt-6">
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex items-center rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-60"
                    >
                        {processing ? 'Joining…' : 'Accept invitation'}
                    </button>
                </form>
            </div>
        </GuestLayout>
    );
}
