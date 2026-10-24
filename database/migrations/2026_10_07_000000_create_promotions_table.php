<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates Promotion storage with date, target, status, and publication-state constraints.
     */
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->date('local_start_date')->nullable();
            $table->date('local_end_date')->nullable();
            $table->unsignedInteger('target_points');
            $table->text('reward_title');
            $table->text('reward_description')->nullable();
            $table->string('timezone_snapshot')->nullable();
            $table->string('status')->default('draft');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->index(['business_id', 'status']);
        });

        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_valid_dates CHECK (local_start_date <= local_end_date)');
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promotions_positive_target CHECK (target_points > 0)');
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_valid_status CHECK (status IN ('draft', 'published', 'cancelled'))");
        DB::statement(<<<'SQL'
            ALTER TABLE promotions
            ADD CONSTRAINT promotions_valid_publication_state
            CHECK (
                (
                    status = 'draft'
                    AND local_start_date IS NOT NULL
                    AND local_end_date IS NOT NULL
                    AND timezone_snapshot IS NULL
                    AND starts_at IS NULL
                    AND ends_at IS NULL
                    AND cancelled_at IS NULL
                )
                OR
                (
                    status = 'published'
                    AND local_start_date IS NULL
                    AND local_end_date IS NULL
                    AND timezone_snapshot IS NOT NULL
                    AND btrim(timezone_snapshot) <> ''
                    AND starts_at IS NOT NULL
                    AND ends_at IS NOT NULL
                    AND starts_at < ends_at
                    AND cancelled_at IS NULL
                )
                OR
                (
                    status = 'cancelled'
                    AND local_start_date IS NULL
                    AND local_end_date IS NULL
                    AND timezone_snapshot IS NOT NULL
                    AND btrim(timezone_snapshot) <> ''
                    AND starts_at IS NOT NULL
                    AND ends_at IS NOT NULL
                    AND starts_at < ends_at
                    AND cancelled_at IS NOT NULL
                )
            )
            SQL);
    }

    /**
     * Drops Promotion storage; rollback discards existing Promotions.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
