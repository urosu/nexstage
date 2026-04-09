import GuestLayout from '@/Layouts/GuestLayout';
import { PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

export default function NoWorkspace({ auth }: PageProps) {
    const isVerified = !!auth.user?.email_verified_at;

    const { post, processing } = useForm();

    const resend = () => {
        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title="No Workspace" />

            <div className="text-center">
                <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-zinc-100">
                    <svg
                        className="h-7 w-7 text-zinc-400"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth={1.5}
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"
                        />
                    </svg>
                </div>

                {isVerified ? (
                    <>
                        <h1 className="text-lg font-semibold text-zinc-900">No workspace</h1>
                        <p className="mt-2 text-sm text-zinc-600">
                            You are not a member of any workspace yet.
                            Connect your first store to get started.
                        </p>
                        <div className="mt-6">
                            <Link
                                href={route('onboarding')}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                Connect a store
                            </Link>
                        </div>
                    </>
                ) : (
                    <>
                        <h1 className="text-lg font-semibold text-zinc-900">Verify your email</h1>
                        <p className="mt-2 text-sm text-zinc-600">
                            Please verify your email address before connecting a store.
                            Check your inbox for a verification link.
                        </p>
                        <div className="mt-6 flex flex-col items-center gap-3">
                            <Link
                                href={route('verification.notice')}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700"
                            >
                                Go to email verification
                            </Link>
                            <button
                                onClick={resend}
                                disabled={processing}
                                className="cursor-pointer text-sm text-zinc-500 hover:text-zinc-700 disabled:opacity-50"
                            >
                                Resend verification email
                            </button>
                        </div>
                    </>
                )}
            </div>
        </GuestLayout>
    );
}
