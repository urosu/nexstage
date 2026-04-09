<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([WorkspaceScope::class])]
class GscPage extends Model
{
    protected $fillable = [
        'property_id',
        'workspace_id',
        'date',
        'page',
        'clicks',
        'impressions',
        'ctr',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'ctr' => 'decimal:6',
            'position' => 'decimal:2',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(SearchConsoleProperty::class, 'property_id');
    }
}
