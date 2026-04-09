import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function Login({
    status,
    canResetPassword,
    invitation_token,
}: {
    status?: string;
    canResetPassword: boolean;
    invitation_token?: string;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false as boolean,
        invitation_token: invitation_token ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout>
            <Head title="Log in" />

            {invitation_token && (
                <div className="mb-4 rounded-md bg-indigo-50 p-3 text-sm text-indigo-700">
                    Log in to accept your workspace invitation.
                </div>
            )}

            {status && (
                <div className="mb-4 text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="email" value="Email" />

                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1 block w-full"
                        autoComplete="username"
                        isFocused={true}
                        onChange={(e) => setData('email', e.target.value)}
                    />

                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div className="mt-4">
                    <InputLabel htmlFor="password" value="Password" />

                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1 block w-full"
                        autoComplete="current-password"
                        onChange={(e) => setData('password', e.target.value)}
                    />

                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="mt-4 block">
                    <label className="flex items-center">
                        <Checkbox
                            name="remember"
                            checked={data.remember}
                            onChange={(e) =>
                                setData(
                                    'remember',
                                    (e.target.checked || false) as false,
                                )
                            }
                        />
                        <span className="ms-2 text-sm text-gray-600">
                            Remember me
                        </span>
                    </label>
                </div>

                {/* Hidden — carries invitation token through to the controller */}
                <input type="hidden" name="invitation_token" value={data.invitation_token} />

                <div className="mt-4 flex items-center justify-end">
                    {canResetPassword && (
                        <Link
                            href={route('password.request')}
                            className="rounded-md text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        >
                            Forgot your password?
                        </Link>
                    )}

                    <PrimaryButton className="ms-4" disabled={processing}>
                        Log in
                    </PrimaryButton>
                </div>
            </form>

            <div className="mt-6 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                    Dev accounts
                </p>
                <div className="space-y-1 text-xs text-zinc-600">
                    {[
                        { label: 'Super Admin — admin panel + full dashboard', email: 'superadmin@nexstage.dev' },
                        { label: 'Admin — clean account, real data',           email: 'admin@nexstage.dev' },
                        { label: 'Owner — Growth plan, full data',             email: 'owner@nexstage.dev' },
                        { label: 'Trial Owner — 10 days left, no data',        email: 'trial@nexstage.dev' },
                        { label: 'Member — limited permissions',               email: 'member@nexstage.dev' },
                    ].map(({ label, email }) => (
                        <div
                            key={email}
                            className="flex cursor-pointer items-center justify-between rounded px-2 py-1 hover:bg-zinc-100"
                            onClick={() => { setData('email', email); setData('password', 'password'); }}
                        >
                            <span>{label}</span>
                            <span className="font-mono text-zinc-400">{email}</span>
                        </div>
                    ))}
                    <p className="pt-1 text-zinc-400">
                        All passwords: <span className="font-mono">password</span>
                    </p>
                </div>
                <div className="mt-3 border-t border-zinc-200 pt-3">
                    <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                        Useful commands
                    </p>
                    <div className="space-y-1">
                        {[
                            { label: 'Reseed database', cmd: 'docker exec -it nexstage-php php artisan migrate:fresh --seed' },
                            { label: 'Restart Horizon', cmd: 'docker restart nexstage-horizon' },
                            { label: 'Run tests', cmd: 'docker exec -it nexstage-php php artisan test' },
                            { label: 'Clear caches', cmd: 'docker exec -it nexstage-php php artisan optimize:clear' },
                        ].map(({ label, cmd }) => (
                            <div key={cmd} className="rounded bg-zinc-100 px-2 py-1.5">
                                <div className="text-xs text-zinc-500">{label}</div>
                                <div className="mt-0.5 font-mono text-xs text-zinc-700 break-all">{cmd}</div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </GuestLayout>
    );
}
