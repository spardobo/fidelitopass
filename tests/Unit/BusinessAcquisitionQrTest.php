<?php

use App\Models\Business;
use App\Support\BusinessAcquisitionQr;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Tests\TestCase;

uses(TestCase::class);

it('uses only the configured origin and permanent public UUID for the future join contract', function () {
    config(['app.url' => 'https://acquire.example.test:8443/']);
    $business = new Business;
    $business->forceFill(['id' => 42, 'public_id' => '01936380-0000-7000-8000-000000000001']);

    expect(app(BusinessAcquisitionQr::class)->url($business))
        ->toBe('https://acquire.example.test:8443/join/01936380-0000-7000-8000-000000000001');
});

it('rejects origins that cannot represent the permanent public destination', function (string $origin) {
    config(['app.url' => $origin]);
    $business = new Business;
    $business->forceFill(['public_id' => '01936380-0000-7000-8000-000000000001']);

    expect(fn () => app(BusinessAcquisitionQr::class)->url($business))->toThrow(InvalidArgumentException::class);
})->with(['', 'javascript:alert(1)', 'https://user:secret@example.test', 'https://example.test/path', 'https://example.test?query=1', 'https://example.test#fragment']);

it('rejects a missing or non-v7 public identity without allocating one', function (?string $identity) {
    config(['app.url' => 'https://acquire.example.test']);
    $business = new Business;
    $business->forceFill(['public_id' => $identity]);

    expect(fn () => app(BusinessAcquisitionQr::class)->svg($business))->toThrow(InvalidArgumentException::class)
        ->and($business->public_id)->toBe($identity);
})->with([null, '01936380-0000-4000-8000-000000000001']);

it('renders deterministic canvas-colored SVG on rounded white with four intact quiet modules for the canonical URL', function () {
    config(['app.url' => 'https://acquire.example.test']);
    $business = new Business;
    $business->forceFill(['public_id' => '01936380-0000-7000-8000-000000000001']);
    $qr = app(BusinessAcquisitionQr::class);
    $svg = $qr->svg($business);
    $fill = Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(36, 36, 36));
    $expected = (new Writer(new ImageRenderer(new RendererStyle(512, 4, fill: $fill), new SvgImageBackEnd)))
        ->writeString('https://acquire.example.test/join/01936380-0000-7000-8000-000000000001');
    $standard = new DOMDocument;
    $standard->loadXML($expected);
    $document = new DOMDocument;
    $document->loadXML($svg);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('svg', 'http://www.w3.org/2000/svg');
    $radius = (float) $document->getElementsByTagName('rect')->item(0)->getAttribute('rx');
    $exterior = $xpath->query('/svg:svg/svg:g')->item(0)->getAttribute('transform');
    preg_match('/translate\(([\d.]+),([\d.]+)\)/', $exterior, $padding);

    expect($svg)->toBe($qr->svg($business))
        ->and($document->getElementsByTagName('path')->item(0)->getAttribute('d'))
        ->toBe($standard->getElementsByTagName('path')->item(0)->getAttribute('d'))
        ->and($document->documentElement->getAttribute('viewBox'))->toBe('0 0 542 542')
        ->and($xpath->query('//svg:rect[@fill="#ffffff"][@width="542"][@height="542"][@rx="48"][@ry="48"]')->length)->toBe(1)
        ->and($xpath->query('//svg:path[@fill="#242424"]')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//svg:path[@fill!="#242424"] | //@opacity | //@fill-opacity | //svg:linearGradient | //svg:radialGradient')->length)->toBe(0)
        ->and($xpath->query('//svg:g[@transform="translate(4,4)"]')->length)->toBe(1)
        ->and($xpath->query('/svg:svg/svg:g[@transform="translate(15,15)"]')->length)->toBe(1)
        ->and(hypot($radius - (float) $padding[1], $radius - (float) $padding[2]))->toBeLessThan($radius)
        ->and($document->getElementsByTagName('path')->length)->toBe($standard->getElementsByTagName('path')->length)
        ->and($document->getElementsByTagName('g')->item(1)->getAttribute('transform'))
        ->toBe($standard->getElementsByTagName('g')->item(0)->getAttribute('transform'))
        ->and($xpath->query('//svg:script | //svg:image | //svg:a')->length)->toBe(0);
});
