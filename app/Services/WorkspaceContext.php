<?php

declare(strict_types=1);

namespace App\Services;

class WorkspaceContext
{
    private ?int $workspaceId = null;

    private ?string $workspaceSlug = null;

    public function set(int $id, ?string $slug = null): void
    {
        $this->workspaceId = $id;
        $this->workspaceSlug = $slug;
    }

    public function id(): ?int
    {
        return $this->workspaceId;
    }

    public function slug(): ?string
    {
        return $this->workspaceSlug;
    }
}
