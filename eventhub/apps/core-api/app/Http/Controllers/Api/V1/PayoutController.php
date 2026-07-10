<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Payout;
use App\Services\Payout\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payoutService) {}

    // ─── Admin endpoints ─────────────────────────────────────────────────────

    /**
     * GET /api/v1/admin/payouts/preview
     * Preview a payout calculation before creating it.
     *
     * Query params: vendor_id, period_start (Y-m-d), period_end (Y-m-d)
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id'    => ['required', 'uuid', 'exists:vendors,id'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end'   => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ]);

        $preview = $this->payoutService->calculatePreview(
            $request->input('vendor_id'),
            $request->input('period_start'),
            $request->input('period_end')
        );

        return ApiResponse::success($preview, 'Payout preview calculated');
    }

    /**
     * POST /api/v1/admin/payouts
     * Create a pending payout for a vendor.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'vendor_id'    => ['required', 'uuid', 'exists:vendors,id'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end'   => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key') ?? (string) \Illuminate\Support\Str::uuid();

        $payout = $this->payoutService->createPayout(
            $request->input('vendor_id'),
            $request->input('period_start'),
            $request->input('period_end'),
            $idempotencyKey
        );

        return ApiResponse::created(
            $this->formatPayout($payout),
            'Payout created'
        );
    }

    /**
     * GET /api/v1/admin/payouts
     * List all payouts (paginated), optionally filtered by vendor or status.
     */
    public function index(Request $request): JsonResponse
    {
        $payouts = Payout::with('vendor:id,business_name')
            ->when($request->input('vendor_id'), fn ($q, $v) => $q->where('vendor_id', $v))
            ->when($request->input('status'),    fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($payouts);
    }

    /**
     * GET /api/v1/admin/payouts/{id}
     * Payout detail with items.
     */
    public function show(string $id): JsonResponse
    {
        $payout = Payout::with(['vendor:id,business_name', 'items', 'attempts'])
            ->findOrFail($id);

        return ApiResponse::success($this->formatPayout($payout, true));
    }

    /**
     * POST /api/v1/admin/payouts/{id}/approve
     * Approve a pending payout and dispatch to the payment service.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $payout = Payout::findOrFail($id);

        $approved = $this->payoutService->approvePayout($request->user(), $payout);

        return ApiResponse::success(
            $this->formatPayout($approved),
            'Payout approved and dispatched for processing'
        );
    }

    // ─── Vendor endpoint ─────────────────────────────────────────────────────

    /**
     * GET /api/v1/vendor/payouts
     * Vendor views their own payout history.
     */
    public function vendorIndex(Request $request): JsonResponse
    {
        $vendorId = $request->user()->vendor?->id;

        if (!$vendorId) {
            return ApiResponse::forbidden('No vendor profile found for this account.');
        }

        $payouts = Payout::where('vendor_id', $vendorId)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success($payouts);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function formatPayout(Payout $p, bool $withItems = false): array
    {
        $data = [
            'id'                                    => $p->id,
            'payout_number'                         => $p->payout_number,
            'vendor_id'                             => $p->vendor_id,
            'period_start'                          => $p->period_start?->toDateString(),
            'period_end'                            => $p->period_end?->toDateString(),
            'gross_amount_minor'                    => $p->gross_amount_minor,
            'refunded_amount_minor'                 => $p->refunded_amount_minor,
            'commission_rate_basis_points_snapshot' => $p->commission_rate_basis_points_snapshot,
            'commission_amount_minor'               => $p->commission_amount_minor,
            'net_amount_minor'                      => $p->net_amount_minor,
            'currency'                              => $p->currency,
            'status'                                => $p->status->value,
            'approved_at'                           => $p->approved_at?->toISOString(),
            'completed_at'                          => $p->completed_at?->toISOString(),
            'created_at'                            => $p->created_at?->toISOString(),
        ];

        if ($withItems && $p->relationLoaded('items')) {
            $data['items'] = $p->items->map(fn ($i) => [
                'id'                    => $i->id,
                'order_item_id'         => $i->order_item_id,
                'gross_amount_minor'    => $i->gross_amount_minor,
                'eligible_amount_minor' => $i->eligible_amount_minor,
            ])->all();
        }

        return $data;
    }
}
