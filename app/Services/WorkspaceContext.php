<?php

declare(strict_types=1);

namespace App\Services;

class WorkspaceContext
{
    private ?int $workspaceId = null;

    public function set(int $id): void
    {
        $this->workspaceId = $id;
    }

    public function id(): ?int
    {
        return $this->workspaceId;
    }
}
