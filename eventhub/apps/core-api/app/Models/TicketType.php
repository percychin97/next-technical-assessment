<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketType extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'event_id',
        'inventory_pool_id',
        'code',
        'name',
        'category',
        'price_minor',
        'currency',
        'admission_units_per_purchase',
        'sale_start_at_utc',
        'sale_end_at_utc',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_minor'                  => 'integer',
            'admission_units_per_purchase' => 'integer',
            'is_active'                    => 'boolean',
            'sale_start_at_utc'            => 'datetime',
            'sale_end_at_utc'              => 'datetime',
            'deleted_at'                   => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function inventoryPool(): BelongsTo
    {
        return $this->belongsTo(TicketInventoryPool::class, 'inventory_pool_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function isSaleActive(): bool
    {
        $now = now();

        return $this->is_active
            && ($this->sale_start_at_utc === null || $now->gte($this->sale_start_at_utc))
            && ($this->sale_end_at_utc === null || $now->lt($this->sale_end_at_utc));
    }
}
