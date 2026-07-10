<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlatformPayoutSetting extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'minimum_payout_minor',
        'currency',
        'effective_from',
        'effective_to',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'minimum_payout_minor' => 'integer',
            'effective_from'       => 'datetime',
            'effective_to'         => 'datetime',
            'created_at'           => 'datetime',
        ];
    }

    public static function currentSetting(): static
    {
        return static::where('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', now());
            })
            ->orderByDesc('effective_from')
            ->firstOrFail();
    }
}
