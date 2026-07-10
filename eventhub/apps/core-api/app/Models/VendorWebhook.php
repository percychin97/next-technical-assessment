<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorWebhook extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'vendor_id',
        'url',
        'encrypted_secret',
        'subscribed_events',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'subscribed_events' => 'array',
            'is_active'         => 'boolean',
            'deleted_at'        => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
