<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Creates pass and Promotion associations with database-enforced shared ownership. */
    public function up(): void
    {
        Schema::table('customer_passes', function (Blueprint $table) {
            $table->unique(['business_id', 'id']);
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->unique(['business_id', 'id']);
        });

        Schema::create('promotion_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id');
            $table->foreignId('customer_pass_id');
            $table->foreignId('promotion_id');
            $table->timestampsTz();

            $table->unique(['customer_pass_id', 'promotion_id']);
            $table->index('business_id');
            $table->index('promotion_id');

            $table->foreign(['business_id', 'customer_pass_id'], 'participations_business_pass_foreign')
                ->references(['business_id', 'id'])->on('customer_passes')->restrictOnDelete();
            $table->foreign(['business_id', 'promotion_id'], 'participations_business_promotion_foreign')
                ->references(['business_id', 'id'])->on('promotions')->restrictOnDelete();
        });
    }

    /** Drops associations before their parent keys; rollback discards existing participation records. */
    public function down(): void
    {
        Schema::dropIfExists('promotion_participations');

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'id']);
        });

        Schema::table('customer_passes', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'id']);
        });
    }
};
