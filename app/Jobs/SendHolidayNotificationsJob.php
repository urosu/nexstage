<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\HolidayReminderMail;
use App\Models\Holiday;
use App\Models\Workspace;
use App\Scopes\WorkspaceScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends holiday and commercial event reminder emails to workspace owners.
 *
 * Processes public holidays (workspace_settings.holiday_notification_days) and
 * commercial events (workspace_settings.commercial_notification_days) independently
 * in a single chunk pass. Each type uses a separate cache key namespace to prevent
 * cross-contamination. Setting either days value to 0 disables that type.
 *
 * Queue:   low
 * Timeout: 60 s
 * Tries:   3
 *
 * Triggered by: daily schedule at 09:00 UTC.
 * See: routes/console.php "send-holiday-notifications"
 *
 * Guards:
 *   - Skips workspaces with the relevant notification_days = 0.
 *   - Skips workspaces with no country set.
 *   - Owner must have a verified email address.
 *   - Cache-based dedup: one email per workspace + holiday date per day.
 *
 * Reads:  holidays (global), workspaces.workspace_settings, workspaces.country
 * Writes: mail queue; Cache for dedup keys
 *
 * @see PLANNING.md section 5 (holiday system)
 */
class SendHolidayNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 3;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(): void
    {
        Workspace::withoutGlobalScope(WorkspaceScope::class)
            ->whereNull('deleted_at')
            ->whereNotNull('country')
            ->with('owner')
            ->chunkById(100, function ($chunk): void {
                // Collect unique (country, date) pairs needed for each type.
                $publicPairs     = [];
                $commercialPairs = [];

                foreach ($chunk as $workspace) {
                    $publicDays     = $workspace->workspace_settings->holidayNotificationDays;
                    $commercialDays = $workspace->workspace_settings->commercialNotificationDays;

                    if ($publicDays > 0) {
                        $date = now()->addDays($publicDays)->toDateString();
                        $publicPairs["{$workspace->country}|{$date}"] = [
                            'country_code' => $workspace->country,
                            'date'         => $date,
                        ];
                    }

                    if ($commercialDays > 0) {
                        $date = now()->addDays($commercialDays)->toDateString();
                        $commercialPairs["{$workspace->country}|{$date}"] = [
                            'country_code' => $workspace->country,
                            'date'         => $date,
                        ];
                    }
                }

                $publicMap     = $this->buildHolidayMap($publicPairs,     'public');
                $commercialMap = $this->buildHolidayMap($commercialPairs, 'commercial');

                foreach ($chunk as $workspace) {
                    $publicDays = $workspace->workspace_settings->holidayNotificationDays;
                    if ($publicDays > 0) {
                        $date     = now()->addDays($publicDays)->toDateString();
                        $holidays = $publicMap["{$workspace->country}|{$date}"] ?? collect();
                        foreach ($holidays as $holiday) {
                            $this->maybeNotify($workspace, $holiday, $publicDays, 'public');
                        }
                    }

                    $commercialDays = $workspace->workspace_settings->commercialNotificationDays;
                    if ($commercialDays > 0) {
                        $date     = now()->addDays($commercialDays)->toDateString();
                        $holidays = $commercialMap["{$workspace->country}|{$date}"] ?? collect();
                        foreach ($holidays as $holiday) {
                            $this->maybeNotify($workspace, $holiday, $commercialDays, 'commercial');
                        }
                    }
                }
            });
    }

    /**
     * Pre-loads all holidays of a given type for the provided (country, date) pairs.
     * Returns a map keyed by "{country_code}|{date}".
     *
     * @param  array<string, array{country_code: string, date: string}>  $pairs
     * @return array<string, Collection<int, Holiday>>
     */
    private function buildHolidayMap(array $pairs, string $type): array
    {
        $map = [];

        foreach ($pairs as $pair) {
            $key      = "{$pair['country_code']}|{$pair['date']}";
            $map[$key] = Holiday::where('country_code', $pair['country_code'])
                ->whereDate('date', $pair['date'])
                ->where('type', $type)
                ->get();
        }

        return $map;
    }

    private function maybeNotify(Workspace $workspace, Holiday $holiday, int $daysAway, string $type): void
    {
        $owner = $workspace->owner;

        if ($owner === null || $owner->email_verified_at === null) {
            Log::info('SendHolidayNotificationsJob: owner missing or unverified, skipping', [
                'workspace_id' => $workspace->id,
                'holiday'      => $holiday->name,
                'type'         => $type,
                'date'         => $holiday->date->toDateString(),
            ]);
            return;
        }

        // Commercial events: multiple events can share the same date (e.g. Singles' Day + another),
        // so include a name hash in the cache key to avoid suppressing distinct events.
        $dateKey  = $holiday->date->toDateString();
        $prefix   = $type === 'commercial' ? 'commercial_notification' : 'holiday_notification';
        $suffix   = $type === 'commercial' ? ':' . substr(md5($holiday->name), 0, 8) : '';
        $cacheKey = "{$prefix}:{$workspace->id}:{$dateKey}{$suffix}";

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, now()->addHours(23));

        Mail::to($owner->email)->queue(new HolidayReminderMail($holiday, $workspace, $daysAway));

        Log::info('SendHolidayNotificationsJob: reminder queued', [
            'workspace_id' => $workspace->id,
            'owner_id'     => $owner->id,
            'holiday'      => $holiday->name,
            'type'         => $type,
            'date'         => $dateKey,
            'days_away'    => $daysAway,
        ]);
    }
}
