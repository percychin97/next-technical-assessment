<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('event_id');
            $table->uuid('ticket_type_id');
            $table->unsignedSmallInteger('requested_purchase_quantity');
            $table->string('status', 20)->default('active');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('event_id')->references('id')->on('events');
            $table->foreign('ticket_type_id')->references('id')->on('ticket_types');
            $table->index(['ticket_type_id', 'status', 'created_at']);
        });

        Schema::create('vendor_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vendor_id');
            $table->string('url');
            $table->text('encrypted_secret');
            $table->jsonb('subscribed_events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('cascade');
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('event_id');
            $table->uuid('opened_by_user_id');
            $table->uuid('resolved_by_user_id')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('reason');
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('event_id')->references('id')->on('events');
            $table->foreign('opened_by_user_id')->references('id')->on('users');
        });

        Schema::create('daily_sales_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vendor_id');
            $table->uuid('event_id');
            $table->date('report_date');
            $table->bigInteger('gross_sales_minor')->default(0);
            $table->bigInteger('refunded_amount_minor')->default(0);
            $table->bigInteger('net_sales_minor')->default(0);
            $table->unsignedInteger('tickets_sold')->default(0);
            $table->string('currency', 3)->default('MYR');
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors');
            $table->foreign('event_id')->references('id')->on('events');
            $table->unique(['vendor_id', 'event_id', 'report_date']);
            $table->index(['report_date']);
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 100);
            $table->uuid('aggregate_id');
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('publish_attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 100);
            $table->uuid('entity_id');
            $table->string('action', 100);
            $table->string('previous_status', 50)->nullable();
            $table->string('new_status', 50)->nullable();
            $table->jsonb('before_state')->nullable();
            $table->jsonb('after_state')->nullable();
            $table->uuid('actor_user_id')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('created_at');

            $table->foreign('actor_user_id')->references('id')->on('users');
            $table->index(['entity_type', 'entity_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('daily_sales_reports');
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('vendor_webhooks');
        Schema::dropIfExists('waitlist_entries');
    }
};
