<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceEvent;
use App\Services\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages workspace events (promotions, expected spikes/drops).
 *
 * Events are used to:
 *  - Render overlay markers on all time-series charts (blue vertical lines)
 *  - Phase 2: suppress anomaly detection during expected high/low periods
 *  - Phase 2: exclude from ComputeMetricBaselinesJob rolling window
 *
 * Related: app/Models/WorkspaceEvent.php
 * Related: resources/js/Components/charts/MultiSeriesLineChart.tsx (overlay rendering)
 * See: PLANNING.md "workspace_events"
 */
class WorkspaceEventsController extends Controller
{
    private const EVENT_TYPES = ['promotion', 'expected_spike', 'expected_drop'];

    public function index(Request $request): Response
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('viewSettings', $workspace);

        $events = WorkspaceEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->orderBy('date_from', 'desc')
            ->get()
            ->map(fn (WorkspaceEvent $e) => [
                'id'                => $e->id,
                'name'              => $e->name,
                'event_type'        => $e->event_type,
                'date_from'         => $e->date_from->toDateString(),
                'date_to'           => $e->date_to->toDateString(),
                'suppress_anomalies' => $e->suppress_anomalies,
                'is_auto_detected'  => $e->is_auto_detected,
                'needs_review'      => $e->needs_review,
            ])
            ->all();

        return Inertia::render('Settings/Events', [
            'events'     => $events,
            'eventTypes' => self::EVENT_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:255'],
            'event_type'         => ['required', Rule::in(self::EVENT_TYPES)],
            'date_from'          => ['required', 'date_format:Y-m-d'],
            'date_to'            => ['required', 'date_format:Y-m-d', 'gte:date_from'],
            'suppress_anomalies' => ['sometimes', 'boolean'],
        ]);

        WorkspaceEvent::create([
            'workspace_id'       => $workspace->id,
            'name'               => $validated['name'],
            'event_type'         => $validated['event_type'],
            'date_from'          => $validated['date_from'],
            'date_to'            => $validated['date_to'],
            'suppress_anomalies' => $validated['suppress_anomalies'] ?? true,
            'is_auto_detected'   => false,
            'needs_review'       => false,
        ]);

        return back()->with('success', 'Event created.');
    }

    public function update(Request $request, string $eventId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('update', $workspace);

        $event = WorkspaceEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($eventId);

        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:255'],
            'event_type'         => ['required', Rule::in(self::EVENT_TYPES)],
            'date_from'          => ['required', 'date_format:Y-m-d'],
            'date_to'            => ['required', 'date_format:Y-m-d', 'gte:date_from'],
            'suppress_anomalies' => ['sometimes', 'boolean'],
        ]);

        $event->update($validated);

        return back()->with('success', 'Event updated.');
    }

    public function destroy(int $eventId): RedirectResponse
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('update', $workspace);

        WorkspaceEvent::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($eventId)
            ->delete();

        return back()->with('success', 'Event deleted.');
    }
}
