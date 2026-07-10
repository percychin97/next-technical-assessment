<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketInventoryPool extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_id',
        'name',
        'capacity_units',
        'reserved_units',
        'sold_units',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'capacity_units' => 'integer',
            'reserved_units' => 'integer',
            'sold_units'     => 'integer',
            'version'        => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class, 'inventory_pool_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(TicketReservation::class, 'inventory_pool_id');
    }

    public function availableUnits(): int
    {
        return $this->capacity_units - $this->reserved_units - $this->sold_units;
    }
}
