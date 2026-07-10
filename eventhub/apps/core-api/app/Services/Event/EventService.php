<?php

namespace App\Services\Event;

use App\Enums\EventStatus;
use App\Exceptions\DomainException;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\VendorNotVerifiedException;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\TicketInventoryPool;
use App\Models\Vendor;
use App\Repositories\Contracts\EventRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventService
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository
    ) {}

    /**
     * Create a draft event for a verified vendor.
     *
     * @throws VendorNotVerifiedException
     */
    public function create(Vendor $vendor, array $data): Event
    {
        if (!$vendor->isVerified()) {
            throw new VendorNotVerifiedException(
                'Only verified vendors can create events.'
            );
        }

        return DB::transaction(function () use ($vendor, $data) {
            $event = $this->eventRepository->create([
                'vendor_id'        => $vendor->id,
                'title'            => $data['title'],
                'description'      => $data['description'] ?? null,
                'start_at_utc'     => $data['start_at_utc'],
                'end_at_utc'       => $data['end_at_utc'],
                'display_timezone' => $data['display_timezone'] ?? 'Asia/Kuala_Lumpur',
                'status'           => EventStatus::Draft->value,
            ]);

            $this->writeAudit($event, null, EventStatus::Draft, null, $vendor->user_id);

            return $event;
        });
    }

    /**
     * Update a draft event.
     *
     * @throws DomainException
     */
    public function update(Event $event, Vendor $vendor, array $data): Event
    {
        if ($event->vendor_id !== $vendor->id) {
            throw new DomainException('You do not own this event.');
        }

        if ($event->status !== EventStatus::Draft) {
            throw new DomainException('Only draft events can be updated.');
        }

        return DB::transaction(function () use ($event, $vendor, $data) {
            $updated = $this->eventRepository->update($event, array_filter([
                'title'            => $data['title'] ?? null,
                'description'      => $data['description'] ?? null,
                'start_at_utc'     => $data['start_at_utc'] ?? null,
                'end_at_utc'       => $data['end_at_utc'] ?? null,
                'display_timezone' => $data['display_timezone'] ?? null,
            ], fn ($v) => $v !== null));

            $this->writeAudit($updated, EventStatus::Draft, EventStatus::Draft, null, $vendor->user_id);

            return $updated;
        });
    }

    /**
     * Publish a draft event — only verified vendors.
     *
     * @throws VendorNotVerifiedException
     * @throws InvalidStateTransitionException
     */
    public function publish(Event $event, Vendor $vendor): Event
    {
        if (!$vendor->isVerified()) {
            throw new VendorNotVerifiedException('Only verified vendors can publish events.');
        }

        if ($event->vendor_id !== $vendor->id) {
            throw new DomainException('You do not own this event.');
        }

        if (!$event->status->canTransitionTo(EventStatus::Published)) {
            throw new InvalidStateTransitionException(
                "Cannot publish an event in status '{$event->status->value}'."
            );
        }

        return DB::transaction(function () use ($event, $vendor) {
            $previous = $event->status;
            $updated = $this->eventRepository->update($event, [
                'status'       => EventStatus::Published->value,
                'published_at' => now(),
            ]);

            $this->writeAudit($updated, $previous, EventStatus::Published, null, $vendor->user_id);

            return $updated;
        });
    }

    /**
     * Cancel a draft event directly. Published events require admin approval flow.
     *
     * @throws InvalidStateTransitionException
     */
    public function cancel(Event $event, Vendor $vendor): Event
    {
        if ($event->vendor_id !== $vendor->id) {
            throw new DomainException('You do not own this event.');
        }

        if (!$event->status->canTransitionTo(EventStatus::Cancelled)) {
            throw new InvalidStateTransitionException(
                "Cannot cancel an event in status '{$event->status->value}' directly. Use admin flow for published events with paid orders."
            );
        }

        return DB::transaction(function () use ($event, $vendor) {
            $previous = $event->status;
            $updated = $this->eventRepository->update($event, [
                'status'       => EventStatus::Cancelled->value,
                'cancelled_at' => now(),
            ]);

            $this->writeAudit($updated, $previous, EventStatus::Cancelled, null, $vendor->user_id);

            return $updated;
        });
    }

    public function listPublished(array $filters = []): LengthAwarePaginator
    {
        return $this->eventRepository->findPublished($filters);
    }

    public function listByVendor(Vendor $vendor, array $filters = []): LengthAwarePaginator
    {
        return $this->eventRepository->findByVendor($vendor->id, $filters);
    }

    private function writeAudit(
        Event $event,
        ?EventStatus $previousStatus,
        EventStatus $newStatus,
        ?array $extra,
        string $actorUserId
    ): void {
        AuditLog::create([
            'entity_type'     => 'event',
            'entity_id'       => $event->id,
            'action'          => 'status_changed',
            'previous_status' => $previousStatus?->value,
            'new_status'      => $newStatus->value,
            'before_state'    => $previousStatus ? ['status' => $previousStatus->value] : null,
            'after_state'     => ['status' => $newStatus->value],
            'actor_user_id'   => $actorUserId,
            'correlation_id'  => (string) Str::uuid(),
            'created_at'      => now(),
        ]);
    }
}
