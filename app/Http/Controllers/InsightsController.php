<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdAccount;
use App\Models\AiSummary;
use App\Models\Alert;
use App\Models\Store;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InsightsController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = app(WorkspaceContext::class)->id();

        $validated = $request->validate([
            'severity' => 'nullable|string|in:all,info,warning,critical',
            'status'   => 'nullable|string|in:all,unread,unresolved',
            'page'     => 'nullable|integer|min:1',
        ]);

        $severity = $validated['severity'] ?? 'all';
        $status   = $validated['status']   ?? 'all';

        // AI summaries — last 7 days, latest first
        $aiSummaries = AiSummary::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('date', '>=', now()->subDays(6)->toDateString())
            ->select(['id', 'date', 'summary_text', 'model_used', 'generated_at'])
            ->orderByDesc('date')
            ->get()
            ->map(fn ($s) => [
                'id'           => $s->id,
                'date'         => $s->date->toDateString(),
                'summary_text' => $s->summary_text,
                'model_used'   => $s->model_used,
                'generated_at' => $s->generated_at->toISOString(),
            ]);

        // Alert query — scoped to workspace, with optional filters
        $query = Alert::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->with([
                'store:id,name',
                'adAccount:id,name',
            ])
            ->orderByDesc('created_at');

        if ($severity !== 'all') {
            $query->where('severity', $severity);
        }

        match ($status) {
            'unread'     => $query->whereNull('read_at'),
            'unresolved' => $query->whereNull('resolved_at'),
            default      => null,
        };

        $alerts = $query->paginate(30)->through(fn ($a) => [
            'id'              => $a->id,
            'type'            => $a->type,
            'severity'        => $a->severity,
            'store_name'      => $a->store?->name,
            'ad_account_name' => $a->adAccount?->name,
            'data'            => $a->data,
            'read_at'         => $a->read_at?->toISOString(),
            'resolved_at'     => $a->resolved_at?->toISOString(),
            'created_at'      => $a->created_at->toISOString(),
        ]);

        return Inertia::render('Insights', [
            'ai_summaries' => $aiSummaries,
            'alerts'       => $alerts,
            'filters'      => [
                'severity' => $severity,
                'status'   => $status,
            ],
        ]);
    }

    public function markRead(Alert $alert): RedirectResponse
    {
        // WorkspaceScope ensures alert belongs to active workspace
        $alert->update(['read_at' => now()]);

        return back();
    }

    public function resolve(Alert $alert): RedirectResponse
    {
        // Mark as read too if not already
        $alert->update([
            'resolved_at' => now(),
            'read_at'     => $alert->read_at ?? now(),
        ]);

        return back();
    }
}
