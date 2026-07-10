<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketReservation extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id',
        'ticket_type_id',
        'inventory_pool_id',
        'purchase_quantity',
        'reserved_units',
        'status',
        'expires_at',
        'confirmed_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'status'           => ReservationStatus::class,
            'purchase_quantity' => 'integer',
            'reserved_units'   => 'integer',
            'expires_at'       => 'datetime',
            'confirmed_at'     => 'datetime',
            'released_at'      => 'datetime',
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

    public function inventoryPool(): BelongsTo
    {
        return $this->belongsTo(TicketInventoryPool::class, 'inventory_pool_id');
    }

    public function isHeld(): bool
    {
        return $this->status === ReservationStatus::Held && now()->lt($this->expires_at);
    }
}
