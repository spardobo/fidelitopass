<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Creates Business ownership, appearance, and permanent public identity with database guards. */
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('timezone');
            $table->string('pass_background_color', 7)->nullable();
            $table->timestampsTz();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE businesses ADD CONSTRAINT businesses_public_id_v7
                CHECK (substring(public_id::text from 15 for 1) = '7'
                    AND substring(public_id::text from 20 for 1) ~ '[89ab]');
            CREATE OR REPLACE FUNCTION preserve_business_public_id() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'A Business public identity cannot be changed.' USING ERRCODE = '23514';
            END;
            $$;
            CREATE TRIGGER businesses_public_id_immutable BEFORE UPDATE OF public_id ON businesses
                FOR EACH ROW WHEN (OLD.public_id IS DISTINCT FROM NEW.public_id)
                EXECUTE FUNCTION preserve_business_public_id();
            SQL);
    }

    /** Drops Business persistence and its identity guard; rollback discards existing Businesses. */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
        DB::statement('DROP FUNCTION preserve_business_public_id()');
    }
};
