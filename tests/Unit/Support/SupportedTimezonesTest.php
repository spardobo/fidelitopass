<?php

use App\Support\SupportedTimezones;

it('offers exactly native default identifiers recognized as ICU system zones', function () {
    expect(extension_loaded('intl'))->toBeTrue();

    $expected = [];

    foreach (DateTimeZone::listIdentifiers() as $identifier) {
        $system = false;
        $canonical = IntlTimeZone::getCanonicalID($identifier, $system);

        if ($canonical !== false && $system) {
            $expected[] = $identifier;
        }
    }

    expect(SupportedTimezones::identifiers())->toBe($expected)
        ->toContain('UTC', 'Asia/Kolkata', 'Asia/Kathmandu')
        ->not->toContain('Etc/Unknown', 'US/Eastern', '+02:00', 'Mars/Olympus');
});

it('keeps availability independent of the presentation locale', function () {
    $original = Locale::getDefault();

    try {
        Locale::setDefault('es');
        $spanish = SupportedTimezones::identifiers();
        Locale::setDefault('en');

        expect(SupportedTimezones::identifiers())->toBe($spanish);
    } finally {
        Locale::setDefault($original);
    }
});
