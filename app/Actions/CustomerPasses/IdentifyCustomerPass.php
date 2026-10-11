<?php

namespace App\Actions\CustomerPasses;

use App\Models\Business;
use App\Models\CustomerPass;
use App\Support\CustomerPassCredentials;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use PDOException;
use RuntimeException;
use SensitiveParameter;

/**
 * Identifies private passes for the authenticated Business owner without changing loyalty facts.
 *
 * Invalid attempts update the cache-backed limiter and may produce a sanitized warning log.
 */
final class IdentifyCustomerPass
{
    /**
     * Identifies an owned pass through exactly one private credential without changing domain facts.
     *
     * Persisted ownership is checked before credential lookup. Denied, malformed and throttled
     * inputs return null; infrastructure failure remains an exception, not ordinary absence.
     * Invalid owned-scope attempts consume the configured cache-backed limit and log once at
     * its threshold. Successful lookup does not reset that limit. No provider calls occur.
     *
     * @param  Business  $business  Untrusted scope selection, checked against persisted ownership.
     * @param  string|null  $validationToken  Untrusted opaque private scan token, never a public UUID or URL.
     * @param  string|null  $manualCode  Untrusted manual fallback entry, not standalone authorization.
     * @return CustomerPass|null Owned pass, or indistinguishable absence for denied, invalid or throttled lookup.
     *
     * @throws RuntimeException On a database failure in ownership, lookup or the cache-backed limiter,
     *                          without SQL or bindings.
     */
    public function handle(
        Business $business,
        #[SensitiveParameter] ?string $validationToken = null,
        #[SensitiveParameter] ?string $manualCode = null,
    ): ?CustomerPass {
        try {
            $actorId = Auth::id();

            if (
                $actorId === null
                || ! $business->exists
                || ! Business::query()
                    ->whereKey($business->id)
                    ->where('user_id', $actorId)
                    ->exists()
            ) {
                return null;
            }

            $limiterKey = 'customer-pass-lookup:'.$actorId.':'.$business->id;
            $maxAttempts = max(1, (int) config('auth.customer_pass_lookup.max_attempts', 10));

            if (RateLimiter::tooManyAttempts($limiterKey, $maxAttempts)) {
                return null;
            }

            $pass = $this->findPass($business, $validationToken, $manualCode);

            if ($pass === null) {
                $attempts = RateLimiter::hit(
                    $limiterKey,
                    max(1, (int) config('auth.customer_pass_lookup.decay_seconds', 60)),
                );

                if ($attempts === $maxAttempts) {
                    Log::warning('customer_pass.lookup_throttled', [
                        'actor_id' => $actorId,
                        'business_id' => $business->id,
                        'outcome' => 'throttled',
                    ]);
                }
            }

            return $pass;
        } catch (QueryException|PDOException) {
            // omits the original failure because QueryException retains interpolated SQL.
            throw new RuntimeException('Customer pass lookup failed.');
        }
    }

    // -----------------------------------------------------------------------------
    // private credential lookup
    // -----------------------------------------------------------------------------

    /**
     * Finds a pass within the selected Business through exactly one valid private credential.
     *
     * Normalizes any supplied manual code before selecting the lookup branch.
     * Returns null when both credentials are supplied, neither is usable, or no pass matches.
     *
     * @param  Business  $business  Business whose persisted ownership has already been checked.
     * @param  string|null  $validationToken  Untrusted private scan token to validate and hash.
     * @param  string|null  $manualCode  Untrusted manual code to normalize before lookup.
     * @return CustomerPass|null Matching pass within the Business, or null for invalid or unmatched credentials.
     *
     * @throws QueryException When the credential lookup query fails.
     * @throws PDOException When the database driver fails.
     */
    private function findPass(
        Business $business,
        #[SensitiveParameter] ?string $validationToken,
        #[SensitiveParameter] ?string $manualCode,
    ): ?CustomerPass {
        $normalizedCode = $manualCode !== null
            ? CustomerPassCredentials::normalizeManualCode($manualCode)
            : null;

        if (
            $validationToken !== null
            && $manualCode === null
            && CustomerPassCredentials::isValidationToken($validationToken)
        ) {
            return $business->customerPasses()
                ->where('validation_token_hash', hash('sha256', $validationToken))
                ->first();
        }

        if ($validationToken === null && $normalizedCode !== null) {
            return $business->customerPasses()
                ->where('manual_code', $normalizedCode)
                ->first();
        }

        return null;
    }
}
