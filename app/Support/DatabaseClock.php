<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class DatabaseClock
{
    /**
     * Captures one PostgreSQL wall-clock instant and derive the Business-local calendar date from it.
     *
     * Mutating callers must capture the operation time after acquiring the Business lock.
     * Read-only minimum-date hints may capture without a lock and cannot authorize a write.
     *
     * @param  string  $timezone  Current IANA timezone used to derive the Business-local date.
     * @return array{instant: string, business_date: string} Database instant and its date in the supplied timezone.
     */
    public function captureForBusinessTimezone(string $timezone): array
    {
        $reading = DB::selectOne(<<<'SQL'
            SELECT operation_clock.instant AS operation_at,
                   (operation_clock.instant AT TIME ZONE ?)::date AS business_date
            FROM (SELECT clock_timestamp() AS instant) AS operation_clock
            SQL, [$timezone]);

        return [
            'instant' => (string) $reading->operation_at,
            'business_date' => (string) $reading->business_date,
        ];
    }
}
