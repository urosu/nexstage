import AuthLayout from '@/Components/layouts/AuthLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function Register({ invitation_token }: { invitation_token?: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        invitation_token: invitation_token ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout>
            <Head title="Register" />

            {invitation_token && (
                <div className="mb-4 rounded-md bg-primary/10 p-3 text-sm text-primary">
                    You were invited to join a workspace. Create an account to accept.
                </div>
            )}

            <form onSubmit={submit}>
                <div>
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        name="name"
                        value={data.name}
                        className="mt-1"
                        autoComplete="name"
                        autoFocus
                        onChange={(e) => setData('name', e.target.value)}
                        required
                    />
                    {errors.name && <p className="mt-2 text-sm text-red-600">{errors.name}</p>}
                </div>

                <div className="mt-4">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1"
                        autoComplete="username"
                        onChange={(e) => setData('email', e.target.value)}
                        required
                    />
                    {errors.email && <p className="mt-2 text-sm text-red-600">{errors.email}</p>}
                </div>

                <div className="mt-4">
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1"
                        autoComplete="new-password"
                        onChange={(e) => setData('password', e.target.value)}
                        required
                    />
                    {errors.password && <p className="mt-2 text-sm text-red-600">{errors.password}</p>}
                </div>

                <div className="mt-4">
                    <Label htmlFor="password_confirmation">Confirm Password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={data.password_confirmation}
                        className="mt-1"
                        autoComplete="new-password"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        required
                    />
                    {errors.password_confirmation && <p className="mt-2 text-sm text-red-600">{errors.password_confirmation}</p>}
                </div>

                {/* Hidden — carries invitation token through to the controller */}
                <input type="hidden" name="invitation_token" value={data.invitation_token} />

                <div className="mt-4 flex items-center justify-end">
                    <Link
                        href={route('login')}
                        className="rounded-md text-sm text-zinc-600 underline hover:text-zinc-900 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                    >
                        Already registered?
                    </Link>

                    <Button type="submit" className="ms-4" disabled={processing}>
                        Register
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
