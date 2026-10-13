<?php

namespace App\Support;

use App\Data\Time\DatabaseTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Reads one PostgreSQL instant and derives its date in the requested business timezone.
 */
class DatabaseClock
{
    /**
     * Captures one database time reading without relying on the application server clock.
     *
     * Mutating callers must call this after acquiring their required business lock.
     * A read-only time hint does not authorize a later write.
     *
     * @param  string  $timezone  IANA timezone used for the local calendar date.
     * @return DatabaseTime Original database timestamp and local date from that same instant.
     *
     * @throws QueryException If the clock query fails, including an invalid database timezone.
     * @throws PDOException If the database driver fails.
     */
    public function capture(string $timezone): DatabaseTime
    {
        $reading = DB::selectOne(<<<'SQL'
            SELECT operation_clock.instant AS operation_at,
                   (operation_clock.instant AT TIME ZONE ?)::date AS business_date
            FROM (SELECT clock_timestamp() AS instant) AS operation_clock
            SQL, [$timezone]);

        return new DatabaseTime((string) $reading->operation_at, (string) $reading->business_date);
    }

    /**
     * Preserves the previous clock response for callers outside the creation flow.
     *
     * @param  string  $timezone  IANA timezone for the business-local date.
     * @return array{instant: string, business_date: string} Legacy clock response, with one database query.
     *
     * @throws QueryException If the clock query fails.
     * @throws PDOException If the database driver fails.
     */
    public function captureForBusinessTimezone(string $timezone): array
    {
        $time = $this->capture($timezone);

        return ['instant' => $time->instant, 'business_date' => $time->localDate];
    }
}
