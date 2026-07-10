<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Services\Refund\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refundService) {}

    // ─── Attendee endpoints ──────────────────────────────────────────────────

    /**
     * POST /api/v1/orders/{id}/refund-request
     * Attendee requests a refund for a paid order.
     */
    public function store(Request $request, string $orderId): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = Order::findOrFail($orderId);
        $this->authorize('view', $order);

        $idempotencyKey = $request->header('Idempotency-Key') ?? (string) \Illuminate\Support\Str::uuid();

        $refundRequest = $this->refundService->requestRefund(
            $request->user(),
            $order,
            array_merge($request->only('reason'), ['idempotency_key' => $idempotencyKey])
        );

        return ApiResponse::created(
            $this->formatRefundRequest($refundRequest),
            'Refund request submitted'
        );
    }

    /**
     * GET /api/v1/orders/{id}/refund-request
     * Attendee views the refund request for their order.
     */
    public function showForOrder(Request $request, string $orderId): JsonResponse
    {
        $order = Order::findOrFail($orderId);
        $this->authorize('view', $order);

        $refundRequest = RefundRequest::where('order_id', $order->id)
            ->latest()
            ->firstOrFail();

        return ApiResponse::success($this->formatRefundRequest($refundRequest));
    }

    /**
     * GET /api/v1/orders/{id}/refund-policy
     * Preview the refund policy without creating a request.
     */
    public function policyPreview(Request $request, string $orderId): JsonResponse
    {
        $order = Order::findOrFail($orderId);
        $this->authorize('view', $order);

        $policy = $this->refundService->calculatePolicy($order);

        return ApiResponse::success([
            'percentage'            => $policy['percentage'],
            'eligible_amount_minor' => $policy['eligible_amount_minor'],
            'hours_until_event'     => round($policy['hours_until_event'], 1),
            'currency'              => $order->currency,
            'total_order_amount'    => $order->total_amount_minor,
        ]);
    }

    // ─── Admin endpoints ─────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/refund-requests
     * Admin views all pending refund requests (paginated).
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $requests = RefundRequest::with(['order:id,order_number,event_id', 'requestedBy:id,email'])
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($requests);
    }

    /**
     * GET /api/v1/admin/refund-requests/{id}
     */
    public function adminShow(string $id): JsonResponse
    {
        $refundRequest = RefundRequest::with(['order', 'requestedBy', 'reviewedBy', 'refund'])
            ->findOrFail($id);

        return ApiResponse::success($this->formatRefundRequest($refundRequest));
    }

    /**
     * POST /api/v1/admin/refund-requests/{id}/approve
     * Admin approves a refund request, optionally overriding the amount.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'override_amount_minor' => ['nullable', 'integer', 'min:1'],
        ]);

        $refundRequest = RefundRequest::findOrFail($id);

        $approved = $this->refundService->approveRefund(
            $request->user(),
            $refundRequest,
            $request->input('override_amount_minor')
        );

        return ApiResponse::success(
            $this->formatRefundRequest($approved),
            'Refund approved and dispatched to payment service'
        );
    }

    /**
     * POST /api/v1/admin/refund-requests/{id}/deny
     * Admin denies a refund request.
     */
    public function deny(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $refundRequest = RefundRequest::findOrFail($id);

        $denied = $this->refundService->denyRefund(
            $request->user(),
            $refundRequest,
            $request->input('reason')
        );

        return ApiResponse::success(
            $this->formatRefundRequest($denied),
            'Refund request denied'
        );
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function formatRefundRequest(RefundRequest $r): array
    {
        return [
            'id'                         => $r->id,
            'order_id'                   => $r->order_id,
            'status'                     => $r->status->value,
            'reason'                     => $r->reason,
            'policy_percentage_snapshot' => $r->policy_percentage_snapshot,
            'original_amount_minor'      => $r->original_amount_minor,
            'requested_amount_minor'     => $r->requested_amount_minor,
            'approved_amount_minor'      => $r->approved_amount_minor,
            'currency'                   => $r->currency,
            'calculated_at'              => $r->calculated_at?->toISOString(),
            'reviewed_at'                => $r->reviewed_at?->toISOString(),
            'created_at'                 => $r->created_at?->toISOString(),
        ];
    }
}
