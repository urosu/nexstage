/**
 * UtmCoverageNudgeModal — active nudge shown when UTM coverage drops below 50%
 * after an ad account is connected.
 *
 * Dismissal is persisted in sessionStorage so the modal re-appears on next login
 * (not just after page refresh). This keeps coverage visible until the user
 * actually fixes their UTM tagging.
 *
 * @see PLANNING.md section 16.2 (Coverage check)
 */

import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, X } from 'lucide-react';

const SESSION_KEY = 'utm_coverage_nudge_dismissed';

interface Props {
    /** Coverage percentage (0–100). Null when not yet computed. */
    coveragePct: number | null;
    /** 'red' = <50%, 'amber' = 50–80%, 'green' = ≥80% */
    coverageStatus: 'red' | 'amber' | 'green' | null;
}

export function UtmCoverageNudgeModal({ coveragePct, coverageStatus }: Props) {
    const [dismissed, setDismissed] = useState(() => {
        try {
            return sessionStorage.getItem(SESSION_KEY) === '1';
        } catch {
            return false;
        }
    });

    // Re-check on mount in case sessionStorage was set before component rendered
    useEffect(() => {
        try {
            if (sessionStorage.getItem(SESSION_KEY) === '1') {
                setDismissed(true);
            }
        } catch {
            // sessionStorage unavailable — show the modal
        }
    }, []);

    if (dismissed || coverageStatus !== 'red') return null;

    const pctText = coveragePct != null ? ` (${Math.round(coveragePct)}%)` : '';

    function handleDismiss() {
        try {
            sessionStorage.setItem(SESSION_KEY, '1');
        } catch {
            // ignore
        }
        setDismissed(true);
    }

    return (
        <>
            {/* Backdrop */}
            <div
                className="fixed inset-0 z-40 bg-black/40 backdrop-blur-sm"
                onClick={handleDismiss}
                aria-hidden="true"
            />

            {/* Modal */}
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="utm-nudge-title"
                className="fixed left-1/2 top-1/2 z-50 w-full max-w-md -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-white p-6 shadow-2xl"
            >
                <button
                    onClick={handleDismiss}
                    className="absolute right-4 top-4 rounded-lg p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600"
                    aria-label="Dismiss"
                >
                    <X className="h-4 w-4" />
                </button>

                <div className="mb-4 flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100">
                    <AlertTriangle className="h-5 w-5 text-amber-600" />
                </div>

                <h2 id="utm-nudge-title" className="mb-2 text-base font-semibold text-zinc-900">
                    UTM coverage is low{pctText}
                </h2>

                <p className="mb-1 text-sm text-zinc-600">
                    Only {coveragePct != null ? `${Math.round(coveragePct)}%` : 'a small fraction'} of your
                    last 30 days of orders carry UTM parameters. Without them, Nexstage can't match ad spend
                    to revenue — so every Real ROAS number you see is an estimate.
                </p>

                <p className="mb-5 text-sm text-zinc-600">
                    Use the Tag Generator to build properly tagged URLs for your ad campaigns. A few minutes
                    of setup can dramatically improve attribution accuracy.
                </p>

                <div className="flex items-center gap-3">
                    <Link
                        href="/manage/tag-generator"
                        className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90"
                    >
                        Open Tag Generator
                        <ArrowRight className="h-4 w-4" />
                    </Link>
                    <button
                        onClick={handleDismiss}
                        className="rounded-lg px-4 py-2 text-sm text-zinc-500 hover:bg-zinc-100"
                    >
                        Dismiss
                    </button>
                </div>

                <p className="mt-4 text-xs text-zinc-400">
                    This message will reappear each session until coverage improves above 50%.
                </p>
            </div>
        </>
    );
}
