import AuthLayout from '@/Components/layouts/AuthLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <AuthLayout>
            <Head title="Forgot Password" />

            <div className="mb-4 text-sm text-zinc-600">
                Forgot your password? No problem. Just let us know your email
                address and we will email you a password reset link that will
                allow you to choose a new one.
            </div>

            {status && (
                <div className="mb-4 text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <form onSubmit={submit}>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    value={data.email}
                    autoFocus
                    onChange={(e) => setData('email', e.target.value)}
                />
                {errors.email && <p className="mt-2 text-sm text-red-600">{errors.email}</p>}

                <div className="mt-4 flex items-center justify-between">
                    <Link
                        href={route('login')}
                        className="rounded-md text-sm text-zinc-600 underline hover:text-zinc-900 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
                    >
                        Back to login
                    </Link>

                    <Button type="submit" disabled={processing}>
                        Email Password Reset Link
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
