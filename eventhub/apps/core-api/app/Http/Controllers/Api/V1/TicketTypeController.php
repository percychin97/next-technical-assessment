<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TicketType\CreateTicketTypeRequest;
use App\Http\Requests\TicketType\UpdateTicketTypeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\TicketType;
use App\Services\TicketType\TicketTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketTypeController extends Controller
{
    public function __construct(private readonly TicketTypeService $ticketTypeService) {}

    /**
     * GET /api/v1/events/{eventId}/ticket-types
     */
    public function index(string $eventId): JsonResponse
    {
        $event = app(\App\Repositories\Contracts\EventRepositoryInterface::class)->findOrFail($eventId);

        $ticketTypes = $event->ticketTypes()
            ->with('inventoryPool')
            ->get();

        return ApiResponse::success($ticketTypes);
    }

    /**
     * POST /api/v1/events/{eventId}/ticket-types
     */
    public function store(CreateTicketTypeRequest $request, string $eventId): JsonResponse
    {
        $vendor = $request->user()->vendor;
        $event = app(\App\Repositories\Contracts\EventRepositoryInterface::class)->findOrFail($eventId);

        $this->authorize('update', $event);

        $ticketType = $this->ticketTypeService->create($event, $vendor, $request->validated());

        return ApiResponse::created($ticketType->load('inventoryPool'), 'Ticket type created');
    }

    /**
     * PUT /api/v1/events/{eventId}/ticket-types/{id}
     */
    public function update(UpdateTicketTypeRequest $request, string $eventId, string $id): JsonResponse
    {
        $vendor = $request->user()->vendor;
        $event = app(\App\Repositories\Contracts\EventRepositoryInterface::class)->findOrFail($eventId);
        $ticketType = TicketType::findOrFail($id);

        $this->authorize('update', $event);

        $updated = $this->ticketTypeService->update($event, $ticketType, $vendor, $request->validated());

        return ApiResponse::success($updated, 'Ticket type updated');
    }

    /**
     * DELETE /api/v1/events/{eventId}/ticket-types/{id}
     */
    public function destroy(Request $request, string $eventId, string $id): JsonResponse
    {
        $vendor = $request->user()->vendor;
        $event = app(\App\Repositories\Contracts\EventRepositoryInterface::class)->findOrFail($eventId);
        $ticketType = TicketType::findOrFail($id);

        $this->authorize('delete', $event);

        $this->ticketTypeService->delete($event, $ticketType, $vendor);

        return ApiResponse::success(null, 'Ticket type deleted');
    }
}
