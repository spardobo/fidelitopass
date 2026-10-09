<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Creates anonymous pass identities and their ownership and lookup constraints. */
    public function up(): void
    {
        Schema::create('customer_passes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('wallet_object_id')->nullable()->unique();
            $table->string('validation_token_hash')->nullable()->unique();
            $table->string('manual_code')->nullable();
            $table->timestampsTz();

            $table->unique(['business_id', 'manual_code']);
        });

        // Nullable facts represent unprovisioned passes; empty strings are not identities.
        DB::statement(<<<'SQL'
            ALTER TABLE customer_passes
            ADD CONSTRAINT customer_passes_wallet_object_not_blank CHECK (btrim(wallet_object_id) <> ''),
            ADD CONSTRAINT customer_passes_validation_hash_not_blank CHECK (btrim(validation_token_hash) <> ''),
            ADD CONSTRAINT customer_passes_manual_code_not_blank CHECK (btrim(manual_code) <> '')
            SQL);
    }

    /** Drops pass persistence; rollback is destructive once pass identities exist. */
    public function down(): void
    {
        Schema::dropIfExists('customer_passes');
    }
};
