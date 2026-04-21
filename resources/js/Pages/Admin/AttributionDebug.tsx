import { Head } from '@inertiajs/react';
import AppLayout from '@/Components/layouts/AppLayout';
import { PageHeader } from '@/Components/shared/PageHeader';
import type { PageProps } from '@/types';

interface OrderData {
    id: number;
    external_id: string;
    occurred_at: string | null;
    workspace_id: number;
    store_name: string | null;
    utm_source: string | null;
    utm_medium: string | null;
    utm_campaign: string | null;
    source_type: string | null;
    attribution_source: string | null;
    raw_meta_keys: string[];
}

interface TouchPoint {
    source?: string;
    medium?: string;
    campaign?: string;
    content?: string;
    term?: string;
    landing_page?: string;
    channel?: string;
    channel_type?: string;
    [key: string]: string | undefined;
}

interface ParsedResult {
    source_type: string;
    first_touch: TouchPoint | null;
    last_touch: TouchPoint | null;
    click_ids: Record<string, string> | null;
    channel: string | null;
    channel_type: string | null;
    raw_data: Record<string, unknown> | null;
}

interface PipelineStep {
    source: string;
    matched: boolean;
    skipped: boolean;
    result: ParsedResult | null;
}

interface Props extends PageProps {
    order: OrderData;
    pipeline: PipelineStep[];
}

function shortClassName(cls: string): string {
    const parts = cls.split('\\');
    return parts[parts.length - 1] ?? cls;
}

function TouchCard({ label, touch }: { label: string; touch: TouchPoint | null }) {
    if (!touch) {
        return (
            <div>
                <p className="text-xs font-medium text-muted-foreground mb-1">{label}</p>
                <p className="text-xs text-muted-foreground italic">null</p>
            </div>
        );
    }
    return (
        <div>
            <p className="text-xs font-medium text-muted-foreground mb-1">{label}</p>
            <dl className="space-y-0.5">
                {Object.entries(touch).map(([k, v]) => v != null && (
                    <div key={k} className="flex gap-2 text-xs">
                        <dt className="text-muted-foreground w-24 shrink-0">{k}</dt>
                        <dd className="font-mono break-all">{v}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

export default function AttributionDebug({ order, pipeline }: Props) {
    const winner = pipeline.find(s => s.matched);

    return (
        <AppLayout>
            <Head title={`Attribution Debug — Order #${order.external_id}`} />

            <div className="max-w-4xl mx-auto px-4 py-8 space-y-6">
                <PageHeader
                    title={`Attribution Debug — Order #${order.external_id}`}
                    subtitle={`Workspace ${order.workspace_id} · Store: ${order.store_name ?? '—'} · ${order.occurred_at ?? '—'}`}
                />

                {/* Order snapshot */}
                <section className="border rounded-lg p-4 space-y-3">
                    <h2 className="font-semibold text-sm">Order input fields</h2>
                    <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-xs">
                        {[
                            ['utm_source', order.utm_source],
                            ['utm_medium', order.utm_medium],
                            ['utm_campaign', order.utm_campaign],
                            ['source_type', order.source_type],
                            ['attribution_source (existing)', order.attribution_source],
                            ['raw_meta keys', order.raw_meta_keys.length ? order.raw_meta_keys.join(', ') : '(empty)'],
                        ].map(([k, v]) => (
                            <div key={String(k)} className="contents">
                                <dt className="text-muted-foreground">{k}</dt>
                                <dd className="font-mono">{v ?? <span className="text-muted-foreground italic">null</span>}</dd>
                            </div>
                        ))}
                    </dl>
                </section>

                {/* Winner badge */}
                {winner ? (
                    <div className="border border-green-200 bg-green-50 rounded-lg p-3 text-sm">
                        <span className="font-semibold text-green-800">Winner:</span>{' '}
                        <span className="text-green-700">{shortClassName(winner.source)}</span>
                        {winner.result?.channel && (
                            <span className="ml-3 text-green-600">
                                → {winner.result.channel} ({winner.result.channel_type})
                            </span>
                        )}
                    </div>
                ) : (
                    <div className="border border-amber-200 bg-amber-50 rounded-lg p-3 text-sm text-amber-800">
                        No source matched — result is <strong>Not Tracked</strong>.
                    </div>
                )}

                {/* Pipeline trace */}
                <section className="space-y-3">
                    <h2 className="font-semibold text-sm">Pipeline trace</h2>
                    {pipeline.map((step, i) => (
                        <div
                            key={i}
                            className={[
                                'border rounded-lg p-4 space-y-3',
                                step.matched  ? 'border-green-300 bg-green-50/50' : '',
                                step.skipped  ? 'opacity-40' : '',
                                !step.matched && !step.skipped ? 'border-muted' : '',
                            ].join(' ')}
                        >
                            <div className="flex items-center gap-3">
                                <span className="text-xs text-muted-foreground w-4">{i + 1}.</span>
                                <span className="font-mono text-sm font-medium">{shortClassName(step.source)}</span>
                                {step.matched && (
                                    <span className="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">matched</span>
                                )}
                                {step.skipped && (
                                    <span className="text-xs bg-muted text-muted-foreground px-2 py-0.5 rounded-full">skipped (first-hit-wins)</span>
                                )}
                                {!step.matched && !step.skipped && (
                                    <span className="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded-full">no match</span>
                                )}
                            </div>

                            {step.result && (
                                <div className="grid grid-cols-2 gap-4 ml-7">
                                    <TouchCard label="first_touch" touch={step.result.first_touch} />
                                    <TouchCard label="last_touch"  touch={step.result.last_touch} />
                                    {step.result.click_ids && (
                                        <div className="col-span-2">
                                            <p className="text-xs font-medium text-muted-foreground mb-1">click_ids</p>
                                            <pre className="text-xs font-mono bg-muted/50 p-2 rounded">
                                                {JSON.stringify(step.result.click_ids, null, 2)}
                                            </pre>
                                        </div>
                                    )}
                                    {step.result.raw_data && (
                                        <div className="col-span-2">
                                            <p className="text-xs font-medium text-muted-foreground mb-1">raw_data</p>
                                            <pre className="text-xs font-mono bg-muted/50 p-2 rounded overflow-auto max-h-48">
                                                {JSON.stringify(step.result.raw_data, null, 2)}
                                            </pre>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    ))}
                </section>
            </div>
        </AppLayout>
    );
}
