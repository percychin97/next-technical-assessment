<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Event;
use App\Models\TicketInventoryPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryPoolController extends Controller
{
    /**
     * POST /api/v1/events/{eventId}/inventory-pools
     */
    public function store(Request $request, string $eventId): JsonResponse
    {
        $event = Event::findOrFail($eventId);
        $this->authorize('update', $event);

        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:100'],
            'capacity_units' => ['required', 'integer', 'min:1'],
        ]);

        $pool = TicketInventoryPool::create([
            'event_id'       => $event->id,
            'name'           => $validated['name'],
            'capacity_units' => $validated['capacity_units'],
            'reserved_units' => 0,
            'sold_units'     => 0,
            'version'        => 1,
        ]);

        return ApiResponse::created($pool, 'Inventory pool created');
    }
}
