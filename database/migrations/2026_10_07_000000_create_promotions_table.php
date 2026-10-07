<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->date('local_start_date');
            $table->date('local_end_date');
            $table->unsignedInteger('target_points');
            $table->text('reward_title');
            $table->text('reward_description')->nullable();
            $table->string('status')->default('draft');
            $table->timestampsTz();
            $table->index(['business_id', 'status']);
        });

        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_valid_dates CHECK (local_start_date <= local_end_date)');
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_positive_target CHECK (target_points > 0)');
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_valid_status CHECK (status IN ('draft', 'published', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
