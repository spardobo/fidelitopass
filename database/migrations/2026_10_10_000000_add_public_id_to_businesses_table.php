<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Adds and backfills permanent public identities without changing Business facts or timestamps. */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique();
        });

        DB::table('businesses')->select('id')->chunkById(200, function ($businesses): void {
            foreach ($businesses as $business) {
                DB::table('businesses')->where('id', $business->id)->update(['public_id' => (string) Str::uuid7()]);
            }
        });

        Schema::table('businesses', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable(false)->change();
        });

        // Database guards preserve acquisition identity even when Eloquent is bypassed.
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

    /** Removes acquisition identity; rollback invalidates previously prepared QR material. */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER businesses_public_id_immutable ON businesses; DROP FUNCTION preserve_business_public_id();');
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropColumn('public_id');
        });
    }
};
