<?php

namespace App\Data\Wallet;

/**
 * Carries authoritative content from WalletPresentationBuilder to the Google Wallet adapter.
 *
 * progress is separate so the adapter can label it in details while the card selects only its body.
 * A null progress omits that module entirely.
 * sections are identified by stable roles; their array order is not the rendered card/detail order.
 * The adapter adds credentials to the Object, while its Class template selects and places each role.
 * This container neither selects a domain state nor changes it.
 */
final readonly class WalletPresentation
{
    /** @var list<WalletTextModule> Named content sections; the Class template owns visual placement. */
    public array $sections;

    /**
     * Stores content for initial issuance or an existing pass.
     *
     * @param  string|null  $progress  Formatted authoritative progress; null outside the earning state.
     * @param  WalletTextModule  ...$sections  Text modules kept in supplied order for transport, not display.
     */
    public function __construct(
        public ?string $progress,
        WalletTextModule ...$sections,
    ) {
        $this->sections = array_values($sections);
    }
}
