<?php

use App\Support\DatabaseClock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reads one PostgreSQL wall clock instant and converts it with the supplied Business timezone', function () {
    $clockQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$clockQueries): void {
        if (str_contains($query->sql, 'clock_timestamp()')) {
            $clockQueries[] = $query;
        }
    });

    $reading = app(DatabaseClock::class)->captureForBusinessTimezone('Pacific/Kiritimati');

    expect($reading['business_date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($reading['instant'])->toMatch('/\+00(:00)?$/')
        ->and($clockQueries)->toHaveCount(1)
        ->and($clockQueries[0]->sql)->toContain('AT TIME ZONE')
        ->and($clockQueries[0]->bindings)->toContain('Pacific/Kiritimati');
});

it('uses PostgreSQL calendar conversion across UTC offsets and a daylight-saving transition', function () {
    $dates = DB::select(<<<'SQL'
        SELECT (instant AT TIME ZONE zone)::date AS local_date
        FROM (VALUES
            ('2026-01-01 01:00:00+00'::timestamptz, 'America/Los_Angeles'::text),
            ('2026-01-01 01:00:00+00'::timestamptz, 'Europe/Madrid'::text),
            ('2026-03-28 23:30:00+00'::timestamptz, 'Europe/Madrid'::text),
            ('2026-03-29 01:00:00+00'::timestamptz, 'Europe/Madrid'::text)
        ) AS business_instants(instant, zone)
        SQL);

    expect(array_map(static fn (object $row): string => (string) $row->local_date, $dates))
        ->toBe(['2025-12-31', '2026-01-01', '2026-03-29', '2026-03-29']);
});
