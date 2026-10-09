<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Creates one same-Business entitlement per pass and Promotion with paired final redemption facts. */
    public function up(): void
    {
        Schema::create('reward_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id');
            $table->foreignId('customer_pass_id');
            $table->foreignId('promotion_id');
            $table->timestampTz('unlocked_at', 6);
            $table->timestampTz('redeemed_at', 6)->nullable();
            $table->foreignId('redeemed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['customer_pass_id', 'promotion_id']);

            // Summary reads all created entitlements, including those already redeemed.
            $table->index(['business_id', 'promotion_id']);

            $table->foreign(['business_id', 'customer_pass_id'], 'reward_entitlements_business_pass_foreign')
                ->references(['business_id', 'id'])->on('customer_passes')->restrictOnDelete();
            $table->foreign(['business_id', 'promotion_id'], 'reward_entitlements_business_promotion_foreign')
                ->references(['business_id', 'id'])->on('promotions')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE reward_entitlements ADD CONSTRAINT reward_entitlements_redemption_pair
            CHECK ((redeemed_at IS NULL) = (redeemed_by_user_id IS NULL))');
    }

    /** Drops only entitlement persistence; rollback discards unlock and redemption history when populated. */
    public function down(): void
    {
        Schema::dropIfExists('reward_entitlements');
    }
};
