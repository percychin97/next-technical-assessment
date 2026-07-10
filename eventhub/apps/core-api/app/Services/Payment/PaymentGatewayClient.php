<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the internal payment service.
 *
 * All requests use a shared Bearer token for service-to-service authentication.
 * The payment service responds immediately with `pending`; the final outcome
 * arrives via a signed webhook callback to Core API.
 */
class PaymentGatewayClient
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.payment.url'), '/');
        $this->token   = (string) config('services.payment.token');
    }

    /**
     * POST /internal/v1/payments — initiate a new payment.
     *
     * @throws \RuntimeException if payment service is unavailable
     */
    public function createPayment(array $payload): array
    {
        return $this->post('/internal/v1/payments', $payload);
    }

    /**
     * POST /internal/v1/refunds — execute a refund.
     */
    public function createRefund(array $payload): array
    {
        return $this->post('/internal/v1/refunds', $payload);
    }

    /**
     * POST /internal/v1/payouts — execute a vendor payout.
     */
    public function createPayout(array $payload): array
    {
        return $this->post('/internal/v1/payouts', $payload);
    }

    private function post(string $path, array $payload): array
    {
        $idempotencyKey = $payload['idempotency_key'] ?? null;

        try {
            $response = Http::withToken($this->token)
                ->withHeaders(array_filter([
                    'Idempotency-Key' => $idempotencyKey,
                    'Accept'          => 'application/json',
                ]))
                ->timeout(10)
                ->post("{$this->baseUrl}{$path}", $payload);

            if ($response->failed()) {
                Log::error('[PaymentGatewayClient] Request failed', [
                    'path'   => $path,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                // 503 for payment service unavailable — reservation stays valid until expiry
                if ($response->status() >= 500) {
                    throw new \RuntimeException(
                        'Payment service is currently unavailable. Your reservation is still held.',
                        503
                    );
                }

                throw new \RuntimeException(
                    "Payment service error: {$response->body()}",
                    $response->status()
                );
            }

            return $response->json();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('[PaymentGatewayClient] Connection failed', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Payment service is currently unavailable. Your reservation is still held.',
                503
            );
        }
    }
}
