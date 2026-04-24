<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiSummary;
use App\Models\Alert;
use App\Models\DailyNote;
use App\Models\InboxItem;
use App\Models\Recommendation;
use App\Services\MonthlyReportService;
use App\Services\NarrativeTemplateService;
use App\Services\WorkspaceContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * /inbox destination — unified feed of the four item kinds listed in
 * PROGRESS.md §Phase 4.2:
 *   1. Today's Attention   (top Recommendations by priority)
 *   2. Recommendations     (full list of open, non-snoozed Recommendations)
 *   3. Agent Reports       (placeholder until Phase 4.3)
 *   4. Monthly PDF reports (existing on-demand generator)
 *
 * Alerts, AI summaries, and daily notes are wrapped by InboxItem for
 * unified status + snooze. Recommendations carry their own status/snoozed_until
 * and are queried directly.
 *
 * Replaces InsightsController (see /insights redirect in routes/web.php).
 */
class InboxController extends Controller
{
    public function index(Request $request, NarrativeTemplateService $narrative): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $todaysAttention = Recommendation::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->active()
            ->orderBy('priority')
            ->limit(5)
            ->get()
            ->map(fn (Recommendation $r) => $this->mapRecommendation($r));

        $recommendations = Recommendation::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->active()
            ->orderBy('priority')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Recommendation $r) => $this->mapRecommendation($r));

        // Active inbox items: Alerts, AiSummaries, DailyNotes that are open
        // and not snoozed. Eager-load itemable so we can render type-specific UI.
        $activeItems = InboxItem::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->active()
            ->with([
                'itemable' => function ($morphTo): void {
                    $morphTo->morphWith([
                        Alert::class => ['store:id,name', 'adAccount:id,name'],
                    ]);
                },
            ])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $alerts     = $this->mapInboxItemsOfType($activeItems, Alert::class, fn ($i) => $this->mapAlert($i));
        $summaries  = $this->mapInboxItemsOfType($activeItems, AiSummary::class, fn ($i) => $this->mapSummary($i));
        $notes      = $this->mapInboxItemsOfType($activeItems, DailyNote::class, fn ($i) => $this->mapNote($i));

        $reportMonths = [];
        $cursor = CarbonImmutable::now()->startOfMonth()->subMonth();
        for ($i = 0; $i < 6; $i++) {
            $reportMonths[] = [
                'year'  => (int) $cursor->format('Y'),
                'month' => (int) $cursor->format('n'),
                'label' => $cursor->format('F Y'),
            ];
            $cursor = $cursor->subMonth();
        }

        return Inertia::render('Inbox', [
            'narrative'        => $narrative->forInbox(count($todaysAttention)),
            'todays_attention' => $todaysAttention->values(),
            'recommendations'  => $recommendations->values(),
            'alerts'           => $alerts->values(),
            'ai_summaries'     => $summaries->values(),
            'daily_notes'      => $notes->values(),
            'agent_reports'    => [],
            'report_months'    => $reportMonths,
        ]);
    }

    public function snooze(Request $request, int $item): RedirectResponse
    {
        $validated = $request->validate([
            'duration' => 'required|in:1h,3h,1d,3d,1w',
        ]);

        $snoozedUntil = match ($validated['duration']) {
            '1h' => now()->addHour(),
            '3h' => now()->addHours(3),
            '1d' => now()->addDay(),
            '3d' => now()->addDays(3),
            '1w' => now()->addWeek(),
        };

        $workspaceId = app(WorkspaceContext::class)->id();

        InboxItem::withoutGlobalScopes()
            ->where('id', $item)
            ->where('workspace_id', $workspaceId)
            ->update(['snoozed_until' => $snoozedUntil]);

        return back();
    }

    public function markDone(int $item): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $inboxItem = InboxItem::withoutGlobalScopes()
            ->where('id', $item)
            ->where('workspace_id', $workspaceId)
            ->first();

        if ($inboxItem === null) {
            return back();
        }

        $inboxItem->update(['status' => InboxItem::STATUS_DONE]);

        // Mirror the done state on the underlying Alert so the legacy
        // unread_alerts_count badge and Alert.resolved_at stay consistent.
        if ($inboxItem->itemable_type === Alert::class) {
            Alert::withoutGlobalScopes()
                ->where('id', $inboxItem->itemable_id)
                ->where('workspace_id', $workspaceId)
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'read_at' => now()]);
        }

        return back();
    }

    public function dismiss(int $item): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $inboxItem = InboxItem::withoutGlobalScopes()
            ->where('id', $item)
            ->where('workspace_id', $workspaceId)
            ->first();

        if ($inboxItem === null) {
            return back();
        }

        $inboxItem->update(['status' => InboxItem::STATUS_DISMISSED]);

        if ($inboxItem->itemable_type === Alert::class) {
            Alert::withoutGlobalScopes()
                ->where('id', $inboxItem->itemable_id)
                ->where('workspace_id', $workspaceId)
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'read_at' => now()]);
        }

        return back();
    }

    public function snoozeRecommendation(Request $request, int $recommendation): RedirectResponse
    {
        $validated = $request->validate([
            'duration' => 'required|in:1h,3h,1d,3d,1w',
        ]);

        $snoozedUntil = match ($validated['duration']) {
            '1h' => now()->addHour(),
            '3h' => now()->addHours(3),
            '1d' => now()->addDay(),
            '3d' => now()->addDays(3),
            '1w' => now()->addWeek(),
        };

        $workspaceId = app(WorkspaceContext::class)->id();

        Recommendation::withoutGlobalScopes()
            ->where('id', $recommendation)
            ->where('workspace_id', $workspaceId)
            ->update([
                'status'        => Recommendation::STATUS_SNOOZED,
                'snoozed_until' => $snoozedUntil,
            ]);

        return back();
    }

    public function markRecommendationDone(int $recommendation): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        Recommendation::withoutGlobalScopes()
            ->where('id', $recommendation)
            ->where('workspace_id', $workspaceId)
            ->update(['status' => Recommendation::STATUS_DONE]);

        return back();
    }

    public function dismissRecommendation(int $recommendation): RedirectResponse
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        Recommendation::withoutGlobalScopes()
            ->where('id', $recommendation)
            ->where('workspace_id', $workspaceId)
            ->update(['status' => Recommendation::STATUS_DISMISSED]);

        return back();
    }

    public function downloadMonthlyReport(
        int $year,
        int $month,
        MonthlyReportService $service,
    ): SymfonyResponse {
        $workspaceId = app(WorkspaceContext::class)->id();

        $monthStart = CarbonImmutable::createFromDate($year, $month, 1)->startOfMonth();
        $data = $service->build($workspaceId, $monthStart);

        $filename = sprintf('nexstage-monthly-%s.pdf', $monthStart->format('Y-m'));

        return Pdf::loadView('reports.monthly', $data)
            ->setPaper('a4')
            ->download($filename);
    }

    /**
     * Filter a collection of InboxItem by itemable_type and pass each through
     * a row mapper. Returns a Collection (arrays) for Inertia serialization.
     *
     * @param  \Illuminate\Support\Collection<int, InboxItem>  $items
     * @param  class-string  $type
     */
    private function mapInboxItemsOfType(Collection $items, string $type, callable $mapper): Collection
    {
        return $items
            ->filter(fn (InboxItem $i) => $i->itemable_type === $type && $i->itemable !== null)
            ->map($mapper);
    }

    private function mapRecommendation(Recommendation $r): array
    {
        return [
            'id'              => $r->id,
            'type'            => $r->type,
            'priority'        => $r->priority,
            'title'           => $r->title,
            'body'            => $r->body,
            'impact_estimate' => $r->impact_estimate !== null ? (float) $r->impact_estimate : null,
            'impact_currency' => $r->impact_currency,
            'target_url'      => $r->target_url,
            'data'            => $r->data,
            'created_at'      => $r->created_at?->toISOString(),
        ];
    }

    private function mapAlert(InboxItem $item): array
    {
        /** @var Alert $alert */
        $alert = $item->itemable;

        return [
            'inbox_item_id'   => $item->id,
            'snoozed_until'   => $item->snoozed_until?->toISOString(),
            'id'              => $alert->id,
            'type'            => $alert->type,
            'severity'        => $alert->severity,
            'store_name'      => $alert->store?->name,
            'ad_account_name' => $alert->adAccount?->name,
            'data'            => $alert->data,
            'created_at'      => $alert->created_at->toISOString(),
        ];
    }

    private function mapSummary(InboxItem $item): array
    {
        /** @var AiSummary $summary */
        $summary = $item->itemable;

        return [
            'inbox_item_id' => $item->id,
            'snoozed_until' => $item->snoozed_until?->toISOString(),
            'id'            => $summary->id,
            'date'          => $summary->date->toDateString(),
            'summary_text'  => $summary->summary_text,
            'model_used'    => $summary->model_used,
            'generated_at'  => $summary->generated_at?->toISOString(),
        ];
    }

    private function mapNote(InboxItem $item): array
    {
        /** @var DailyNote $note */
        $note = $item->itemable;

        return [
            'inbox_item_id' => $item->id,
            'snoozed_until' => $item->snoozed_until?->toISOString(),
            'id'            => $note->id,
            'date'          => $note->date->toDateString(),
            'note'          => $note->note,
        ];
    }
}
