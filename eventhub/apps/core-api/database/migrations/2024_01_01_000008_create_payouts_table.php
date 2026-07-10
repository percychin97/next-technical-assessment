<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vendor_id');
            $table->uuid('commission_rate_id');
            $table->uuid('payout_setting_id');
            $table->string('payout_number', 30)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('gross_amount_minor');
            $table->bigInteger('refunded_amount_minor')->default(0);
            $table->unsignedSmallInteger('commission_rate_basis_points_snapshot');
            $table->bigInteger('commission_amount_minor');
            $table->bigInteger('net_amount_minor');
            $table->bigInteger('minimum_threshold_minor_snapshot');
            $table->string('currency', 3)->default('MYR');
            $table->string('status', 20)->default('pending');
            $table->string('idempotency_key', 255)->unique();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors');
            $table->foreign('commission_rate_id')->references('id')->on('platform_commission_rates');
            $table->foreign('payout_setting_id')->references('id')->on('platform_payout_settings');

            // One payout per vendor per period
            $table->unique(['vendor_id', 'period_start', 'period_end']);
            $table->index(['vendor_id', 'created_at']);
            $table->index(['status', 'period_start', 'period_end']);

        });
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE payouts ADD CONSTRAINT chk_payout_net_positive CHECK (net_amount_minor >= 0)');

        Schema::create('payout_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payout_id');
            $table->uuid('order_item_id');
            $table->bigInteger('gross_amount_minor');
            $table->bigInteger('refunded_amount_minor')->default(0);
            $table->bigInteger('eligible_amount_minor');
            $table->timestamp('created_at');

            $table->foreign('payout_id')->references('id')->on('payouts')->onDelete('cascade');
            $table->foreign('order_item_id')->references('id')->on('order_items');
        });

        Schema::create('payout_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payout_id');
            $table->string('provider_event_id', 255)->unique();
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempt_number');
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->text('error_code')->nullable();
            $table->timestamp('attempted_at');

            $table->foreign('payout_id')->references('id')->on('payouts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_attempts');
        Schema::dropIfExists('payout_items');
        Schema::dropIfExists('payouts');
    }
};
