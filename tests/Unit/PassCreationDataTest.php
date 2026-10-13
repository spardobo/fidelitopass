<?php

use App\Data\Time\DatabaseTime;
use App\Data\Wallet\WalletPresentation;
use App\Data\Wallet\WalletTextModule;

it('keeps initial content and database readings typed without provider mapping or new state claims', function () {
    $section = new WalletTextModule('promotion', 'Header', 'Body');
    $waiting = new WalletPresentation(null, $section);
    $active = new WalletPresentation('0 / 8 puntos', $section);
    $time = new DatabaseTime('2030-01-01 00:00:00.123456+00', '2029-12-31');

    expect($waiting->progress)->toBeNull();
    expect($active->progress)->toBe('0 / 8 puntos')
        ->and($active->sections)->toBe([$section]);
    expect([$section->id, $section->header, $section->body])->toBe(['promotion', 'Header', 'Body']);
    expect($time->instant)->toBe('2030-01-01 00:00:00.123456+00')
        ->and($time->localDate)->toBe('2029-12-31');
});

it('keeps nonsecret presentation and time values readonly', function () {
    $section = new WalletTextModule(id: 'promotion', header: 'Header', body: 'Body');
    $presentation = new WalletPresentation(progress: null, sections: $section);
    $time = new DatabaseTime(instant: '2030-01-01 00:00:00+00', localDate: '2030-01-01');

    expect(fn () => $section->body = 'Changed')->toThrow(Error::class);
    expect(fn () => $presentation->progress = '1 / 8 puntos')->toThrow(Error::class);
    expect(fn () => $presentation->sections = [])->toThrow(Error::class);
    expect(fn () => $time->instant = '2031-01-01 00:00:00+00')->toThrow(Error::class);
});
