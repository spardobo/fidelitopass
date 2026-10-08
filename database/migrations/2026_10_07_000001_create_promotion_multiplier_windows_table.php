<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the Promotion multiplier-window table and enforce its persisted domain constraints.
     */
    public function up(): void
    {
        Schema::create('promotion_multiplier_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('weekday');
            $table->unsignedSmallInteger('multiplier');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestampsTz();
            $table->index(['promotion_id', 'weekday']);
        });

        DB::statement('ALTER TABLE promotion_multiplier_windows ADD CONSTRAINT promotion_windows_valid_weekday CHECK (weekday BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE promotion_multiplier_windows ADD CONSTRAINT promotion_windows_valid_multiplier CHECK (multiplier IN (2, 3, 5))');
        DB::statement('ALTER TABLE promotion_multiplier_windows ADD CONSTRAINT promotion_windows_valid_times CHECK ((start_time IS NULL AND end_time IS NULL) OR (start_time IS NOT NULL AND end_time IS NOT NULL AND start_time < end_time))');
    }

    /**
     * Drop the Promotion multiplier-window table when rolling back this migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotion_multiplier_windows');
    }
};
