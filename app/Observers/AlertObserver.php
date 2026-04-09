<?php

declare(strict_types=1);

namespace App\Observers;

use App\Mail\CriticalAlertMail;
use App\Models\Alert;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends CriticalAlertMail to the workspace owner whenever a critical alert is created.
 *
 * Guards:
 *   1. Only fires for severity='critical'.
 *   2. Owner must have a verified email address.
 *   3. No more than one email per alert type + workspace per 24 hours.
 */
class AlertObserver
{
    public function created(Alert $alert): void
    {
        if ($alert->severity !== 'critical') {
            return;
        }

        $workspace = Workspace::withoutGlobalScopes()->find($alert->workspace_id);

        if ($workspace === null) {
            Log::warning('AlertObserver: workspace not found for critical alert', [
                'alert_id'     => $alert->id,
                'workspace_id' => $alert->workspace_id,
            ]);
            return;
        }

        $owner = $workspace->owner;

        // Guard: owner must have a verified email
        if ($owner === null || $owner->email_verified_at === null) {
            Log::info('AlertObserver: owner has no verified email, skipping critical alert email', [
                'alert_id'     => $alert->id,
                'workspace_id' => $alert->workspace_id,
            ]);
            return;
        }

        // Guard: 24-hour dedup — same alert type + workspace
        $recentDuplicate = Alert::withoutGlobalScopes()
            ->where('workspace_id', $alert->workspace_id)
            ->where('type', $alert->type)
            ->where('severity', 'critical')
            ->where('id', '!=', $alert->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();

        if ($recentDuplicate) {
            Log::info('AlertObserver: duplicate critical alert within 24h, suppressing email', [
                'alert_id'     => $alert->id,
                'workspace_id' => $alert->workspace_id,
                'type'         => $alert->type,
            ]);
            return;
        }

        Mail::to($owner->email)->queue(new CriticalAlertMail($alert, $workspace));

        Log::info('AlertObserver: critical alert email queued', [
            'alert_id'     => $alert->id,
            'workspace_id' => $alert->workspace_id,
            'type'         => $alert->type,
            'owner_id'     => $owner->id,
        ]);
    }
}
