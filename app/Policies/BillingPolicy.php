<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Billing actions are restricted to workspace Owners only.
 */
class BillingPolicy
{
    /**
     * View the billing settings page.
     */
    public function view(User $user, Workspace $workspace): bool
    {
        return $this->isOwner($user, $workspace);
    }

    /**
     * Subscribe, change plan, or cancel a subscription.
     */
    public function manage(User $user, Workspace $workspace): bool
    {
        return $this->isOwner($user, $workspace);
    }

    private function isOwner(User $user, Workspace $workspace): bool
    {
        return WorkspaceUser::where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->exists();
    }
}
