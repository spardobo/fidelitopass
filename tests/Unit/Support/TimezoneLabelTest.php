<?php

use App\Support\SupportedTimezones;
use App\Support\TimezoneLabel;

it('offers compact geography without merging IANA values', function (string $locale) {
    $instant = new DateTimeImmutable('2026-07-15T12:00:00Z');

    $options = TimezoneLabel::options($locale, $instant);

    expect($options['America/La_Paz'])->toBe('Bolivia, La Paz (UTC-04:00)');

    $city = (new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'America/Los_Angeles', null, 'VVV'))->format($instant);

    expect($options['America/Los_Angeles'])->toContain($city);
    expect($options['Europe/Madrid'])->not->toBe($options['Europe/Paris']);
    expect(count(array_unique($options)))->toBe(count($options));
    expect(array_diff(SupportedTimezones::identifiers(), array_keys($options)))->toBe([]);
    expect(array_diff(array_keys($options), SupportedTimezones::identifiers()))->toBe([]);
    expect($options)->toHaveKeys(['Asia/Kolkata', 'Asia/Kathmandu', 'UTC']);
})->with(['es', 'en']);

it('keeps compact geography and the localized ICU long UTC name', function () {
    $instant = new DateTimeImmutable('2026-07-15T12:00:00Z');
    $spanish = TimezoneLabel::options('es', $instant);
    $english = TimezoneLabel::options('en', $instant);

    expect($spanish['America/La_Paz'])->toBe('Bolivia, La Paz (UTC-04:00)');
    expect($english['America/La_Paz'])->toBe('Bolivia, La Paz (UTC-04:00)');
    expect($spanish['UTC'])->toBe('Tiempo universal coordinado (UTC+00:00)');
    expect($english['UTC'])->toBe('Coordinated Universal Time (UTC+00:00)');
});

it('shows localized geography and preserves proper names without repeating identical country and city', function () {
    $options = TimezoneLabel::options('es', new DateTimeImmutable('2026-07-15T12:00:00Z'));

    expect($options['America/Caracas'])->toBe('Venezuela, Caracas (UTC-04:00)');
    expect($options['America/Barbados'])->toBe('Barbados (UTC-04:00)');
    expect($options['Europe/Madrid'])->toBe('España, Madrid (UTC+02:00)');
    expect($options['America/New_York'])->toBe('Estados Unidos, Nueva York (UTC-04:00)');

    $identifiers = array_keys($options);
    expect(array_search('America/Barbados', $identifiers))->toBeLessThan(array_search('America/La_Paz', $identifiers));
    expect(array_search('America/La_Paz', $identifiers))->toBeLessThan(array_search('Europe/Madrid', $identifiers));
    expect(array_search('Europe/Madrid', $identifiers))->toBeLessThan(array_search('America/Caracas', $identifiers));
});

it('provides matching compact labels and separate localized names from one presentation map', function () {
    $instant = new DateTimeImmutable('2026-07-15T12:00:00Z');
    $spanish = TimezoneLabel::choices('es', $instant);
    $english = TimezoneLabel::choices('en', $instant);

    expect(array_map(fn ($choice) => $choice['label'], $spanish))->toBe(TimezoneLabel::options('es', $instant));
    expect($spanish['America/La_Paz'])->toBe(['label' => 'Bolivia, La Paz (UTC-04:00)', 'name' => 'Hora de Bolivia']);
    expect($spanish['America/Barbados']['name'])->toBe('Hora estándar del Atlántico');
    expect($spanish['Europe/Madrid']['name'])->toBe('Hora de Europa central');
    expect($english['America/La_Paz']['name'])->toBe('Bolivia Time');
    expect($spanish['UTC']['name'])->toBe('Tiempo universal coordinado');
});

it('uses native offsets at one immutable instant including DST and partial hours', function (string $instant, string $madrid) {
    $reference = new DateTimeImmutable($instant);

    $options = TimezoneLabel::options('es', $reference);

    expect($options['Europe/Madrid'])->toEndWith($madrid);
    expect($options['Asia/Kathmandu'])->toEndWith('(UTC+05:45)');
    expect($options['America/St_Johns'])->toEndWith(str_contains($instant, '-07-') ? '(UTC-02:30)' : '(UTC-03:30)');
    expect($reference->format('Y-m-d\TH:i:s\Z'))->toBe($instant);
})->with([
    'winter' => ['2026-01-15T12:00:00Z', '(UTC+01:00)'],
    'summer' => ['2026-07-15T12:00:00Z', '(UTC+02:00)'],
]);

it('orders by localized native country then representative city rather than offset', function (string $locale) {
    $options = TimezoneLabel::options($locale, new DateTimeImmutable('2026-07-15T12:00:00Z'));
    $expected = array_keys($options);
    $collator = new Collator($locale);
    $metadata = [];

    foreach ($expected as $identifier) {
        $country = (new DateTimeZone($identifier))->getLocation()['country_code'] ?? null;
        $city = (new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, $identifier, null, 'VVV'))->format(1784116800);
        $metadata[$identifier] = [$country ? Locale::getDisplayRegion('und_'.$country, $locale) : '', $city];
    }

    usort($expected, function ($left, $right) use ($collator, $metadata) {
        return ($left === 'UTC') <=> ($right === 'UTC')
            ?: $collator->compare($metadata[$left][0], $metadata[$right][0])
            ?: $collator->compare($metadata[$left][1], $metadata[$right][1])
            ?: strcmp($left, $right);
    });

    expect(array_keys($options))->toBe($expected);
    expect(array_key_last($options))->toBe('UTC');
    expect(array_search('Africa/Ceuta', $expected) < array_search('Europe/Paris', $expected))->toBe($locale === 'es');
})->with(['es', 'en']);

it('retains recognized zones with native offsets and location wording when ICU rules differ', function () {
    $instant = new DateTimeImmutable('2026-07-15T12:00:00Z');
    $options = TimezoneLabel::options('es', $instant);
    $choices = TimezoneLabel::choices('es', $instant);

    foreach (SupportedTimezones::identifiers() as $identifier) {
        $icu = IntlTimeZone::createTimeZone($identifier);
        $icu->getOffset($instant->getTimestamp() * 1000, false, $raw, $dst);
        $native = new DateTimeZone($identifier);

        if ($native->getOffset($instant) * 1000 === $raw + $dst) {
            continue;
        }

        $location = (new IntlDateFormatter('es', IntlDateFormatter::NONE, IntlDateFormatter::NONE, $identifier, null, 'VVVV'))->format($instant);
        $capitalized = mb_strtoupper(mb_substr($location, 0, 1)).mb_substr($location, 1);

        expect($choices[$identifier]['name'])->toBe($capitalized);
        expect($options[$identifier])->toEndWith('(UTC'.$instant->setTimezone($native)->format('P').')');
    }
});
