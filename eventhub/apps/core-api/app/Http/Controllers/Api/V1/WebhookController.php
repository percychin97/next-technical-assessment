<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Order\OrderService;
use App\Services\Payout\PayoutService;
use App\Services\Refund\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WebhookController — receives HMAC-signed callbacks from the payment service.
 *
 * Signature verification:
 *   X-Webhook-Id:        Unique provider event ID (prevents replay)
 *   X-Webhook-Timestamp: Unix timestamp (prevents stale replays)
 *   X-Webhook-Signature: HMAC-SHA256(timestamp + "." + raw_body)
 *
 * On success → OrderService processes the state change.
 * On failure  → Raw deduplication guard returns 200.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly OrderService  $orderService,
        private readonly RefundService $refundService,
        private readonly PayoutService $payoutService,
    ) {}

    /**
     * POST /api/v1/webhooks/payments
     */
    public function handlePayment(Request $request): JsonResponse
    {
        if (!$this->verifySignature($request)) {
            Log::warning('[Webhook] Invalid payment signature');
            return ApiResponse::unauthorized('Invalid webhook signature');
        }

        $eventId = $request->header('X-Webhook-Id');
        $payload = $request->json()->all();

        Log::info('[Webhook] Payment callback received', [
            'event_id' => $eventId,
            'status'   => $payload['status'] ?? null,
            'order_id' => $payload['order_id'] ?? null,
        ]);

        try {
            $result = $this->orderService->processPaymentWebhook($payload, $eventId);

            $message = match ($result['status'] ?? null) {
                'payment_review' => 'Late payment accepted for compensating refund',
                'paid'           => 'Payment event processed',
                default          => 'Payment event processed',
            };

            return ApiResponse::success($result, $message);
        } catch (\Throwable $e) {
            Log::error('[Webhook] Payment processing failed', [
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ]);

            // Return 500 so the payment service retries
            return response()->json([
                'success' => false,
                'message' => 'Processing error, please retry',
            ], 500);
        }
    }

    /**
     * POST /api/v1/webhooks/refunds
     */
    public function handleRefund(Request $request): JsonResponse
    {
        if (!$this->verifySignature($request)) {
            return ApiResponse::unauthorized('Invalid webhook signature');
        }

        $eventId = $request->header('X-Webhook-Id');
        $payload = $request->json()->all();

        Log::info('[Webhook] Refund callback received', [
            'event_id'  => $eventId,
            'refund_id' => $payload['refund_id'] ?? null,
            'status'    => $payload['status'] ?? null,
        ]);

        try {
            $result = $this->refundService->processWebhook($payload, $eventId);
            return ApiResponse::success($result, 'Refund event processed');
        } catch (\Throwable $e) {
            Log::error('[Webhook] Refund processing failed', [
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Processing error, please retry'], 500);
        }
    }

    /**
     * POST /api/v1/webhooks/payouts
     */
    public function handlePayout(Request $request): JsonResponse
    {
        if (!$this->verifySignature($request)) {
            return ApiResponse::unauthorized('Invalid webhook signature');
        }

        $eventId = $request->header('X-Webhook-Id');
        $payload = $request->json()->all();

        Log::info('[Webhook] Payout callback received', [
            'event_id'  => $eventId,
            'payout_id' => $payload['payout_id'] ?? null,
            'status'    => $payload['status'] ?? null,
        ]);

        try {
            $result = $this->payoutService->processWebhook($payload, $eventId);
            return ApiResponse::success($result, 'Payout event processed');
        } catch (\Throwable $e) {
            Log::error('[Webhook] Payout processing failed', [
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Processing error, please retry'], 500);
        }
    }

    // ─── HMAC Signature Verification ────────────────────────────────────────────

    private function verifySignature(Request $request): bool
    {
        $secret    = config('services.payment.webhook_secret');
        $id        = $request->header('X-Webhook-Id');
        $timestamp = $request->header('X-Webhook-Timestamp');
        $signature = $request->header('X-Webhook-Signature');

        if (!$id || !$timestamp || !$signature || !$secret) {
            return false;
        }

        // Reject replays outside the allowed window (5 minutes default)
        $replayWindow = (int) config('services.payment.webhook_replay_window_seconds', 300);
        if (abs(time() - (int) $timestamp) > $replayWindow) {
            Log::warning('[Webhook] Replay window exceeded', ['timestamp' => $timestamp]);
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            "{$timestamp}.{$request->getContent()}",
            $secret
        );

        return hash_equals($expected, $signature);
    }
}
