<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Presents locations with decorative offsets, never domain validity or stored identity. */
class TimezoneLabel
{
    /**
     * Returns the supported IANA values and labels at one immutable presentation instant.
     *
     * @return array<string, string>
     */
    public static function options(?DateTimeImmutable $instant = null): array
    {
        $instant ??= new DateTimeImmutable('now');
        $options = [];

        foreach (timezone_identifiers_list() as $identifier) {
            $options[$identifier] = self::forZone($identifier, $instant);
        }

        return $options;
    }

    /**
     * Formats a native timezone location and its offset at the supplied instant.
     *
     * @throws \DateInvalidTimeZoneException When the identifier is unknown.
     */
    public static function forZone(string $identifier, DateTimeImmutable $instant): string
    {
        $zone = new DateTimeZone($identifier);
        $offset = $instant->setTimezone($zone)->format('P');
        $parts = explode('/', $identifier);

        if (count($parts) > 1) {
            array_shift($parts);
        }

        $location = str_replace('_', ' ', implode(' / ', $parts));

        return "(UTC{$offset}) {$location}";
    }
}
