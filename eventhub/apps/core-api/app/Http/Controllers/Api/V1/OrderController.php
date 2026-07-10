<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CreateOrderRequest;
use App\Http\Requests\Order\InitiatePaymentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Services\Order\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService) {}

    /**
     * GET /api/v1/orders
     * List authenticated attendee's orders.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::where('user_id', $request->user()->id)
            ->with(['items', 'event:id,title,start_at_utc,display_timezone'])
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($orders);
    }

    /**
     * GET /api/v1/orders/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $order = Order::with(['items', 'reservations', 'tickets', 'event:id,title,start_at_utc'])
            ->findOrFail($id);

        $this->authorize('view', $order);

        return ApiResponse::success($order);
    }

    /**
     * POST /api/v1/orders
     * Reserve tickets — the core flow with distributed locking.
     */
    public function store(CreateOrderRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key') ?? (string) \Illuminate\Support\Str::uuid();

        $order = $this->orderService->reserve(
            $request->user(),
            array_merge($request->validated(), ['idempotency_key' => $idempotencyKey])
        );

        return ApiResponse::created(
            [
                'order_id'       => $order->id,
                'order_number'   => $order->order_number,
                'status'         => $order->status->value,
                'hold_expires_at' => $order->hold_expires_at?->toISOString(),
                'total_amount_minor' => $order->total_amount_minor,
                'currency'       => $order->currency,
                'items'          => $order->items->map(fn ($i) => [
                    'ticket_type_id'   => $i->ticket_type_id,
                    'name'             => $i->ticket_type_name_snapshot,
                    'quantity'         => $i->purchase_quantity,
                    'unit_price_minor' => $i->unit_price_minor_snapshot,
                    'subtotal_minor'   => $i->subtotal_minor,
                ]),
            ],
            'Tickets reserved for 15 minutes'
        );
    }

    /**
     * POST /api/v1/orders/{id}/payments
     * Initiate payment for a reserved order.
     */
    public function initiatePayment(InitiatePaymentRequest $request, string $id): JsonResponse
    {
        $order = Order::findOrFail($id);
        $this->authorize('view', $order);

        $idempotencyKey = $request->header('Idempotency-Key') ?? (string) \Illuminate\Support\Str::uuid();

        $payment = $this->orderService->initiatePayment(
            $order,
            $request->user(),
            $request->validated('provider'),
            $idempotencyKey
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'payment_id' => $payment->id,
                'status'     => $payment->status->value,
                'provider'   => $payment->provider,
            ],
            'message' => 'Payment initiated',
        ], 202);
    }
}
