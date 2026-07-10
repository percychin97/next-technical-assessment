<?php

namespace App\Models;

use App\Enums\RefundRequestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RefundRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id',
        'requested_by_user_id',
        'reviewed_by_user_id',
        'idempotency_key',
        'status',
        'reason',
        'policy_percentage_snapshot',
        'original_amount_minor',
        'requested_amount_minor',
        'approved_amount_minor',
        'currency',
        'calculated_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'                     => RefundRequestStatus::class,
            'policy_percentage_snapshot' => 'integer',
            'original_amount_minor'      => 'integer',
            'requested_amount_minor'     => 'integer',
            'approved_amount_minor'      => 'integer',
            'calculated_at'              => 'datetime',
            'reviewed_at'                => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }
}
