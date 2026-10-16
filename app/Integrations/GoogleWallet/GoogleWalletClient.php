<?php

namespace App\Integrations\GoogleWallet;

use App\Enums\CustomerPassCreationFailure;
use App\Enums\GoogleWalletStage;
use App\Enums\GoogleWalletSynchronizationFailure;
use App\Enums\PassCreationStage;
use App\Exceptions\CustomerPassCreationException;
use App\Exceptions\GoogleWalletSynchronizationException;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Walletobjects;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Authenticates operation-local SDK resources with bounded, non-retrying transport.
 *
 * @phpstan-import-type WalletConfiguration from GoogleWalletConfiguration
 */
class GoogleWalletClient
{
    /**
     * Receives an optional native handler without replacing SDK behavior.
     *
     * @param  HttpClient|null  $http  Native transport whose handler can be replaced in tests.
     */
    public function __construct(#[SensitiveParameter] private ?HttpClient $http = null) {}

    /**
     * Authenticates once before returning the native Wallet resources.
     *
     * @param  WalletConfiguration  $settings  Validated service account and per-request limits.
     * @param  int|null  $deadlineNs  Shared monotonic deadline in nanoseconds, or null for issuance limits.
     * @param  bool  $synchronizing  Whether the invocation belongs to the existing-pass flow.
     * @return Walletobjects Native SDK resources with an operation-local access token.
     *
     * @throws CustomerPassCreationException If issuance OAuth fails or returns no usable access token.
     * @throws GoogleWalletSynchronizationException If synchronization OAuth fails or exceeds its deadline.
     */
    public function authenticate(#[SensitiveParameter] array $settings, ?int $deadlineNs = null, bool $synchronizing = false): Walletobjects
    {
        // configure transport and SDK policy before authentication
        $http = $this->createHttpClient($settings, $deadlineNs);
        $client = new GoogleClient([
            'retry' => ['retries' => 0],
            'universe_domain' => 'googleapis.com',
        ]);

        $client->setLogger(new NullLogger);
        $client->setHttpClient($http);
        $client->setAuthConfig($settings['account']);
        $client->addScope(Walletobjects::WALLET_OBJECT_ISSUER);

        // classify OAuth separately from an Object write that may already have succeeded
        try {
            $tokenResponse = $client->fetchAccessTokenWithAssertion($http);
        } catch (Throwable $exception) {
            throw $synchronizing
                ? GoogleWalletSynchronizationException::fromException(
                    $exception, GoogleWalletStage::OAuth,
                    $deadlineNs !== null && hrtime(true) >= $deadlineNs,
                    ['http_method' => 'POST'],
                )
                : CustomerPassCreationException::fromException(
                    $exception, PassCreationStage::Provider, GoogleWalletStage::OAuth, 'POST',
                );
        }

        if (
            ! is_string($tokenResponse['access_token'] ?? null)
            || $tokenResponse['access_token'] === ''
        ) {
            throw $synchronizing
                ? new GoogleWalletSynchronizationException(
                    $deadlineNs !== null && hrtime(true) >= $deadlineNs
                        ? GoogleWalletSynchronizationFailure::DeadlineExceeded
                        : GoogleWalletSynchronizationFailure::MalformedResponse,
                    context: ['http_method' => 'POST'],
                    stage: GoogleWalletStage::OAuth,
                )
                : new CustomerPassCreationException(
                    CustomerPassCreationFailure::MalformedResponse,
                    context: ['http_method' => 'POST'],
                    stage: PassCreationStage::Provider,
                    providerStage: GoogleWalletStage::OAuth,
                );
        }

        // keep this operation on the explicitly authenticated service account
        $client->useApplicationDefaultCredentials(false);

        return new Walletobjects($client);
    }

    /**
     * Builds the native HTTP policy without modifying the supplied handler stack.
     *
     * @param  WalletConfiguration  $settings  Validated connection and request timeouts.
     * @param  int|null  $deadlineNs  Optional deadline shared by OAuth and subsequent Wallet requests.
     * @return HttpClient TLS-verified transport without redirects, bounded by the remaining request budget.
     */
    private function createHttpClient(#[SensitiveParameter] array $settings, ?int $deadlineNs): HttpClient
    {
        // isolate middleware changes to this operation
        $handler = ($this->http ?? new HttpClient)->getConfig('handler');
        $stack = $handler instanceof HandlerStack ? clone $handler : HandlerStack::create($handler);

        // reject responses that the SDK could otherwise decode as successful models
        $stack->push(Middleware::mapResponse(static function (ResponseInterface $response): ResponseInterface {
            $status = $response->getStatusCode();

            if ($status < 200 || ($status >= 300 && $status < 400)) {
                throw new GoogleServiceException('Unexpected provider response.', $status);
            }

            return $response;
        }));

        // cap each request by the same deadline without adding retries
        if ($deadlineNs !== null) {
            $stack->unshift(static function (callable $next) use ($deadlineNs): callable {
                return static function ($request, array $options) use ($next, $deadlineNs) {
                    $remaining = ($deadlineNs - hrtime(true)) / 1_000_000_000;

                    if ($remaining <= 0) {
                        throw new RuntimeException('Wallet operation deadline exceeded.');
                    }

                    $options['timeout'] = min($options['timeout'], $remaining);
                    $options['connect_timeout'] = min($options['connect_timeout'], $remaining);

                    return $next($request, $options);
                };
            });
        }

        // preserve the configured request limits and transport defaults
        return new HttpClient([
            'handler' => $stack,
            'connect_timeout' => $settings['connect_timeout'],
            'timeout' => $settings['timeout'],
            'allow_redirects' => false,
            'http_errors' => true,
            'verify' => true,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }
}
