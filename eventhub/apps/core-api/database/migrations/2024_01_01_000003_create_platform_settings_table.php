<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_commission_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('rate_basis_points'); // 0–10000 (0–100%)
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->uuid('created_by_user_id');
            $table->timestamp('created_at');

            $table->foreign('created_by_user_id')->references('id')->on('users');

        });
        
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE platform_commission_rates ADD CONSTRAINT chk_rate_basis_points CHECK (rate_basis_points BETWEEN 0 AND 10000)');

        Schema::create('platform_payout_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('minimum_payout_minor');
            $table->string('currency', 3)->default('MYR');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->uuid('created_by_user_id');
            $table->timestamp('created_at');

            $table->foreign('created_by_user_id')->references('id')->on('users');
        });
        
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE platform_payout_settings ADD CONSTRAINT chk_min_payout_positive CHECK (minimum_payout_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payout_settings');
        Schema::dropIfExists('platform_commission_rates');
    }
};
