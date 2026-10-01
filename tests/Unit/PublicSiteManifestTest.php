<?php

test('public manifest identifies FidelitoPass by both app names', function () {
    $manifest = json_decode(
        file_get_contents(__DIR__.'/../../public/site.webmanifest'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($manifest['name'])->toBe('FidelitoPass');
    expect($manifest['short_name'])->toBe('FidelitoPass');
});

test('public manifest uses the dark brand theme and background', function () {
    $manifest = json_decode(
        file_get_contents(__DIR__.'/../../public/site.webmanifest'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect($manifest['theme_color'])->toBe('#242424');
    expect($manifest['background_color'])->toBe('#242424');
});
