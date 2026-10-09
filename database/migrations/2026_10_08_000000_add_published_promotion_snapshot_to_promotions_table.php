<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add frozen publication facts and constrain each Promotion to a complete draft or published state.
     */
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->date('local_start_date')->nullable()->change();
            $table->date('local_end_date')->nullable()->change();
            $table->string('timezone_snapshot')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
        });

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
     * Restore local date representation before removing frozen publication facts.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE promotions DROP CONSTRAINT promotions_valid_publication_state');
        DB::statement(<<<'SQL'
            UPDATE promotions
            SET local_start_date = (starts_at AT TIME ZONE timezone_snapshot)::date,
                local_end_date = (ends_at AT TIME ZONE timezone_snapshot)::date - 1
            WHERE status IN ('published', 'cancelled')
            SQL);

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['timezone_snapshot', 'starts_at', 'ends_at', 'cancelled_at']);
            $table->date('local_start_date')->nullable(false)->change();
            $table->date('local_end_date')->nullable(false)->change();
        });
    }
};
