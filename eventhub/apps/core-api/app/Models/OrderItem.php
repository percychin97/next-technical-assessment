<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'ticket_type_id',
        'ticket_type_name_snapshot',
        'purchase_quantity',
        'admission_units_per_purchase_snapshot',
        'admission_quantity',
        'unit_price_minor_snapshot',
        'subtotal_minor',
        'currency',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'purchase_quantity'                    => 'integer',
            'admission_units_per_purchase_snapshot' => 'integer',
            'admission_quantity'                   => 'integer',
            'unit_price_minor_snapshot'            => 'integer',
            'subtotal_minor'                       => 'integer',
            'created_at'                           => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }
}
