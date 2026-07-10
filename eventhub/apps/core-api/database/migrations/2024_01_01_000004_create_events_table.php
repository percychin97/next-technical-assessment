<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vendor_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('start_at_utc');
            $table->timestamp('end_at_utc');
            $table->string('display_timezone', 64)->default('Asia/Kuala_Lumpur');
            $table->string('status', 30)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('vendor_id')->references('id')->on('vendors');
            $table->index(['status', 'start_at_utc']);
            $table->index(['vendor_id', 'status']);

            // start_at_utc < end_at_utc
        });
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE events ADD CONSTRAINT chk_event_dates CHECK (start_at_utc < end_at_utc)');
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
