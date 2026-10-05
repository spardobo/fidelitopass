<?php

use App\Support\TimezoneLabel;

it('shows location hierarchy and the offset at the reference instant', function (string $zone, string $instant, string $label) {
    expect(TimezoneLabel::forZone($zone, new DateTimeImmutable($instant)))->toBe($label);
})->with([
    'negative offset' => ['America/Montevideo', '2026-01-15T12:00:00Z', '(UTC-03:00) Montevideo'],
    'winter' => ['Europe/Madrid', '2026-01-15T12:00:00Z', '(UTC+01:00) Madrid'],
    'summer' => ['Europe/Madrid', '2026-07-15T12:00:00Z', '(UTC+02:00) Madrid'],
    'quarter hour' => ['Asia/Kathmandu', '2026-01-15T12:00:00Z', '(UTC+05:45) Kathmandu'],
    'negative half hour' => ['America/St_Johns', '2026-01-15T12:00:00Z', '(UTC-03:30) St Johns'],
    'hierarchy' => ['America/Argentina/Buenos_Aires', '2026-01-15T12:00:00Z', '(UTC-03:00) Argentina / Buenos Aires'],
    'UTC' => ['UTC', '2026-01-15T12:00:00Z', '(UTC+00:00) UTC'],
]);

it('keeps the complete supported IANA values with one reference instant', function () {
    $instant = new DateTimeImmutable('2026-07-15T12:00:00Z');

    $options = TimezoneLabel::options($instant);

    expect(array_keys($options))->toBe(timezone_identifiers_list());
    expect($options['Europe/Madrid'])->toBe('(UTC+02:00) Madrid');
    expect($options['UTC'])->toBe('(UTC+00:00) UTC');
    expect($instant->format('c'))->toBe('2026-07-15T12:00:00+00:00');
});

it('does not turn unknown identifiers into selectable fallback zones', function () {
    expect(fn () => TimezoneLabel::forZone('Mars/Olympus', new DateTimeImmutable('2026-01-15T12:00:00Z')))
        ->toThrow(DateInvalidTimeZoneException::class);
});
