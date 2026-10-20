<?php

namespace App\Actions\Businesses;

use App\Enums\PassAppearanceFailure;
use App\Exceptions\GoogleWalletSynchronizationException;
use App\Exceptions\PassAppearanceException;
use App\Integrations\GoogleWallet\GoogleWalletPassUpdater;
use App\Models\Business;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PDOException;

/** Saves Business pass appearance only after its Google Wallet confirmation, when required. */
final class UpdatePassAppearance
{
    /**
     * Receives the integration that confirms changes to the shared Wallet Class.
     *
     * @param  GoogleWalletPassUpdater  $googleWallet  Updates the issued Business appearance.
     */
    public function __construct(private GoogleWalletPassUpdater $googleWallet) {}

    /**
     * Validates, authorizes and saves the Business color, confirming Wallet before commit.
     *
     * The Business is locked before authorization and mutation. A failed provider update rolls
     * back the local color, but a later database failure cannot roll back a confirmed remote change.
     * Businesses without issued passes do not contact Google Wallet.
     *
     * @param  string|null  $backgroundColor  Untrusted six-digit hexadecimal color.
     * @return Business Persisted Business with its updated appearance.
     *
     * @throws AuthorizationException If the actor is missing, deleted or unauthorized.
     * @throws ModelNotFoundException If the actor no longer owns a Business.
     * @throws ValidationException If the color is invalid.
     * @throws GoogleWalletSynchronizationException If Wallet confirmation fails.
     * @throws PassAppearanceException If local persistence fails or rejects the save.
     */
    public function handle(?string $backgroundColor): Business
    {
        // require an authenticated actor and validate the submitted color
        $actor = Auth::user();

        if ($actor === null) {
            throw new AuthorizationException;
        }

        $color = $this->validateBackgroundColor($backgroundColor);
        $businessId = null;
        $providerConfirmed = false;

        // lock, authorize and update the Business before confirming issued Wallet passes
        try {
            $actor = $actor->fresh() ?? throw new AuthorizationException;

            return DB::transaction(function () use ($actor, $color, &$businessId, &$providerConfirmed): Business {
                $business = $actor->business()->lockForUpdate()->firstOrFail();
                $businessId = $business->id;

                Gate::forUser($actor)->authorize('update', $business);

                $business->pass_background_color = strtoupper($color);

                if (! $business->save()) {
                    throw new PassAppearanceException(PassAppearanceFailure::SaveRejected);
                }

                // confirm shared appearance only when at least one Wallet Object has been issued
                if ($business->customerPasses()->whereNotNull('wallet_object_id')->exists()) {
                    $this->googleWallet->updateAppearance($business);
                    $providerConfirmed = true;
                }

                return $business;
            }, attempts: 1);
        } catch (GoogleWalletSynchronizationException $exception) {
            $exception->log([
                'actor_id' => $actor->id,
                'business_id' => $businessId,
                'stage' => $exception->stage->value,
                'outcome' => $exception->outcome->value,
                'provider_status' => $exception->providerStatus,
            ]);

            throw $exception;
        } catch (QueryException|PDOException $exception) {
            $failure = $this->persistenceFailure($exception, $providerConfirmed);
            $failure->log(['actor_id' => $actor->id, 'business_id' => $businessId]);

            throw $failure;
        } catch (PassAppearanceException $exception) {
            $exception->log(['actor_id' => $actor->id, 'business_id' => $businessId]);

            throw $exception;
        }
    }

    /**
     * Validates the requested color before acquiring a Business lock.
     *
     * @param  string|null  $backgroundColor  Untrusted user input.
     * @return string Validated hexadecimal color, including the leading #.
     *
     * @throws ValidationException If the color is missing or malformed.
     */
    private function validateBackgroundColor(?string $backgroundColor): string
    {
        /** @var array{backgroundColor: string} $validated */
        $validated = Validator::make(
            ['backgroundColor' => $backgroundColor],
            ['backgroundColor' => ['required', 'string', 'regex:/\A#[0-9A-Fa-f]{6}\z/']],
            ['backgroundColor.*' => __('business.pass.invalid_color')],
        )->validate();

        return $validated['backgroundColor'];
    }

    /**
     * Classifies a database failure without retaining SQL, bindings or the native exception.
     *
     * @param  QueryException|PDOException  $exception  Failed database operation.
     * @param  bool  $providerConfirmed  Whether Google confirmed an update before local failure.
     * @return PassAppearanceException Classified failure with safe diagnostic frames.
     */
    private function persistenceFailure(QueryException|PDOException $exception, bool $providerConfirmed): PassAppearanceException
    {
        return new PassAppearanceException(PassAppearanceFailure::PersistenceFailed, context: [
            'previous_class' => $exception::class,
            'previous_file' => $exception->getFile(),
            'previous_line' => $exception->getLine(),
            'previous_frames' => array_map(
                static fn (array $frame): array => Arr::only($frame, ['file', 'line', 'class', 'function']),
                $exception->getTrace(),
            ),
            'provider_confirmed' => $providerConfirmed,
        ]);
    }
}
