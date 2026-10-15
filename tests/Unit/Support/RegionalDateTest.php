<?php

use App\Support\RegionalDate;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

it('formats dates using the active application locale', function (string $locale, string $expected): void {
    app()->setLocale($locale);

    expect(RegionalDate::format(CarbonImmutable::parse('2026-05-03', 'UTC')))->toBe($expected);
})->with([
    'Spanish application locale' => ['es', '3/5/2026'],
    'Spanish regional locale' => ['es_ES', '3/5/2026'],
    'English' => ['en', '5/3/2026'],
    'Netherlands' => ['nl_NL', '3-5-2026'],
]);

it('preserves the supplied calendar day and leaves mutable Carbon unchanged', function (): void {
    app()->setLocale('es_ES');

    foreach (['Pacific/Kiritimati', 'Pacific/Honolulu'] as $timezone) {
        $date = Carbon::parse('2026-05-03 00:30:00', $timezone);
        $original = $date->format('c e');

        expect(RegionalDate::format($date))->toBe('3/5/2026');
        expect($date->format('c e'))->toBe($original);
    }

    expect(RegionalDate::format(new DateTimeImmutable('2026-05-03', new DateTimeZone('UTC'))))
        ->toBe('3/5/2026');
    expect(RegionalDate::format(new DateTimeImmutable('0099-01-02', new DateTimeZone('UTC'))))
        ->toBe('2/1/99');
});

it('preserves leap days and early years across locale and date implementations', function (
    string $locale,
    string $value,
    string $timezone,
    string $expected,
): void {
    app()->setLocale($locale);

    foreach ([DateTimeImmutable::class, CarbonImmutable::class, Carbon::class] as $class) {
        $date = new $class($value, new DateTimeZone($timezone));
        $before = serialize($date);

        expect(RegionalDate::format($date))->toBe($expected);
        expect(serialize($date))->toBe($before);
    }
})->with([
    'positive-offset leap day' => ['es', '2028-02-29 00:30:00', 'Pacific/Kiritimati', '29/2/2028'],
    'negative-offset leap day' => ['en', '2028-02-29 23:30:00', 'Pacific/Honolulu', '2/29/2028'],
    'Dutch application locale' => ['nl', '2028-02-29 00:30:00', 'UTC', '29-2-2028'],
    'proleptic calendar day' => ['es', '0099-01-02 12:00:00', 'UTC', '2/1/99'],
]);
