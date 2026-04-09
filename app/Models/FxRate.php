<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FxRate extends Model
{
    use HasFactory;
    // Historical rates are never revised — no updated_at.
    public const UPDATED_AT = null;

    protected $fillable = [
        'base_currency',
        'target_currency',
        'rate',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'rate' => 'decimal:8',
        ];
    }
}
