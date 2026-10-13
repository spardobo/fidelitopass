<?php

namespace App\Data\Time;

/**
 * One database instant and its calendar date in the requested timezone.
 */
final readonly class DatabaseTime
{
    /**
     * Stores the database instant and the local date derived from it.
     *
     * @param  string  $instant  Original PostgreSQL timestamp text, including its precision and offset.
     * @param  string  $localDate  Calendar date in the requested timezone, formatted as YYYY-MM-DD.
     */
    public function __construct(
        public string $instant,
        public string $localDate,
    ) {}
}
