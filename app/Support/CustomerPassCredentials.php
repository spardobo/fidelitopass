<?php

namespace App\Support;

use App\Models\Business;
use App\Models\CustomerPass;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use LogicException;
use PDOException;
use RuntimeException;
use SensitiveParameter;

/**
 * Retains private current-operation material outside sessions, queues and browser state.
 */
final readonly class CustomerPassCredentials
{
    /**
     * Retains server-generated material for the current issuance operation only.
     *
     * @param  int  $businessId  Persisted Business bound before provider creation.
     * @param  string  $publicId  UUIDv7 reused as this operation's pass identity.
     * @param  string  $validationToken  Private 256-bit opaque scan credential.
     * @param  string  $manualCode  Normalized Business-scoped fallback code.
     */
    private function __construct(
        private int $businessId,
        private string $publicId,
        #[SensitiveParameter] private string $validationToken,
        #[SensitiveParameter] private string $manualCode,
    ) {}

    /**
     * Prepares credentials without writes, checking manual collisions before the provider call.
     *
     * @param  Business  $business  Persisted Business receiving the anonymous pass.
     * @return self Private material to retain and replay only within this operation.
     *
     * @throws LogicException When the Business has not been persisted.
     * @throws RuntimeException When allocation is exhausted or the collision query fails,
     *                          without private SQL context.
     */
    public static function prepare(Business $business): self
    {
        if (! $business->exists) {
            throw new LogicException('Customer pass preparation requires a persisted Business.');
        }

        try {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $manualCode = strtoupper(Str::random(12));

                if (
                    ! CustomerPass::query()
                        ->where('business_id', $business->id)
                        ->where('manual_code', $manualCode)
                        ->exists()
                ) {
                    return new self(
                        $business->id,
                        (string) Str::uuid7(),
                        bin2hex(random_bytes(32)),
                        $manualCode,
                    );
                }
            }
        } catch (QueryException|PDOException) {
            // omits the original failure to protect the private manual-code binding.
            throw new RuntimeException('Customer pass credential preparation failed.');
        }

        throw new RuntimeException('Customer pass credential preparation could not allocate a code.');
    }

    // -----------------------------------------------------------------------------
    // operation bindings and credential access
    // -----------------------------------------------------------------------------

    /**
     * Exposes the owning Business binding for trusted local persistence.
     *
     * @return int Business identity selected before provider orchestration.
     */
    public function businessId(): int
    {
        return $this->businessId;
    }

    /**
     * Exposes the stable identity reused for this issuance operation and its pass.
     *
     * @return string Server-generated UUIDv7; not a private validation credential.
     */
    public function publicId(): string
    {
        return $this->publicId;
    }

    /**
     * Exposes the private scan credential only to trusted payload and handoff callers.
     *
     * @return string Opaque 256-bit token; never persist or place it in logs or public links.
     */
    public function validationToken(): string
    {
        return $this->validationToken;
    }

    /**
     * Derives the storage and lookup verifier without revealing the private scan token.
     *
     * @return string Lowercase hexadecimal SHA-256 verifier of the high-entropy token.
     */
    public function validationTokenHash(): string
    {
        return hash('sha256', $this->validationToken);
    }

    /**
     * Exposes the private operational fallback for the authenticated owning Business.
     *
     * @return string Twelve uppercase alphanumeric characters; not standalone authorization.
     */
    public function manualCode(): string
    {
        return $this->manualCode;
    }

    // -----------------------------------------------------------------------------
    // credential validation and normalization
    // -----------------------------------------------------------------------------

    /**
     * Rejects public identifiers and malformed scan input before querying pass records.
     *
     * @param  string  $token  Untrusted private scan input.
     * @return bool Whether the input has the exact generated token format.
     */
    public static function isValidationToken(#[SensitiveParameter] string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    /**
     * Accepts case-insensitive manual entry with optional spaces and hyphens.
     *
     * @param  string  $code  Untrusted manual entry, bounded before normalization.
     * @return string|null Canonical twelve-character code, or null for invalid input.
     */
    public static function normalizeManualCode(#[SensitiveParameter] string $code): ?string
    {
        if (strlen($code) > 64) {
            return null;
        }

        $normalized = strtoupper(str_replace([' ', '-'], '', $code));

        return preg_match('/^[A-Z0-9]{12}$/D', $normalized) === 1
            ? $normalized
            : null;
    }

    // -----------------------------------------------------------------------------
    // inspection and serialization safeguards
    // -----------------------------------------------------------------------------

    /**
     * Limits ordinary object inspection to non-secret ownership and correlation identities.
     *
     * @return array{business_id: int, operation_id: string} Debug context excluding tokens,
     *                                                       verifiers and manual codes.
     */
    public function __debugInfo(): array
    {
        return [
            'business_id' => $this->businessId,
            'operation_id' => $this->publicId,
        ];
    }

    /**
     * Prevents accidental durable session, queue or cache storage of private operation material.
     *
     * @return array{} No serializable state; this operation always rejects.
     *
     * @throws LogicException Whenever serialization is attempted.
     */
    public function __serialize(): array
    {
        throw new LogicException('Customer pass credentials cannot be serialized.');
    }

    /**
     * Prevents reconstruction of prepared material from serialized input.
     *
     * @param  array<array-key, mixed>  $data  Untrusted serialized state; never accepted.
     *
     * @throws LogicException Whenever deserialization is attempted.
     */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Customer pass credentials cannot be deserialized.');
    }
}
