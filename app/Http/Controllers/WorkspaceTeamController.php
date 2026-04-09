<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceUser;
use App\Services\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceTeamController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = Workspace::findOrFail(app(WorkspaceContext::class)->id());

        $this->authorize('viewSettings', $workspace);

        $members = WorkspaceUser::with('user:id,name,email,last_login_at')
            ->where('workspace_id', $workspace->id)
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")
            ->orderBy('created_at')
            ->get()
            ->map(fn ($wu) => [
                'id'         => $wu->id,
                'user_id'    => $wu->user_id,
                'name'       => $wu->user->name,
                'email'      => $wu->user->email,
                'role'       => $wu->role,
                'joined_at'  => $wu->created_at,
                'last_login' => $wu->user->last_login_at,
            ]);

        $invitations = WorkspaceInvitation::where('workspace_id', $workspace->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($inv) => [
                'id'         => $inv->id,
                'email'      => $inv->email,
                'role'       => $inv->role,
                'expires_at' => $inv->expires_at,
                'token'      => $inv->token,
            ]);

        $userRole = WorkspaceUser::where('workspace_id', $workspace->id)
            ->where('user_id', $request->user()->id)
            ->value('role') ?? 'member';

        return Inertia::render('Settings/Team', [
            'workspace'   => ['id' => $workspace->id, 'name' => $workspace->name, 'owner_id' => $workspace->owner_id],
            'members'     => $members,
            'invitations' => $invitations,
            'userRole'    => $userRole,
            'authUser'    => ['id' => $request->user()->id],
        ]);
    }
}
