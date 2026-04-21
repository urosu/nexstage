import { Link, router } from '@inertiajs/react';

interface Props {
    children: React.ReactNode;
    currentStep: 1 | 2 | 3 | 4;
}

// Related: resources/js/Pages/Onboarding/Index.tsx (only consumer)
export default function OnboardingLayout({ children, currentStep }: Props) {
    const steps = ['Connect', 'Store setup', 'Choose history', 'Importing data'];

    return (
        <div className="flex min-h-screen flex-col items-center bg-zinc-50 px-4 py-12">
            {/* Logo + logout */}
            <div className="mb-8 flex w-full max-w-lg items-center justify-between">
                <Link href="/" className="text-xl font-bold tracking-tight text-zinc-900">
                    Nexstage
                </Link>
                <button
                    type="button"
                    onClick={() => router.post(route('logout'))}
                    className="text-sm text-zinc-400 hover:text-zinc-600"
                >
                    Log out
                </button>
            </div>

            {/* Step indicator */}
            <div className="mb-8 flex items-center gap-3">
                {steps.map((label, idx) => {
                    const num = idx + 1;
                    const done = num < currentStep;
                    const active = num === currentStep;
                    return (
                        <div key={label} className="flex items-center gap-3">
                            <div className="flex items-center gap-2">
                                <div
                                    className={[
                                        'flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold',
                                        done
                                            ? 'bg-primary text-primary-foreground'
                                            : active
                                              ? 'bg-primary text-primary-foreground ring-2 ring-primary ring-offset-2'
                                              : 'bg-zinc-200 text-zinc-500',
                                    ].join(' ')}
                                >
                                    {done ? (
                                        <svg
                                            className="h-3.5 w-3.5"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            strokeWidth={3}
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                d="M5 13l4 4L19 7"
                                            />
                                        </svg>
                                    ) : (
                                        num
                                    )}
                                </div>
                                <span
                                    className={[
                                        'text-sm font-medium',
                                        active ? 'text-zinc-900' : 'text-zinc-400',
                                    ].join(' ')}
                                >
                                    {label}
                                </span>
                            </div>
                            {idx < steps.length - 1 && (
                                <div className="h-px w-8 bg-zinc-300" />
                            )}
                        </div>
                    );
                })}
            </div>

            {/* Card */}
            <div className="w-full max-w-lg rounded-xl border border-zinc-200 bg-white p-8 shadow-sm">
                {children}
            </div>
        </div>
    );
}
