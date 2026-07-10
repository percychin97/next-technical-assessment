<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'event_id',
        'order_number',
        'creation_idempotency_key',
        'status',
        'subtotal_minor',
        'total_amount_minor',
        'currency',
        'hold_expires_at',
        'payment_review_reason',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status'              => OrderStatus::class,
            'subtotal_minor'      => 'integer',
            'total_amount_minor'  => 'integer',
            'hold_expires_at'     => 'datetime',
            'paid_at'             => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(TicketReservation::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function isReservationHeld(): bool
    {
        return $this->status === OrderStatus::AwaitingPayment
            && $this->hold_expires_at !== null
            && now()->lt($this->hold_expires_at);
    }
}
