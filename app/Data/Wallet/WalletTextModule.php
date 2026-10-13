<?php

namespace App\Data\Wallet;

/**
 * One text section displayed on a Wallet pass.
 */
final readonly class WalletTextModule
{
    /**
     * Stores one text section without changing its content.
     *
     * @param  string  $id  Stable section identifier used by the Wallet template.
     * @param  string  $header  Localized section heading.
     * @param  string  $body  Localized section content.
     */
    public function __construct(
        public string $id,
        public string $header,
        public string $body,
    ) {}
}
