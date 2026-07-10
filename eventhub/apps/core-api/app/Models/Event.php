<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'vendor_id',
        'title',
        'description',
        'start_at_utc',
        'end_at_utc',
        'display_timezone',
        'status',
        'published_at',
        'cancelled_at',
        'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status'           => EventStatus::class,
            'start_at_utc'     => 'datetime',
            'end_at_utc'       => 'datetime',
            'published_at'     => 'datetime',
            'cancelled_at'     => 'datetime',
            'reminder_sent_at' => 'datetime',
            'deleted_at'       => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function inventoryPools(): HasMany
    {
        return $this->hasMany(TicketInventoryPool::class);
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
    }

    public function isPublished(): bool
    {
        return $this->status === EventStatus::Published;
    }

    public function isAcceptingReservations(): bool
    {
        return $this->status === EventStatus::Published
            && now()->lt($this->end_at_utc);
    }
}
