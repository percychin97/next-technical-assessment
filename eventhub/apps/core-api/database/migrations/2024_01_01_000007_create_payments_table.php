<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('provider', 50);
            $table->string('status', 20)->default('pending');
            $table->string('idempotency_key', 255)->unique();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('MYR');
            $table->string('provider_reference', 255)->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->index(['order_id', 'status']);
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payment_id');
            $table->string('provider_event_id', 255)->unique();
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempt_number');
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->text('error_code')->nullable();
            $table->timestamp('attempted_at');

            $table->foreign('payment_id')->references('id')->on('payments')->onDelete('cascade');
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('requested_by_user_id');
            $table->uuid('reviewed_by_user_id')->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->string('status', 30)->default('requested');
            $table->text('reason')->nullable();
            $table->unsignedTinyInteger('policy_percentage_snapshot'); // 0, 50, 100
            $table->bigInteger('original_amount_minor');
            $table->bigInteger('requested_amount_minor');
            $table->bigInteger('approved_amount_minor')->nullable();
            $table->string('currency', 3)->default('MYR');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('requested_by_user_id')->references('id')->on('users');
            $table->foreign('reviewed_by_user_id')->references('id')->on('users');
            $table->index(['status', 'created_at']);

        });
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE refund_requests ADD CONSTRAINT chk_refund_approved_positive CHECK (approved_amount_minor >= 0)');

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('refund_request_id')->unique();
            $table->uuid('payment_id');
            $table->string('status', 20)->default('pending');
            $table->string('idempotency_key', 255)->unique();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('MYR');
            $table->string('provider_reference', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('refund_request_id')->references('id')->on('refund_requests');
            $table->foreign('payment_id')->references('id')->on('payments');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
    }
};
