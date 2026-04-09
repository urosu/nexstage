<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Workspace;
use Illuminate\Support\Facades\Log;

class DeleteWorkspaceAction
{
    /**
     * Soft-delete a workspace after confirming no open invoices or active subscription.
     *
     * Per spec §Workspace Deletion:
     *   - Blocked if open (unpaid) Stripe invoices exist
     *   - Blocked if an active Stripe subscription exists (owner must cancel first)
     *   - Sets deleted_at (soft-delete) — PurgeDeletedWorkspaceJob hard-deletes after 30 days
     *
     * @throws \RuntimeException if preconditions are not met
     */
    public function handle(Workspace $workspace): void
    {
        // Block if active Stripe subscription exists
        if ($workspace->subscribed()) {
            throw new \RuntimeException(
                'Please cancel your subscription before deleting this workspace.'
            );
        }

        // Block if open invoices exist
        $openInvoices = $workspace->invoices()->filter(
            fn ($invoice) => $invoice->isOpen()
        );

        if ($openInvoices->isNotEmpty()) {
            throw new \RuntimeException(
                'This workspace has unpaid invoices. Please settle them before deleting.'
            );
        }

        $workspace->delete(); // triggers SoftDeletes — sets deleted_at

        Log::info('Workspace soft-deleted', [
            'workspace_id' => $workspace->id,
            'name'         => $workspace->name,
            'deleted_at'   => $workspace->deleted_at,
        ]);

        // TODO: send workspace deletion confirmation email to owner with 30-day restore link
        // Template not defined in spec MVP — implement when email templates are ready
    }
}
