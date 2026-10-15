<?php

namespace App\Integrations\GoogleWallet;

use App\Enums\GoogleWalletStage;
use App\Exceptions\CustomerPassCreationException;
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
 * Builds operation-local authenticated SDK resources without exposing provider credentials or payloads.
 *
 * @phpstan-import-type WalletConfiguration from GoogleWalletConfiguration
 */
class GoogleWalletClient
{
    /**
     * Accepts a native test transport while retaining production authentication and request policy.
     *
     * @param  HttpClient|null  $http  Optional native handler used by isolated tests.
     */
    public function __construct(#[SensitiveParameter] private ?HttpClient $http = null) {}

    /**
     * Authenticates before object insertion and disables automatic retries and redirects.
     *
     * @param  WalletConfiguration  $settings  Selected service-account fields and request limits.
     * @param  int|null  $deadlineNs  Monotonic deadline shared by every request, or null for issuance limits.
     * @return Walletobjects Native SDK resources with an operation-local access token.
     *
     * @throws CustomerPassCreationException If OAuth cannot complete safely.
     */
    public function authenticate(#[SensitiveParameter] array $settings, ?int $deadlineNs = null): Walletobjects
    {
        // bound transport and reject redirects that the SDK would otherwise decode as models
        $handler = ($this->http ?? new HttpClient)->getConfig('handler');
        $stack = $handler instanceof HandlerStack ? clone $handler : HandlerStack::create($handler);
        $stack->push(Middleware::mapResponse(static function (ResponseInterface $response): ResponseInterface {
            $status = $response->getStatusCode();

            if ($status < 200 || ($status >= 300 && $status < 400)) {
                throw new GoogleServiceException('Unexpected provider response.', $status);
            }

            return $response;
        }));
        if ($deadlineNs !== null) {
            $stack->unshift(static function (callable $handler) use ($deadlineNs): callable {
                return static function ($request, array $options) use ($handler, $deadlineNs) {
                    $remaining = ($deadlineNs - hrtime(true)) / 1_000_000_000;

                    if ($remaining <= 0) {
                        throw new RuntimeException('Wallet operation deadline exceeded.');
                    }

                    $options['timeout'] = min($options['timeout'], $remaining);
                    $options['connect_timeout'] = min($options['connect_timeout'], $remaining);

                    return $handler($request, $options);
                };
            });
        }
        $http = new HttpClient([
            'handler' => $stack,
            'connect_timeout' => $settings['connect_timeout'],
            'timeout' => $settings['timeout'],
            'allow_redirects' => false,
            'http_errors' => true,
            'verify' => true,
            'headers' => ['Accept' => 'application/json'],
        ]);

        // keep credentials and payloads out of SDK logs, and pin the public Google API
        $client = new GoogleClient(['retry' => ['retries' => 0], 'universe_domain' => 'googleapis.com']);
        $client->setLogger(new NullLogger);
        $client->setHttpClient($http);
        $client->setAuthConfig($settings['account']);
        $client->addScope(Walletobjects::WALLET_OBJECT_ISSUER);

        // distinguish OAuth failure from an object write that might already have succeeded
        try {
            $accessTokenResponse = $client->fetchAccessTokenWithAssertion($http);
        } catch (Throwable $exception) {
            throw CustomerPassCreationException::fromProvider($exception, GoogleWalletStage::OAuth, 'POST');
        }

        if (! is_string($accessTokenResponse['access_token'] ?? null) || $accessTokenResponse['access_token'] === '') {
            throw CustomerPassCreationException::providerRejected(
                GoogleWalletStage::OAuth,
                'malformed_response',
                ['http_method' => 'POST'],
            );
        }

        $client->useApplicationDefaultCredentials(false);

        return new Walletobjects($client);
    }
}
