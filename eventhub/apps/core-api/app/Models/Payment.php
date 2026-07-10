<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id',
        'provider',
        'status',
        'idempotency_key',
        'amount_minor',
        'currency',
        'provider_reference',
        'succeeded_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'status'       => PaymentStatus::class,
            'amount_minor' => 'integer',
            'succeeded_at' => 'datetime',
            'failed_at'    => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
