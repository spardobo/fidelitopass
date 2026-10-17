<?php

namespace App\Http\Controllers;

use App\Actions\CustomerPasses\CreateCustomerPass;
use App\Enums\CustomerPassCreationFailure;
use App\Enums\FailureOutcome;
use App\Enums\PassCreationStage;
use App\Exceptions\CustomerPassCreationException;
use App\Models\Business;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use PDOException;

/** Resolves the business from the route and returns the existing private JSON contract. */
final class CreateCustomerPassController extends Controller
{
    /**
     * Issues a pass without accepting identities or credentials from the request body.
     *
     * @param  CreateCustomerPass  $createPass  Owns provider confirmation and local commit.
     * @param  string  $public_id  Business UUID supplied by the existing route.
     * @return JsonResponse Private save details or the safe failure envelope.
     *
     * @throws ModelNotFoundException If the business is absent; Laravel retains its 404 response.
     */
    public function __invoke(CreateCustomerPass $createPass, string $public_id): JsonResponse
    {
        // resolve the route-owned business before any issuance work
        try {
            $business = Business::query()
                ->where('public_id', $public_id)
                ->firstOrFail();
        } catch (QueryException|PDOException $exception) {
            $failure = new CustomerPassCreationException(CustomerPassCreationFailure::BusinessLookupFailed, context: [
                'previous_class' => $exception::class,
                'exception_class' => $exception::class,
                'sqlstate' => $exception->errorInfo[0] ?? null,
            ]);
            $failure->log(['business_public_id' => $public_id]);

            return $this->failureResponse($failure);
        }

        // missing saved appearance is a public prerequisite, not a provider failure
        if ($business->pass_background_color === null) {
            return response()->json([
                'outcome' => 'definitive',
                'stage' => 'preparation',
                'retry_allowed' => false,
                'code' => 'business_unconfigured',
            ], 409, ['Cache-Control' => 'no-store, private']);
        }

        // deliver only after the action has committed the local pass
        try {
            $result = $createPass->handle($business);

            return response()->json([
                'save_url' => $result['save_url'],
                'manual_code' => $result['manual_code'],
            ], 201, ['Cache-Control' => 'no-store, private']);
        } catch (CustomerPassCreationException $exception) {
            return $this->failureResponse($exception);
        }
    }

    /**
     * Maps safe failure facts to the existing status and retry contract.
     *
     * @param  CustomerPassCreationException  $exception  Sanitized issuance failure.
     * @return JsonResponse Stage and outcome without credentials or provider diagnostic text.
     */
    private function failureResponse(CustomerPassCreationException $exception): JsonResponse
    {
        $status = $exception->stage === PassCreationStage::Provider
            && $exception->outcome === FailureOutcome::Definitive ? 502 : 503;

        return response()->json([
            'outcome' => $exception->outcome->value,
            'stage' => $exception->stage->value,
            'retry_allowed' => $exception->isRetryable(),
        ], $status, ['Cache-Control' => 'no-store, private']);
    }
}
