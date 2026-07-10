<?php

namespace App\Models;

use App\Enums\OutboxEventStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'status',
        'publish_attempts',
        'available_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status'           => OutboxEventStatus::class,
            'payload'          => 'array',
            'publish_attempts' => 'integer',
            'available_at'     => 'datetime',
            'published_at'     => 'datetime',
        ];
    }
}
