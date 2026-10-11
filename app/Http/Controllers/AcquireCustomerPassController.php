<?php

namespace App\Http\Controllers;

use App\Actions\CustomerPasses\AcquireCustomerPass;
use App\Models\Business;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use PDOException;

/**
 * Exposes anonymous explicit saving through private, non-cacheable JSON responses.
 */
final class AcquireCustomerPassController extends Controller
{
    /**
     * Resolves the public Business and projects confirmed handoff or sanitized failure.
     *
     * Ignores all body-supplied identities and credentials. The web middleware owns CSRF;
     * framework routing and middleware failures never contain private handoff material.
     *
     * @param  AcquireCustomerPass  $acquire  Google-first acquisition capability.
     * @param  string  $public_id  UUID-constrained public Business identity from the route only.
     * @return JsonResponse Private success or classified failure, never provider or exception details.
     *
     * @throws ModelNotFoundException When the public Business does not exist.
     */
    public function __invoke(AcquireCustomerPass $acquire, string $public_id): JsonResponse
    {
        try {
            $business = Business::query()
                ->where('public_id', $public_id)
                ->firstOrFail();
        } catch (QueryException|PDOException) {
            return response()->json([
                'outcome' => 'definitive',
                'stage' => 'preparation',
                'retry_allowed' => true,
            ], 503, ['Cache-Control' => 'no-store, private']);
        }

        $result = $acquire->handle($business);

        if ($result['outcome'] === 'confirmed') {
            return response()->json([
                'save_url' => $result['save_url'],
                'manual_code' => $result['manual_code'],
            ], 201, ['Cache-Control' => 'no-store, private']);
        }

        $status = $result['stage'] === 'provider' && $result['outcome'] === 'definitive'
            ? 502
            : 503;

        return response()->json($result, $status, ['Cache-Control' => 'no-store, private']);
    }
}
