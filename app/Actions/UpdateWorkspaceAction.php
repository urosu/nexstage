<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\RecomputeReportingCurrencyJob;
use App\Models\Workspace;

class UpdateWorkspaceAction
{
    /**
     * Update workspace settings.
     *
     * If reporting_currency changes, dispatches RecomputeReportingCurrencyJob.
     *
     * @param array{name?: string, reporting_currency?: string, reporting_timezone?: string} $validated
     */
    public function handle(Workspace $workspace, array $validated): Workspace
    {
        $currencyChanged = isset($validated['reporting_currency'])
            && $validated['reporting_currency'] !== $workspace->reporting_currency;

        $workspace->update($validated);

        if ($currencyChanged) {
            dispatch(new RecomputeReportingCurrencyJob($workspace->id));
        }

        return $workspace->fresh();
    }
}
