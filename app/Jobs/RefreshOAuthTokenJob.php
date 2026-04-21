<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\GoogleTokenExpiredException;
use App\Models\AdAccount;
use App\Models\Alert;
use App\Models\SearchConsoleProperty;
use App\Scopes\WorkspaceScope;
use App\Services\Integrations\Google\GoogleAdsClient;
use App\Services\Integrations\SearchConsole\SearchConsoleClient;
use App\Services\WorkspaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes OAuth tokens for all Google Ads and GSC integrations.
 *
 * Queue:   default
 * Timeout: 60 s
 * Tries:   3
 * Backoff: [30, 120, 300] s
 *
 * Scheduled daily at 05:00 UTC.
 *
 * Iterates all active Google Ads ad accounts and GSC properties whose tokens
 * expire within the next 24 hours. Refreshes each one proactively so that the
 * next scheduled sync job always has a valid token.
 *
 * On refresh failure: marks the integration as token_expired, creates a critical
 * alert, and logs the error — but does NOT throw (continues to the next record).
 * Throwing would consume a retry attempt even when most records succeeded.
 */
class RefreshOAuthTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $this->refreshGoogleAdsTokens();
        $this->refreshGscTokens();
    }

    // -------------------------------------------------------------------------
    // Google Ads
    // -------------------------------------------------------------------------

    private function refreshGoogleAdsTokens(): void
    {
        // Refresh accounts whose token expires within 24 hours (or has already expired)
        $accounts = AdAccount::withoutGlobalScope(WorkspaceScope::class)
            ->join('workspaces', 'ad_accounts.workspace_id', '=', 'workspaces.id')
            ->where('ad_accounts.platform', 'google')
            ->where('ad_accounts.status', 'active')
            ->whereNull('workspaces.deleted_at')
            ->where('ad_accounts.token_expires_at', '<=', now()->addHours(24))
            ->select(['ad_accounts.id', 'ad_accounts.workspace_id'])
            ->get();

        foreach ($accounts as $account) {
            app(WorkspaceContext::class)->set((int) $account->workspace_id);

            /** @var AdAccount $full */
            $full = AdAccount::withoutGlobalScopes()->find($account->id);

            if ($full === null) {
                continue;
            }

            try {
                $client = GoogleAdsClient::forAccount($full);
                $client->forceRefresh();

                Log::info('RefreshOAuthTokenJob: Google Ads token refreshed', [
                    'ad_account_id' => $full->id,
                ]);
            } catch (GoogleTokenExpiredException $e) {
                $this->markGoogleAdsTokenExpired($full);
            } catch (\Throwable $e) {
                Log::error('RefreshOAuthTokenJob: unexpected error refreshing Google Ads token', [
                    'ad_account_id' => $full->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Google Search Console
    // -------------------------------------------------------------------------

    private function refreshGscTokens(): void
    {
        $properties = SearchConsoleProperty::withoutGlobalScope(WorkspaceScope::class)
            ->join('workspaces', 'search_console_properties.workspace_id', '=', 'workspaces.id')
            ->where('search_console_properties.status', 'active')
            ->whereNull('workspaces.deleted_at')
            ->where('search_console_properties.token_expires_at', '<=', now()->addHours(24))
            ->select(['search_console_properties.id', 'search_console_properties.workspace_id'])
            ->get();

        foreach ($properties as $property) {
            app(WorkspaceContext::class)->set((int) $property->workspace_id);

            /** @var SearchConsoleProperty $full */
            $full = SearchConsoleProperty::withoutGlobalScopes()->find($property->id);

            if ($full === null) {
                continue;
            }

            try {
                $client = SearchConsoleClient::forProperty($full);
                $client->forceRefresh();

                Log::info('RefreshOAuthTokenJob: GSC token refreshed', [
                    'property_id' => $full->id,
                ]);
            } catch (GoogleTokenExpiredException $e) {
                $this->markGscTokenExpired($full);
            } catch (\Throwable $e) {
                Log::error('RefreshOAuthTokenJob: unexpected error refreshing GSC token', [
                    'property_id' => $full->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Failure helpers
    // -------------------------------------------------------------------------

    private function markGoogleAdsTokenExpired(AdAccount $account): void
    {
        $account->update(['status' => 'token_expired']);

        Alert::withoutGlobalScopes()->create([
            'workspace_id'  => $account->workspace_id,
            'ad_account_id' => $account->id,
            'type'          => 'google_token_expired',
            'severity'      => 'critical',
            'data'          => ['ad_account_name' => $account->name],
        ]);

        Log::error('RefreshOAuthTokenJob: Google Ads token expired, cannot refresh', [
            'ad_account_id' => $account->id,
        ]);
    }

    private function markGscTokenExpired(SearchConsoleProperty $property): void
    {
        $property->update(['status' => 'token_expired']);

        Alert::withoutGlobalScopes()->create([
            'workspace_id' => $property->workspace_id,
            'type'         => 'gsc_token_expired',
            'severity'     => 'critical',
            'data'         => ['property_url' => $property->property_url],
        ]);

        Log::error('RefreshOAuthTokenJob: GSC token expired, cannot refresh', [
            'property_id' => $property->id,
        ]);
    }
}
