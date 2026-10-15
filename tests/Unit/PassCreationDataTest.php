<?php

use App\Data\Time\DatabaseTime;

it('preserves the database instant precision and local calendar date', function () {
    $time = new DatabaseTime('2030-01-01 00:00:00.123456+00', '2029-12-31');

    expect($time->instant)
        ->toBe('2030-01-01 00:00:00.123456+00');

    expect($time->localDate)
        ->toBe('2029-12-31');
});

it('keeps database time values readonly', function () {
    $time = new DatabaseTime(instant: '2030-01-01 00:00:00+00', localDate: '2030-01-01');

    expect(fn () => $time->instant = '2031-01-01 00:00:00+00')
        ->toThrow(Error::class);
});
