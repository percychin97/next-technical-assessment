<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    use HasUuids;

    protected $fillable = [
        'vendor_id',
        'commission_rate_id',
        'payout_setting_id',
        'payout_number',
        'period_start',
        'period_end',
        'gross_amount_minor',
        'refunded_amount_minor',
        'commission_rate_basis_points_snapshot',
        'commission_amount_minor',
        'net_amount_minor',
        'minimum_threshold_minor_snapshot',
        'currency',
        'status',
        'idempotency_key',
        'approved_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'                                => PayoutStatus::class,
            'period_start'                          => 'date',
            'period_end'                            => 'date',
            'gross_amount_minor'                    => 'integer',
            'refunded_amount_minor'                 => 'integer',
            'commission_rate_basis_points_snapshot' => 'integer',
            'commission_amount_minor'               => 'integer',
            'net_amount_minor'                      => 'integer',
            'minimum_threshold_minor_snapshot'      => 'integer',
            'approved_at'                           => 'datetime',
            'completed_at'                          => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PayoutAttempt::class);
    }
}
