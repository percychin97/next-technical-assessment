<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutItem extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'payout_id',
        'order_item_id',
        'gross_amount_minor',
        'refunded_amount_minor',
        'eligible_amount_minor',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount_minor'    => 'integer',
            'refunded_amount_minor' => 'integer',
            'eligible_amount_minor' => 'integer',
            'created_at'            => 'datetime',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
