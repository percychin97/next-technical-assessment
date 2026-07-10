<?php

namespace Tests\Unit\Services;

use App\Enums\EventStatus;
use App\Enums\KycStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\VendorNotVerifiedException;
use App\Models\Event;
use App\Models\Vendor;
use App\Repositories\Contracts\EventRepositoryInterface;
use App\Services\Event\EventService;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * EventService Unit Tests
 *
 * Tests business rules without database access.
 */
class EventServiceTest extends TestCase
{
    private EventService $service;
    private MockObject $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(EventRepositoryInterface::class);
        $this->service    = new EventService($this->repository);
    }

    /** @test */
    public function it_prevents_unverified_vendors_from_creating_events(): void
    {
        $vendor             = new Vendor();
        $vendor->kyc_status = KycStatus::Pending;

        $this->expectException(VendorNotVerifiedException::class);

        $this->service->create($vendor, [
            'title'        => 'Test Event',
            'start_at_utc' => now()->addDay(),
            'end_at_utc'   => now()->addDay()->addHours(4),
        ]);
    }

    /** @test */
    public function it_prevents_publishing_a_non_draft_event(): void
    {
        $vendor             = new Vendor();
        $vendor->kyc_status = KycStatus::Verified;
        $vendor->id         = 'vendor-uuid';

        $event            = new Event();
        $event->status    = EventStatus::Published; // Already published
        $event->vendor_id = 'vendor-uuid';

        $this->expectException(InvalidStateTransitionException::class);

        $this->service->publish($event, $vendor);
    }

    /** @test */
    public function event_status_transitions_are_enforced_by_enum(): void
    {
        $this->assertFalse(
            EventStatus::Published->canTransitionTo(EventStatus::Draft),
            'Published events cannot revert to draft'
        );

        $this->assertTrue(
            EventStatus::Draft->canTransitionTo(EventStatus::Published),
            'Draft events can be published'
        );

        $this->assertFalse(
            EventStatus::Completed->canTransitionTo(EventStatus::Cancelled),
            'Completed events cannot be cancelled'
        );
    }
}
