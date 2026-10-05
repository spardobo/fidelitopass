<?php

namespace App\Support;

use Collator;
use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use IntlTimeZone;
use Locale;
use RuntimeException;

/**
 * Provides localized presentation for runtime-supported IANA time zones.
 *
 * Preserves IANA identifiers as stable keys while presenting human-readable
 * names suitable for selection interfaces.
 */
class TimezoneLabel
{
    /**
     * Builds localized display options for the supported IANA time zones.
     *
     * Uses one immutable instant for offset and daylight-saving calculations.
     * Country and city identify each option; offsets remain decorative.
     * Duplicate labels are disambiguated by the original IANA identifier.
     *
     * @param  string  $locale  Locale used to localize time zone, city, and country names.
     * @param  DateTimeImmutable|null  $instant  Instant used to resolve localized names and UTC offsets.
     * @return array<string, string> IANA identifiers mapped to localized display labels.
     *
     * @throws RuntimeException When ICU cannot localize country or time zone metadata.
     * @throws DateInvalidTimeZoneException When PHP cannot construct a native time zone.
     */
    public static function options(
        string $locale,
        ?DateTimeImmutable $instant = null,
    ): array {
        return array_map(
            fn (array $choice): string => $choice['label'],
            self::choices($locale, $instant),
        );
    }

    /**
     * Builds compact options and their separate localized time zone names.
     *
     * Both values share one immutable instant and localization pass. Names retain
     * ICU's generic-location fallback when native and ICU offset rules differ.
     *
     * @return array<string, array{label: string, name: string}> Original IANA identities mapped to presentation values.
     *
     * @throws RuntimeException When ICU cannot localize country or time zone metadata.
     * @throws DateInvalidTimeZoneException When PHP cannot construct a native time zone.
     */
    public static function choices(
        string $locale,
        ?DateTimeImmutable $instant = null,
    ): array {
        $identifiers = SupportedTimezones::identifiers();

        if ($identifiers === []) {
            return [];
        }

        $instant ??= new DateTimeImmutable('now');
        $timestampMilliseconds = $instant->getTimestamp() * 1000;

        $zones = [];

        foreach ($identifiers as $identifier) {
            $native = new DateTimeZone($identifier);
            $icu = IntlTimeZone::createTimeZone($identifier);

            $icu->getOffset(
                $timestampMilliseconds,
                false,
                $raw,
                $dst,
            );

            $pattern = $native->getOffset($instant) * 1000 === $raw + $dst
                ? 'vvvv'
                : 'VVVV';

            $name = $identifier === 'UTC'
                ? $icu->getDisplayName(
                    false,
                    IntlTimeZone::DISPLAY_LONG,
                    $locale,
                )
                : self::format(
                    $identifier,
                    $locale,
                    $instant,
                    $pattern,
                );

            $countryCode = $identifier === 'UTC'
                ? null
                : ($native->getLocation()['country_code'] ?? null);

            $countryName = $countryCode
                ? Locale::getDisplayRegion("und_{$countryCode}", $locale)
                : '';

            if ($countryName === false) {
                throw new RuntimeException(
                    "Unable to localize the country for timezone {$identifier}.",
                );
            }

            $zones[$identifier] = [
                'name' => mb_strtoupper(mb_substr($name, 0, 1))
                    .mb_substr($name, 1),
                'city' => self::format(
                    $identifier,
                    $locale,
                    $instant,
                    'VVV',
                ),
                'country' => $countryName,
                'offset' => $instant
                    ->setTimezone($native)
                    ->format('P'),
            ];
        }

        foreach ($zones as &$zone) {
            $zone['label'] = $zone['name'];

            if ($zone['country'] !== '') {
                $location = $zone['country'];

                if ($zone['city'] !== $zone['country']) {
                    $location .= ', '.$zone['city'];
                }

                $zone['label'] = $location;
            }
        }

        unset($zone);

        $labelCounts = array_count_values(
            array_column($zones, 'label'),
        );

        $collator = new Collator($locale);

        uksort(
            $zones,
            function (string $left, string $right) use ($zones, $collator): int {
                $countryBucketOrder = ($zones[$left]['country'] === '')
                    <=> ($zones[$right]['country'] === '');

                if ($countryBucketOrder) {
                    return $countryBucketOrder;
                }

                $countryOrder = $collator->compare(
                    $zones[$left]['country'],
                    $zones[$right]['country'],
                );

                if ($countryOrder) {
                    return $countryOrder;
                }

                $cityOrder = $collator->compare(
                    $zones[$left]['city'],
                    $zones[$right]['city'],
                );

                return $cityOrder ?: strcmp($left, $right);
            },
        );

        $options = [];

        foreach ($zones as $identifier => $zone) {
            $label = $zone['label'];

            if ($labelCounts[$label] > 1) {
                $label .= ' — '.$identifier;
            }

            $options[$identifier] = [
                'label' => "{$label} (UTC{$zone['offset']})",
                'name' => $zone['name'],
            ];
        }

        return $options;
    }

    /**
     * Formats localized time zone metadata using an ICU pattern.
     *
     * @param  string  $identifier  IANA time zone identifier to format.
     * @param  string  $locale  Locale used to localize the formatted value.
     * @param  DateTimeImmutable  $instant  Instant used to resolve the formatted value.
     * @param  string  $pattern  ICU pattern used to select the time zone representation.
     * @return string Localized time zone value.
     *
     * @throws RuntimeException When ICU cannot format the requested time zone metadata.
     */
    private static function format(
        string $identifier,
        string $locale,
        DateTimeImmutable $instant,
        string $pattern,
    ): string {
        $formatter = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $identifier,
            null,
            $pattern,
        );

        $formatted = $formatter->format($instant);

        if ($formatted === false) {
            throw new RuntimeException(
                "Unable to localize timezone {$identifier}.",
            );
        }

        return $formatted;
    }
}
