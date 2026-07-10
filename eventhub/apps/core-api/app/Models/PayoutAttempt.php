<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutAttempt extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'payout_id',
        'provider_event_id',
        'status',
        'attempt_number',
        'request_payload',
        'response_payload',
        'error_code',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload'  => 'array',
            'response_payload' => 'array',
            'attempt_number'   => 'integer',
            'attempted_at'     => 'datetime',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
