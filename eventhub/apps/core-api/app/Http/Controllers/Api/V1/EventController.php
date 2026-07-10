<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\CreateEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Event\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(private readonly EventService $eventService) {}

    /**
     * GET /api/v1/events — Public event discovery.
     */
    public function index(Request $request): JsonResponse
    {
        $events = $this->eventService->listPublished($request->only(['search', 'per_page']));
        return ApiResponse::success($events);
    }

    /**
     * GET /api/v1/events/{id}
     */
    public function show(string $id): JsonResponse
    {
        $event = app(\App\Repositories\Contracts\EventRepositoryInterface::class)->findOrFail($id);
        $event->load(['ticketTypes.inventoryPool', 'vendor:id,business_name']);
        return ApiResponse::success($event);
    }

    /**
     * GET /api/v1/vendor/events — Vendor's own events.
     */
    public function vendorIndex(Request $request): JsonResponse
    {
        $vendor = $request->user()->vendor;
        if (!$vendor) {
            return ApiResponse::error('Vendor profile not found', 404);
        }
        $events = $this->eventService->listByVendor($vendor, $request->only(['status', 'per_page']));
        return ApiResponse::success($events);
    }

    /**
     * POST /api/v1/events — Create event (vendor only).
     */
    public function store(CreateEventRequest $request): JsonResponse
    {
        $vendor = $request->user()->vendor;
        if (!$vendor) {
            return ApiResponse::error('Vendor profile not found', 404);
        }

        $event = $this->eventService->create($vendor, $request->validated());
        return ApiResponse::created($event, 'Event created');
    }

    /**
     * PUT /api/v1/events/{id} — Update event (vendor only, draft only).
     */
    public function update(UpdateEventRequest $request, string $id): JsonResponse
    {
        $vendor = $request->user()->vendor;
        $event = app(\App\Repositories\Contracts\EventRepositoryInterface::class)->findOrFail($id);

        $this->authorize('update', $event);

        $updated = $this->eventService->update($event, $vendor, $request->validated());
        return ApiResponse::success($updated, 'Event updated');
    }

    /**
     * POST /api/v1/events/{id}/publish — Publish a draft event (vendor only).
     */
    public function publish(Request $request, string $id): JsonResponse
    {
        $vendor = $request->user()->vendor;
        $event = app(\App\Repositories\Contracts\EventRepositoryInterface::class)->findOrFail($id);

        $this->authorize('update', $event);

        $published = $this->eventService->publish($event, $vendor);
        return ApiResponse::success($published, 'Event published');
    }

    /**
     * DELETE /api/v1/events/{id} — Soft-delete / cancel a draft event.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $vendor = $request->user()->vendor;
        $event = app(\App\Repositories\Contracts\EventRepositoryInterface::class)->findOrFail($id);

        $this->authorize('delete', $event);

        $this->eventService->cancel($event, $vendor);
        return ApiResponse::success(null, 'Event cancelled');
    }
}
