<?php

declare(strict_types=1);

namespace App\Scopes;

use App\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $id = app(WorkspaceContext::class)->id();

        if (!$id) {
            throw new \RuntimeException('WorkspaceContext not set — call set() before querying workspace-scoped models.');
        }

        $builder->where('workspace_id', $id);
    }
}
