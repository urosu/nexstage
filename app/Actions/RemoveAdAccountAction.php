<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AdAccount;
use App\Models\AdInsight;
use App\Models\SyncLog;
use Illuminate\Support\Facades\DB;

class RemoveAdAccountAction
{
    /**
     * Permanently remove an ad account and all its data.
     *
     * ad_insights uses nullOnDelete (SET NULL), so we delete those explicitly
     * before removing the account. campaigns/adsets/ads cascade automatically.
     */
    public function handle(AdAccount $adAccount): void
    {
        DB::transaction(function () use ($adAccount): void {
            AdInsight::where('ad_account_id', $adAccount->id)->delete();

            SyncLog::where('syncable_type', AdAccount::class)
                ->where('syncable_id', $adAccount->id)
                ->delete();

            $adAccount->delete();
        });
    }
}
