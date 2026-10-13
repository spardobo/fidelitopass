<?php

namespace App\Data\Wallet;

/**
 * Contains initial pass content, without customer credentials or persisted progress.
 */
final readonly class WalletPresentation
{
    /** @var list<WalletTextModule> Ordered initial sections. */
    public array $sections;

    /**
     * Creates content for a new pass.
     *
     * @param  string|null  $progress  Formatted initial progress; null when there is no active promotion.
     * @param  WalletTextModule  ...$sections  Ordered text sections included in the pass.
     */
    public function __construct(
        public ?string $progress,
        WalletTextModule ...$sections,
    ) {
        $this->sections = array_values($sections);
    }
}
