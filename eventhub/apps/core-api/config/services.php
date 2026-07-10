<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Service Configuration
    |--------------------------------------------------------------------------
    */
    'payment' => [
        // Base URL of the internal payment microservice
        'url'   => env('PAYMENT_SERVICE_URL', 'http://payment-service:3001'),

        // Shared service bearer token for Core → Payment calls
        'token' => env('PAYMENT_SERVICE_TOKEN', ''),

        // HMAC secret for validating inbound payment webhook signatures
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET', ''),

        // Reject webhooks with timestamps older than this (seconds)
        'webhook_replay_window_seconds' => (int) env('PAYMENT_WEBHOOK_REPLAY_WINDOW', 300),

        // Callback URL the payment service will POST results to
        'callback_url_payments' => env('APP_URL', 'http://localhost:8000') . '/api/v1/webhooks/payments',
        'callback_url_refunds'  => env('APP_URL', 'http://localhost:8000') . '/api/v1/webhooks/refunds',
        'callback_url_payouts'  => env('APP_URL', 'http://localhost:8000') . '/api/v1/webhooks/payouts',
    ],
];
