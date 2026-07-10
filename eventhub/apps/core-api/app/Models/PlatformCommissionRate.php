<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformCommissionRate extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'rate_basis_points',
        'effective_from',
        'effective_to',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'rate_basis_points' => 'integer',
            'effective_from'    => 'datetime',
            'effective_to'      => 'datetime',
            'created_at'        => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the currently effective commission rate.
     */
    public static function currentRate(): static
    {
        return static::where('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', now());
            })
            ->orderByDesc('effective_from')
            ->firstOrFail();
    }
}
