<?php

use App\Http\Controllers\Api\V1\AdminVendorController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PayoutController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\TicketTypeController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — /api/v1 (prefix set in bootstrap/app.php)
|--------------------------------------------------------------------------
*/

// ─── Auth (public) ───────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('/login',    [AuthController::class, 'login'])->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/me',      [AuthController::class, 'me'])->name('auth.me');
    });
});

// ─── Public event discovery ───────────────────────────────────────────────────
Route::get('/events',      [EventController::class, 'index'])->name('events.index');
Route::get('/events/{id}', [EventController::class, 'show'])->name('events.show');
Route::get('/events/{eventId}/ticket-types', [TicketTypeController::class, 'index'])
    ->name('ticket-types.index');

// ─── Webhooks (authenticated by HMAC, not Sanctum) ───────────────────────────
Route::prefix('webhooks')->group(function () {
    Route::post('/payments', [WebhookController::class, 'handlePayment'])->name('webhooks.payments');
    Route::post('/refunds',  [WebhookController::class, 'handleRefund'])->name('webhooks.refunds');
    Route::post('/payouts',  [WebhookController::class, 'handlePayout'])->name('webhooks.payouts');
});

// ─── Authenticated routes ─────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // ─── Attendee: Orders ─────────────────────────────────────────────────────
    Route::get('/orders',                          [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{id}',                     [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders',                         [OrderController::class, 'store'])->name('orders.store');
    Route::post('/orders/{id}/payments',           [OrderController::class, 'initiatePayment'])->name('orders.payments.initiate');

    // ─── Attendee: Refunds ────────────────────────────────────────────────────
    Route::get('/orders/{id}/refund-policy',       [RefundController::class, 'policyPreview'])->name('orders.refund-policy');
    Route::get('/orders/{id}/refund-request',      [RefundController::class, 'showForOrder'])->name('orders.refund-request.show');
    Route::post('/orders/{id}/refund-request',     [RefundController::class, 'store'])->name('orders.refund-request.store');

    // ─── Vendor event management ──────────────────────────────────────────────
    Route::middleware('role:vendor')->group(function () {
        Route::get('/vendor/events',               [EventController::class, 'vendorIndex'])->name('vendor.events.index');
        Route::post('/events',                     [EventController::class, 'store'])->name('events.store');
        Route::put('/events/{id}',                 [EventController::class, 'update'])->name('events.update');
        Route::delete('/events/{id}',              [EventController::class, 'destroy'])->name('events.destroy');
        Route::post('/events/{id}/publish',        [EventController::class, 'publish'])->name('events.publish');

        // Inventory pools
        Route::post('/events/{eventId}/inventory-pools', [\App\Http\Controllers\Api\V1\InventoryPoolController::class, 'store'])
            ->name('inventory-pools.store');

        // Ticket types
        Route::post('/events/{eventId}/ticket-types',           [TicketTypeController::class, 'store'])->name('ticket-types.store');
        Route::put('/events/{eventId}/ticket-types/{id}',       [TicketTypeController::class, 'update'])->name('ticket-types.update');
        Route::delete('/events/{eventId}/ticket-types/{id}',    [TicketTypeController::class, 'destroy'])->name('ticket-types.destroy');

        // Vendor: payout history
        Route::get('/vendor/payouts', [PayoutController::class, 'vendorIndex'])->name('vendor.payouts.index');
    });

    // ─── Admin ────────────────────────────────────────────────────────────────
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/vendors',               [AdminVendorController::class, 'index'])->name('admin.vendors.index');
        Route::post('/vendors/{id}/approve', [AdminVendorController::class, 'approve'])->name('admin.vendors.approve');
        Route::post('/vendors/{id}/reject',  [AdminVendorController::class, 'reject'])->name('admin.vendors.reject');

        // Refund management
        Route::get('/refund-requests',               [RefundController::class, 'adminIndex'])->name('admin.refund-requests.index');
        Route::get('/refund-requests/{id}',          [RefundController::class, 'adminShow'])->name('admin.refund-requests.show');
        Route::post('/refund-requests/{id}/approve', [RefundController::class, 'approve'])->name('admin.refund-requests.approve');
        Route::post('/refund-requests/{id}/deny',    [RefundController::class, 'deny'])->name('admin.refund-requests.deny');

        // Payout management
        Route::get('/payouts/preview',   [PayoutController::class, 'preview'])->name('admin.payouts.preview');
        Route::get('/payouts',           [PayoutController::class, 'index'])->name('admin.payouts.index');
        Route::post('/payouts',          [PayoutController::class, 'store'])->name('admin.payouts.store');
        Route::get('/payouts/{id}',      [PayoutController::class, 'show'])->name('admin.payouts.show');
        Route::post('/payouts/{id}/approve', [PayoutController::class, 'approve'])->name('admin.payouts.approve');
    });
});
