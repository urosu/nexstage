<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAnnotationScope;
use App\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([WorkspaceScope::class])]
class DailyNote extends Model
{
    use HasAnnotationScope;
    protected $fillable = [
        'workspace_id',
        'date',
        'note',
        'scope_type',
        'scope_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
