<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('event_id');
            $table->string('order_number', 30)->unique();
            $table->string('creation_idempotency_key', 255)->unique();
            $table->string('status', 30)->default('awaiting_payment');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('total_amount_minor');
            $table->string('currency', 3)->default('MYR');
            $table->timestamp('hold_expires_at')->nullable();
            $table->text('payment_review_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('event_id')->references('id')->on('events');
            $table->index(['user_id', 'created_at']);
            $table->index(['event_id', 'status']);
            $table->index(['status', 'hold_expires_at']);

        });
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE orders ADD CONSTRAINT chk_order_amount_positive CHECK (total_amount_minor >= 0)');

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('ticket_type_id');
            $table->string('ticket_type_name_snapshot');
            $table->unsignedInteger('purchase_quantity');
            $table->unsignedSmallInteger('admission_units_per_purchase_snapshot');
            $table->unsignedInteger('admission_quantity');
            $table->bigInteger('unit_price_minor_snapshot');
            $table->bigInteger('subtotal_minor');
            $table->string('currency', 3)->default('MYR');
            $table->timestamp('created_at');

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('ticket_type_id')->references('id')->on('ticket_types');
        });

        Schema::create('ticket_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('ticket_type_id');
            $table->uuid('inventory_pool_id');
            $table->unsignedInteger('purchase_quantity');
            $table->unsignedInteger('reserved_units');
            $table->string('status', 20)->default('held'); // held|confirmed|released|expired
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('ticket_type_id')->references('id')->on('ticket_types');
            $table->foreign('inventory_pool_id')->references('id')->on('ticket_inventory_pools');
            $table->index(['status', 'expires_at']);
            $table->index(['inventory_pool_id', 'status']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('order_item_id');
            $table->uuid('ticket_type_id');
            $table->string('ticket_number', 30)->unique();
            $table->string('qr_token_hash', 64)->unique();
            $table->string('status', 20)->default('valid'); // valid|checked_in|voided
            $table->timestamp('checked_in_at')->nullable();
            $table->uuid('checked_in_by')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('order_item_id')->references('id')->on('order_items');
            $table->foreign('ticket_type_id')->references('id')->on('ticket_types');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_reservations');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
