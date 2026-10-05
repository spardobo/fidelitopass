<?php

namespace App\Support;

use DateTimeZone;
use IntlTimeZone;

/**
 * Defines the IANA time zone identifiers supported by both PHP and ICU.
 *
 * Only identifiers recognized by ICU as system time zones are exposed for
 * selection and server-side validation.
 */
class SupportedTimezones
{
    /**
     * Return the supported IANA time zone identifiers.
     *
     * Identifiers come from PHP's installed time zone database and are retained
     * only when ICU recognizes them as system time zones. If the Intl extension
     * is unavailable, no identifiers are exposed.
     *
     * @return list<string>
     */
    public static function identifiers(): array
    {
        if (! extension_loaded('intl')) {
            return [];
        }

        return array_values(
            array_filter(
                DateTimeZone::listIdentifiers(),
                function (string $identifier): bool {
                    $system = false;

                    IntlTimeZone::getCanonicalID(
                        $identifier,
                        $system,
                    );

                    return $system;
                },
            ),
        );
    }
}
