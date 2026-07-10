<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_inventory_pools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->string('name');
            $table->unsignedInteger('capacity_units');
            $table->unsignedInteger('reserved_units')->default(0);
            $table->unsignedInteger('sold_units')->default(0);
            $table->unsignedInteger('version')->default(1); // optimistic lock version
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->index(['event_id']);

            // Invariant: reserved + sold <= capacity
        });
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE ticket_inventory_pools ADD CONSTRAINT chk_capacity_positive CHECK (capacity_units >= 0)');
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE ticket_inventory_pools ADD CONSTRAINT chk_pool_capacity CHECK (reserved_units + sold_units <= capacity_units)');

        Schema::create('ticket_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('inventory_pool_id');
            $table->string('code', 50);
            $table->string('name');
            $table->string('category', 30); // early_bird|vip|general_admission|group_bundle
            $table->bigInteger('price_minor');
            $table->string('currency', 3)->default('MYR');
            $table->unsignedSmallInteger('admission_units_per_purchase')->default(1);
            $table->timestamp('sale_start_at_utc')->nullable();
            $table->timestamp('sale_end_at_utc')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('inventory_pool_id')->references('id')->on('ticket_inventory_pools');
            $table->unique(['event_id', 'code']);
            $table->index(['event_id', 'is_active']);
            $table->index(['inventory_pool_id']);

        });
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT chk_price_positive CHECK (price_minor >= 0)');
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE ticket_types ADD CONSTRAINT chk_admission_units_positive CHECK (admission_units_per_purchase > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_types');
        Schema::dropIfExists('ticket_inventory_pools');
    }
};
