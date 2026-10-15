<?php

namespace App\Support;

use DateTimeInterface;
use IntlDateFormatter;
use IntlDatePatternGenerator;
use IntlException;
use IntlGregorianCalendar;

final class RegionalDate
{
    /**
     * Presents a date with the application locale while preserving its supplied timezone and calendar day.
     *
     * @param  DateTimeInterface  $date  Already interpreted date; callers own parsing and business-timezone conversion.
     * @return string Regional numeric calendar date, without time.
     *
     * @throws IntlException When ICU cannot produce the requested presentation.
     */
    public static function format(DateTimeInterface $date): string
    {
        $locale = app()->getLocale();
        $pattern = IntlDatePatternGenerator::create($locale)->getBestPattern('yMd');

        if ($pattern === false) {
            throw new IntlException('Date pattern generation failed.');
        }

        // preserve proleptic Gregorian days, matching PHP dates and browser Intl
        $calendar = new IntlGregorianCalendar($date->getTimezone(), $locale);
        $calendar->setGregorianChange(-8.64e15);
        $formatter = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $date->getTimezone(),
            $calendar,
            $pattern,
        );
        $formatted = $formatter->format($date);

        if ($formatted === false) {
            throw new IntlException('Date formatting failed.');
        }

        return $formatted;
    }
}
