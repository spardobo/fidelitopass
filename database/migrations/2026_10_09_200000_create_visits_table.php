<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Creates accepted Visit storage with shared ownership and operation integrity. */
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id');
            $table->foreignId('customer_pass_id');
            $table->foreignId('promotion_id');
            $table->foreignId('confirmed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->integer('awarded_points');
            $table->timestampTz('confirmed_at', 6);
            $table->timestampsTz();

            // Summary reads accepted activity for one Business and its active Promotion.
            $table->index(['business_id', 'promotion_id', 'customer_pass_id']);

            $table->foreign(['business_id', 'customer_pass_id'], 'visits_business_pass_foreign')
                ->references(['business_id', 'id'])->on('customer_passes')->restrictOnDelete();
            $table->foreign(['business_id', 'promotion_id'], 'visits_business_promotion_foreign')
                ->references(['business_id', 'id'])->on('promotions')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE visits ADD CONSTRAINT visits_awarded_points_positive CHECK (awarded_points > 0)');
    }

    /** Drops only Visit persistence; rollback discards accepted Visit history when populated. */
    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
