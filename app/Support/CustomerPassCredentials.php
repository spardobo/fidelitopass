<?php

namespace App\Support;

use App\Exceptions\CustomerPassCreationException;
use App\Models\Business;
use App\Models\CustomerPass;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use JsonSerializable;
use LogicException;
use PDOException;
use Random\RandomException;
use Random\Randomizer;
use SensitiveParameter;

/**
 * Generates and holds the private credentials for one pass creation attempt.
 *
 * The public ID identifies the pass; the token and manual code identify it privately.
 * This object is not intended for session, queue or browser storage.
 */
final readonly class CustomerPassCredentials implements JsonSerializable
{
    private const MAX_CODE_ATTEMPTS = 10;

    private const MANUAL_CODE_LENGTH = 5;

    private const TOKEN_BYTES = 32;

    /**
     * Stores one generated credential set without additional reads or writes.
     *
     * @param  int  $businessId  Database ID of the owning business.
     * @param  string  $publicPassId  Public UUID reused for this pass and creation attempt.
     * @param  string  $validationToken  Private token placed in the pass QR code.
     * @param  string  $manualCode  Business-scoped fallback code for manual identification.
     */
    private function __construct(
        public int $businessId,
        public string $publicPassId,
        #[SensitiveParameter] public string $validationToken,
        #[SensitiveParameter] public string $manualCode,
    ) {}

    /**
     * Generates a credential set after finding an unused manual code for the business.
     *
     * Checks at most ten candidate codes without reserving or writing a record.
     * Database constraints still protect against concurrent collisions during persistence.
     *
     * @param  Business  $business  Persisted business that will own the pass.
     * @param  Randomizer|null  $randomizer  Optional native random source for isolated tests; production defaults to Secure.
     * @return self Public pass ID, private scan token and manual code for one attempt.
     *
     * @throws CustomerPassCreationException If the business, database read or random allocation prevents preparation.
     */
    public static function prepare(Business $business, ?Randomizer $randomizer = null): self
    {
        // require a persisted owner before allocating credentials
        if (! $business->exists) {
            throw CustomerPassCreationException::preparationFailed('business_not_persisted');
        }

        // check for a free manual code before allocating the pass identity and scan token
        try {
            $randomizer ??= new Randomizer;

            for ($codeAttempt = 0; $codeAttempt < self::MAX_CODE_ATTEMPTS; $codeAttempt++) {
                $manualCode = str_pad((string) $randomizer->getInt(0, 99999), self::MANUAL_CODE_LENGTH, '0', STR_PAD_LEFT);

                if (
                    ! CustomerPass::query()
                        ->where('business_id', $business->id)
                        ->where('manual_code', $manualCode)
                        ->exists()
                ) {
                    return new self(
                        $business->id,
                        (string) Str::uuid7(),
                        bin2hex(random_bytes(self::TOKEN_BYTES)),
                        $manualCode,
                    );
                }
            }
        } catch (QueryException|PDOException $exception) {
            // omit the original exception because query bindings contain the private code
            throw CustomerPassCreationException::preparationFailed('credential_query_failed', [
                'exception_class' => $exception::class,
                'sqlstate' => $exception->errorInfo[0] ?? null,
            ]);
        } catch (RandomException $exception) {
            throw CustomerPassCreationException::preparationFailed('randomness_unavailable', ['exception_class' => $exception::class]);
        }

        throw CustomerPassCreationException::preparationFailed('manual_code_exhausted');
    }

    /**
     * Calculates the token hash used for local storage and lookup.
     *
     * @return string Lowercase SHA-256 hash of the hexadecimal token string, not of its decoded bytes.
     */
    public function hashValidationToken(): string
    {
        return hash('sha256', $this->validationToken);
    }

    /**
     * Exposes only non-secret identifiers during ordinary object inspection.
     *
     * @return array{business_id: int, operation_id: string} Safe debug metadata, without credentials or hashes.
     */
    public function __debugInfo(): array
    {
        return ['business_id' => $this->businessId, 'operation_id' => $this->publicPassId];
    }

    /**
     * Selects public identifiers for ordinary JSON without disclosing credentials.
     *
     * @return array{businessId: int, publicPassId: string} Nonsecret identity metadata only.
     */
    public function jsonSerialize(): array
    {
        return [
            'businessId' => $this->businessId,
            'publicPassId' => $this->publicPassId,
        ];
    }

    /**
     * Rejects storage of the credential set in PHP serialized state.
     *
     * @return array{} No value is returned because serialization is rejected.
     *
     * @throws LogicException Whenever serialization is attempted.
     */
    public function __serialize(): array
    {
        throw new LogicException('Customer pass credentials cannot be serialized.');
    }

    /**
     * Rejects reconstruction of credentials from serialized input.
     *
     * @param  array<array-key, mixed>  $data  Serialized data; never accepted.
     *
     * @throws LogicException Whenever deserialization is attempted.
     */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Customer pass credentials cannot be deserialized.');
    }
}
