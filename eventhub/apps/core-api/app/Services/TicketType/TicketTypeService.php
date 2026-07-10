<?php

namespace App\Services\TicketType;

use App\Enums\EventStatus;
use App\Exceptions\DomainException;
use App\Models\Event;
use App\Models\TicketInventoryPool;
use App\Models\TicketType;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

class TicketTypeService
{
    /**
     * Create an inventory pool and ticket type for a draft event.
     *
     * @throws DomainException
     */
    public function create(Event $event, Vendor $vendor, array $data): TicketType
    {
        if ($event->vendor_id !== $vendor->id) {
            throw new DomainException('You do not own this event.');
        }

        if (!in_array($event->status, [EventStatus::Draft, EventStatus::Published])) {
            throw new DomainException('Ticket types can only be added to draft or published events.');
        }

        return DB::transaction(function () use ($event, $data) {
            // Create or use existing inventory pool
            $pool = isset($data['inventory_pool_id'])
                ? TicketInventoryPool::where('id', $data['inventory_pool_id'])
                    ->where('event_id', $event->id)
                    ->firstOrFail()
                : TicketInventoryPool::create([
                    'event_id'       => $event->id,
                    'name'           => $data['pool_name'] ?? $data['name'] . ' Pool',
                    'capacity_units' => $data['capacity'],
                ]);

            return TicketType::create([
                'event_id'                    => $event->id,
                'inventory_pool_id'           => $pool->id,
                'code'                        => $data['code'],
                'name'                        => $data['name'],
                'category'                    => $data['category'],
                'price_minor'                 => $data['price_minor'],
                'currency'                    => $data['currency'] ?? 'MYR',
                'admission_units_per_purchase' => $data['admission_units_per_purchase'] ?? 1,
                'sale_start_at_utc'           => $data['sale_start_at_utc'] ?? null,
                'sale_end_at_utc'             => $data['sale_end_at_utc'] ?? null,
                'is_active'                   => $data['is_active'] ?? true,
            ]);
        });
    }

    /**
     * Update an existing ticket type on a draft event.
     *
     * @throws DomainException
     */
    public function update(Event $event, TicketType $ticketType, Vendor $vendor, array $data): TicketType
    {
        if ($event->vendor_id !== $vendor->id) {
            throw new DomainException('You do not own this event.');
        }

        if ($ticketType->event_id !== $event->id) {
            throw new DomainException('Ticket type does not belong to this event.');
        }

        if ($event->status !== EventStatus::Draft) {
            throw new DomainException('Ticket types can only be modified on draft events.');
        }

        $ticketType->update(array_filter([
            'name'              => $data['name'] ?? null,
            'price_minor'       => $data['price_minor'] ?? null,
            'sale_start_at_utc' => $data['sale_start_at_utc'] ?? null,
            'sale_end_at_utc'   => $data['sale_end_at_utc'] ?? null,
            'is_active'         => $data['is_active'] ?? null,
        ], fn ($v) => $v !== null));

        return $ticketType->fresh();
    }

    /**
     * Soft-delete a ticket type.
     *
     * @throws DomainException
     */
    public function delete(Event $event, TicketType $ticketType, Vendor $vendor): void
    {
        if ($event->vendor_id !== $vendor->id) {
            throw new DomainException('You do not own this event.');
        }

        if ($event->status !== EventStatus::Draft) {
            throw new DomainException('Ticket types can only be deleted on draft events.');
        }

        $ticketType->delete();
    }
}
