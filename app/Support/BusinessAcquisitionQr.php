<?php

namespace App\Support;

use App\Models\Business;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Owns the permanent public URL contract and deterministic preparation artwork.
 *
 * Does not perform public issuance.
 */
class BusinessAcquisitionQr
{
    /**
     * Builds the public Business join destination from the configured origin.
     *
     * Uses neither the request host nor client context.
     *
     * @param  Business  $business  Business with an already persisted public identity.
     * @return string Canonical absolute acquisition URL without private credentials.
     *
     * @throws InvalidArgumentException When the origin or public identity is unsuitable.
     */
    public function url(Business $business): string
    {
        $origin = config('app.url');
        $parts = is_string($origin) ? parse_url($origin) : false;

        if (
            ! is_string($origin)
            || ! filter_var($origin, FILTER_VALIDATE_URL)
            || ! is_array($parts)
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || ! Str::isUuid($business->public_id, version: 7)
        ) {
            throw new InvalidArgumentException(
                'A canonical public origin and Business UUIDv7 are required.',
            );
        }

        return rtrim($origin, '/').'/join/'.$business->public_id;
    }

    /**
     * Generates canvas-colored QR artwork on a rounded white backplate.
     *
     * Preserves the four-module quiet zone and does not persist anything.
     *
     * @param  Business  $business  Business whose permanent public join URL is encoded.
     * @return string Complete deterministic SVG document for preview and download.
     *
     * @throws InvalidArgumentException When the origin or public identity is unsuitable.
     * @throws LogicException When the generated artwork is incomplete or cannot be serialized.
     */
    public function svg(Business $business): string
    {
        $fill = Fill::uniformColor(
            new Rgb(255, 255, 255),
            new Rgb(36, 36, 36),
        );

        $writer = new Writer(
            new ImageRenderer(
                new RendererStyle(512, 4, fill: $fill),
                new SvgImageBackEnd,
            ),
        );

        $document = new DOMDocument;
        $document->loadXML(
            $writer->writeString($this->url($business)),
            LIBXML_NONET,
        );

        $root = $document->documentElement;
        $background = $document->getElementsByTagName('rect')->item(0);
        $modules = $document->getElementsByTagName('g')->item(0);

        if (
            ! $root instanceof DOMElement
            || ! $background instanceof DOMElement
            || ! $modules instanceof DOMElement
        ) {
            throw new LogicException('Generated QR artwork is incomplete.');
        }

        // preserves the original 512px square and its quiet zone within the rounded backplate.
        // keeps each original corner inside the curve: hypot(48 - 15, 48 - 15) < 48.
        $root->setAttribute('width', '542');
        $root->setAttribute('height', '542');
        $root->setAttribute('viewBox', '0 0 542 542');

        $background->setAttribute('width', '542');
        $background->setAttribute('height', '542');
        $background->setAttribute('rx', '48');
        $background->setAttribute('ry', '48');

        $inset = $document->createElementNS('http://www.w3.org/2000/svg', 'g');
        $inset->setAttribute('transform', 'translate(15,15)');

        $root->appendChild($inset);
        $inset->appendChild($modules);

        $svg = $document->saveXML();

        if ($svg === false) {
            throw new LogicException('Generated QR artwork could not be serialized.');
        }

        return $svg;
    }
}
