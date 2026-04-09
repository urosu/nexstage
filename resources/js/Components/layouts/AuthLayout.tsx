import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';
import { Toaster } from '@/components/ui/sonner';

export default function AuthLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-zinc-50 pt-8 sm:justify-center sm:pt-0">
            <div className="mb-6">
                <Link href="/" className="flex items-center gap-2">
                    <span className="text-2xl font-bold text-zinc-900 tracking-tight">
                        Nexstage
                    </span>
                </Link>
            </div>

            <div className="w-full overflow-hidden bg-white px-6 py-6 shadow-sm border border-zinc-200 sm:max-w-md sm:rounded-xl">
                {children}
            </div>

            <Toaster />
        </div>
    );
}
