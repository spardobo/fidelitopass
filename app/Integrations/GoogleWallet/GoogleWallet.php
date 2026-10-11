<?php

namespace App\Integrations\GoogleWallet;

use App\Models\Business;
use App\Support\CustomerPassCredentials;
use App\Support\InitialWalletPresentation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

/**
 * Provides the native Google boundary for initial issuance.
 *
 * Uses one shared layout and a Class per Business without local domain writes.
 * The private handoff is usable only after the caller confirms its own local
 * issuance commit. Provider confirmation is not a customer Wallet save.
 */
class GoogleWallet
{
    private const API = 'https://walletobjects.googleapis.com/walletobjects/v1/';

    private const OAUTH = 'https://oauth2.googleapis.com/token';

    /**
     * Uses the authoritative initial-state resolver rather than caller-supplied presentation.
     *
     * @param  InitialWalletPresentation  $presentation  Read-only new-pass content capability.
     */
    public function __construct(private InitialWalletPresentation $presentation) {}

    // -----------------------------------------------------------------------------
    // public issuance
    // -----------------------------------------------------------------------------

    /**
     * Prevalidates signing, ensures an approved matching Class and confirms one private Object.
     *
     * Requires the same prepared credentials for operation replay. The returned URL must
     * remain private until local issuance commits. An unknown Object outcome must never
     * trigger an automatic retry.
     *
     * @param  Business  $business  Persisted owner with current name, timezone and pass colour.
     * @param  CustomerPassCredentials  $credentials  Ephemeral material prepared for this Business.
     * @return array{object_id: string, save_url: string} Confirmed provider ID and private
     *                                                    existing-Object handoff, not a local commit receipt.
     *
     * @throws WalletIssuanceException For sanitized configuration, provider, binding or unknown issuance failure.
     */
    public function provision(
        Business $business,
        #[SensitiveParameter] CustomerPassCredentials $credentials,
    ): array {
        $settings = $this->settings();

        if (
            ! $business->exists
            || $business->id !== $credentials->businessId()
            || ! Str::isUuid($business->public_id)
            || trim($business->name) === ''
            || ! is_string($business->pass_background_color)
            || ! preg_match('/^#[0-9a-fA-F]{6}$/D', $business->pass_background_color)
        ) {
            throw new WalletIssuanceException(
                'configuration',
                'definitive',
                'business_invalid',
            );
        }

        $classId = $settings['issuer'].'.business_'.$business->public_id;
        $objectId = $settings['issuer'].'.pass_'.$credentials->publicId();
        $issuedAt = time();

        $saveJwt = $this->sign([
            'iss' => $settings['email'],
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => $issuedAt,
            'origins' => [$settings['origin']],
            'payload' => [
                'loyaltyObjects' => [
                    [
                        'id' => $objectId,
                        'classId' => $classId,
                    ],
                ],
            ],
        ], $settings['key']);

        $assertion = $this->sign([
            'iss' => $settings['email'],
            'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
            'aud' => self::OAUTH,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ], $settings['key']);

        $initial = $this->presentation->resolve($business);
        $class = $this->classPayload($business, $classId, $settings['logo']);

        $object = [
            'id' => $objectId,
            'classId' => $classId,
            'state' => 'ACTIVE',
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $credentials->validationToken(),
            ],
            'accountId' => $credentials->manualCode(),
            'textModulesData' => [
                ...$initial['text_modules'],
                [
                    'id' => 'manual_code',
                    'header' => __('Código del Pase'),
                    'body' => $credentials->manualCode(),
                ],
            ],
        ];

        if ($initial['progress'] !== null) {
            $object['loyaltyPoints'] = [
                'label' => __('Puntos'),
                'balance' => [
                    'string' => $initial['progress'],
                ],
            ];
        }

        $token = $this->authenticate($assertion, $settings);

        $this->ensureClass($class, $token, $settings);
        $this->confirmObject($object, $token, $settings);

        return [
            'object_id' => $objectId,
            'save_url' => 'https://pay.google.com/gp/v/save/'.$saveJwt,
        ];
    }

    // -----------------------------------------------------------------------------
    // deployment configuration
    // -----------------------------------------------------------------------------

    /**
     * Validates deployment settings and loads service-account credentials only for this operation.
     *
     * @return array{
     *     issuer: string,
     *     email: string,
     *     key: OpenSSLAsymmetricKey,
     *     origin: string,
     *     logo: string,
     *     connect_timeout: int,
     *     timeout: int
     * } Validated per-operation settings.
     *
     * @throws WalletIssuanceException If configuration or RSA signing material is unusable.
     */
    private function settings(): array
    {
        $issuer = config('services.google_wallet.issuer_id');
        $origin = config('app.url');
        $logo = config('services.google_wallet.logo_url');
        $connect = config('services.google_wallet.connect_timeout');
        $timeout = config('services.google_wallet.timeout');

        if (
            ! is_string($issuer)
            || ! preg_match('/^[0-9]{1,30}$/D', $issuer)
            || ! is_string($origin)
            || ! is_int($connect)
            || $connect < 1
            || $connect > 30
            || ! is_int($timeout)
            || $timeout < $connect
            || $timeout > 60
        ) {
            throw new WalletIssuanceException(
                'configuration',
                'definitive',
                'configuration_invalid',
            );
        }

        $origin = $this->applicationOrigin($origin);
        $logo = $logo === null || $logo === ''
            ? $origin.'/logo.png'
            : $logo;

        if (! is_string($logo) || ! $this->isPublicHttpsUrl($logo)) {
            throw new WalletIssuanceException(
                'configuration',
                'definitive',
                'logo_url_invalid',
            );
        }

        $credentials = $this->serviceAccountCredentials();

        return [
            'issuer' => $issuer,
            'email' => $credentials['email'],
            'key' => $credentials['key'],
            'origin' => $origin,
            'logo' => $logo,
            'connect_timeout' => $connect,
            'timeout' => $timeout,
        ];
    }

    /**
     * Loads external service-account JSON without persisting raw credentials in configuration.
     *
     * Resolves relative paths against the running application root, including Sail.
     *
     * @return array{email: string, key: OpenSSLAsymmetricKey} Validated per-operation signing identity.
     *
     * @throws WalletIssuanceException If the path, account fields or RSA key are unusable.
     */
    private function serviceAccountCredentials(): array
    {
        $path = config('services.google_wallet.credentials_path');

        if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
            throw new WalletIssuanceException(
                'configuration',
                'definitive',
                'credentials_invalid',
            );
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        if (! is_file($path) || ! is_readable($path)) {
            throw new WalletIssuanceException(
                'configuration',
                'definitive',
                'credentials_invalid',
            );
        }

        $json = @file_get_contents($path);
        $account = is_string($json) ? json_decode($json, true) : null;

        if (
            ! is_array($account)
            || ($account['type'] ?? null) !== 'service_account'
            || ! is_string($account['client_email'] ?? null)
            || filter_var($account['client_email'], FILTER_VALIDATE_EMAIL) === false
            || ! preg_match('/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.gserviceaccount\.com$/D', $account['client_email'])
            || ! is_string($account['private_key'] ?? null)
            || $account['private_key'] === ''
        ) {
            throw new WalletIssuanceException(
                'configuration',
                'definitive',
                'credentials_invalid',
            );
        }

        $key = @openssl_pkey_get_private($account['private_key']);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if (
            $key === false
            || $details === false
            || $details['type'] !== OPENSSL_KEYTYPE_RSA
            || $details['bits'] < 2048
        ) {
            throw new WalletIssuanceException(
                'configuration',
                'definitive',
                'signing_key_invalid',
            );
        }

        return [
            'email' => $account['client_email'],
            'key' => $key,
        ];
    }

    /**
     * Allows public HTTPS origins and exact loopback HTTP/HTTPS origins only in local development.
     *
     * @param  string  $url  Configured application URL without credentials or non-origin components.
     * @return string Canonical origin retaining its scheme and nondefault port.
     *
     * @throws WalletIssuanceException If the application URL is not a permitted origin.
     */
    private function applicationOrigin(string $url): string
    {
        $parts = parse_url($url);
        $localLoopback = app()->environment('local')
            && is_array($parts)
            && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true);

        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || (! $this->isPublicHttpsUrl($url) && ! $localLoopback)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] < 1)
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))
        ) {
            throw new WalletIssuanceException(
                'configuration',
                'definitive',
                'origin_invalid',
            );
        }

        $defaultPort = $parts['scheme'] === 'https' ? 443 : 80;

        return $parts['scheme'].'://'.strtolower($parts['host'])
            .(isset($parts['port']) && $parts['port'] !== $defaultPort ? ':'.$parts['port'] : '');
    }

    /**
     * Rejects credential-bearing, local, non-HTTPS and noncanonical deployment URLs.
     *
     * @param  string  $url  Deployment-controlled origin or publicly served platform logo.
     * @return bool Whether the URL is a public HTTPS shape without secret-bearing components.
     */
    private function isPublicHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && isset($parts['host'])
            && str_contains($parts['host'], '.')
            && ! filter_var($parts['host'], FILTER_VALIDATE_IP)
            && ! preg_match('/(?:^|\.)(localhost|local|internal)$/i', $parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    // -----------------------------------------------------------------------------
    // JWT signing and encoding
    // -----------------------------------------------------------------------------

    /**
     * Signs native RS256 assertions without exposing key material in errors or ordinary traces.
     *
     * @param  array<string, mixed>  $claims  Application-owned OAuth or existing-Object claims.
     * @param  OpenSSLAsymmetricKey  $key  Validated external RSA private key.
     * @return string Compact RS256 JWT, private until the owning flow permits handoff.
     *
     * @throws WalletIssuanceException If signing cannot complete.
     */
    private function sign(
        #[SensitiveParameter] array $claims,
        #[SensitiveParameter] OpenSSLAsymmetricKey $key,
    ): string {
        $header = $this->base64Url('{"alg":"RS256","typ":"JWT"}');
        $payload = json_encode($claims, JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new WalletIssuanceException(
                'configuration',
                'definitive',
                'signing_failed',
            );
        }

        $input = $header.'.'.$this->base64Url($payload);

        if (! @openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new WalletIssuanceException(
                'configuration',
                'definitive',
                'signing_failed',
            );
        }

        return $input.'.'.$this->base64Url($signature);
    }

    /**
     * Encodes JWT segments without padding.
     *
     * @param  string  $value  Raw JSON or signature bytes.
     * @return string URL-safe base64 segment.
     */
    private function base64Url(#[SensitiveParameter] string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    // -----------------------------------------------------------------------------
    // provider authentication
    // -----------------------------------------------------------------------------

    /**
     * Exchanges a fresh assertion once without caching tokens or raw credentials.
     *
     * @param  string  $assertion  Private OAuth JWT.
     * @param  array{connect_timeout: int, timeout: int}  $settings  Bounded HTTP timeouts.
     * @return string Validated bearer token held only for this operation.
     *
     * @throws WalletIssuanceException On sanitized authentication failure, before Object creation.
     */
    private function authenticate(
        #[SensitiveParameter] string $assertion,
        #[SensitiveParameter] array $settings,
    ): string {
        $response = $this->request(
            'oauth',
            'POST',
            self::OAUTH,
            [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ],
            null,
            $settings,
        );

        if ($response->status() !== 200) {
            throw new WalletIssuanceException(
                'oauth',
                'definitive',
                'provider_rejected',
            );
        }

        $token = $response->json('access_token');
        $tokenType = $response->json('token_type');

        if (
            ! is_string($token)
            || strlen($token) > 8192
            || ! preg_match('/^[a-zA-Z0-9._~+\/-]+$/D', $token)
            || ! is_string($tokenType)
            || strcasecmp($tokenType, 'Bearer') !== 0
        ) {
            throw new WalletIssuanceException(
                'oauth',
                'definitive',
                'malformed_response',
            );
        }

        return $token;
    }

    // -----------------------------------------------------------------------------
    // Class preparation and confirmation
    // -----------------------------------------------------------------------------

    /**
     * Builds only platform-owned Class fields, leaving unrelated provider configuration untouched.
     *
     * @param  Business  $business  Current program name and background source.
     * @param  string  $classId  Deterministic issuer-bound Class identifier.
     * @param  string  $logo  Validated public platform logo URL.
     * @return array<string, mixed> Shared layout and Business appearance for creation or partial PATCH.
     */
    private function classPayload(Business $business, string $classId, string $logo): array
    {
        $rows = [];

        foreach ([
            "object.textModulesData['promotion']",
            'object.loyaltyPoints.balance',
            "object.textModulesData['reward']",
        ] as $field) {
            $rows[] = [
                'oneItem' => [
                    'item' => [
                        'firstValue' => [
                            'fields' => [
                                ['fieldPath' => $field],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return [
            'id' => $classId,
            'issuerName' => 'FidelitoPass',
            'programName' => $business->name,
            'programLogo' => [
                'sourceUri' => [
                    'uri' => $logo,
                ],
            ],
            'hexBackgroundColor' => strtoupper($business->pass_background_color),
            'classTemplateInfo' => [
                'cardTemplateOverride' => [
                    'cardRowTemplateInfos' => $rows,
                ],
            ],
            'reviewStatus' => 'underReview',
        ];
    }

    /**
     * Ensures approved issuer binding and current owned appearance before any Object POST.
     *
     * Requires readback after a concurrent Class POST conflict. Applies a partial PATCH
     * for stale appearance only during this acquisition, without background synchronization.
     *
     * @param  array<string, mixed>  $expected  Platform-owned Class fields.
     * @param  string  $token  Ephemeral bearer token.
     * @param  array{connect_timeout: int, timeout: int}  $settings  Bounded HTTP timeouts.
     *
     * @throws WalletIssuanceException If Class identity, approval or appearance cannot be confirmed.
     */
    private function ensureClass(
        array $expected,
        #[SensitiveParameter] string $token,
        #[SensitiveParameter] array $settings,
    ): void {
        $url = self::API.'loyaltyClass/'.$expected['id'];
        $response = $this->request('class', 'GET', $url, [], $token, $settings);

        if ($response->status() === 404) {
            $response = $this->request(
                'class',
                'POST',
                self::API.'loyaltyClass',
                $expected,
                $token,
                $settings,
            );

            if ($response->status() === 409) {
                $response = $this->request('class', 'GET', $url, [], $token, $settings);
            }
        }

        $actual = $this->approvedClass($response, $expected['id']);

        if (! $this->classMatches($actual, $expected)) {
            $response = $this->request('class', 'PATCH', $url, $expected, $token, $settings);
            $actual = $this->approvedClass($response, $expected['id']);

            if (! $this->classMatches($actual, $expected)) {
                throw new WalletIssuanceException(
                    'class',
                    'definitive',
                    'appearance_mismatch',
                );
            }
        }
    }

    /**
     * Checks complete approved Class identity without trusting a success status alone.
     *
     * @param  Response  $response  Provider response, never retained on failure.
     * @param  string  $classId  Expected issuer-bound identifier.
     * @return array<string, mixed> Approved Class representation.
     *
     * @throws WalletIssuanceException For unavailable, malformed, foreign or unapproved Class state.
     */
    private function approvedClass(
        #[SensitiveParameter] Response $response,
        string $classId,
    ): array {
        if (! in_array($response->status(), [200, 201], true)) {
            throw new WalletIssuanceException(
                'class',
                'definitive',
                'provider_rejected',
            );
        }

        $class = $response->json();

        if (
            ! is_array($class)
            || ($class['id'] ?? null) !== $classId
            || ($class['reviewStatus'] ?? null) !== 'approved'
        ) {
            throw new WalletIssuanceException(
                'class',
                'definitive',
                'class_not_usable',
            );
        }

        return $class;
    }

    /**
     * Compares owned fields while ignoring unrelated provider metadata.
     *
     * @param  array<string, mixed>  $actual  Approved provider Class.
     * @param  array<string, mixed>  $expected  Current platform-owned fields.
     * @return bool Whether owned appearance and layout match current acquisition terms.
     */
    private function classMatches(array $actual, array $expected): bool
    {
        foreach ([
            'issuerName',
            'programName',
            'programLogo',
            'hexBackgroundColor',
            'classTemplateInfo',
        ] as $field) {
            if (($actual[$field] ?? null) != $expected[$field]) {
                return false;
            }
        }

        return true;
    }

    // -----------------------------------------------------------------------------
    // Object issuance and binding reconciliation
    // -----------------------------------------------------------------------------

    /**
     * Posts once and reconciles uncertain outcomes by the same immutable private binding.
     *
     * Treats missing readback as inconclusive because late processing may still create
     * the Object. Never overwrites replayed Objects with zero progress or new credentials.
     *
     * @param  array<string, mixed>  $expected  Initial Object and private immutable binding.
     * @param  string  $token  Ephemeral bearer token.
     * @param  array{connect_timeout: int, timeout: int}  $settings  Bounded HTTP timeouts.
     *
     * @throws WalletIssuanceException For definitive rejection/conflict or unresolved issuance.
     */
    private function confirmObject(
        #[SensitiveParameter] array $expected,
        #[SensitiveParameter] string $token,
        #[SensitiveParameter] array $settings,
    ): void {
        try {
            $response = $this->request(
                'object',
                'POST',
                self::API.'loyaltyObject',
                $expected,
                $token,
                $settings,
            );

            if (
                in_array($response->status(), [200, 201], true)
                && $this->objectMatches($response, $expected)
            ) {
                return;
            }

            if (
                ! $response->successful()
                && ! in_array($response->status(), [408, 409, 429], true)
                && ! $response->serverError()
            ) {
                throw new WalletIssuanceException(
                    'object',
                    'definitive',
                    'provider_rejected',
                );
            }
        } catch (WalletIssuanceException $exception) {
            if ($exception->outcome !== 'unknown') {
                throw $exception;
            }
        }

        $response = $this->request(
            'object',
            'GET',
            self::API.'loyaltyObject/'.$expected['id'],
            [],
            $token,
            $settings,
        );

        if ($response->status() === 200 && $this->objectMatches($response, $expected)) {
            return;
        }

        throw new WalletIssuanceException(
            'object',
            'unknown',
            'confirmation_unavailable',
        );
    }

    /**
     * Requires every private binding field and leaves incomplete responses unconfirmed.
     *
     * @param  Response  $response  Object representation from POST or reconciliation GET.
     * @param  array<string, mixed>  $expected  Prepared ID, Class, token and manual-code binding.
     * @return bool Whether the complete immutable binding is present and matches.
     *
     * @throws WalletIssuanceException If any present immutable field conflicts with this operation.
     */
    private function objectMatches(
        #[SensitiveParameter] Response $response,
        #[SensitiveParameter] array $expected,
    ): bool {
        $object = $response->json();

        if (! is_array($object)) {
            return false;
        }

        $binding = [
            'id' => $expected['id'],
            'classId' => $expected['classId'],
            'accountId' => $expected['accountId'],
            'barcode.type' => 'QR_CODE',
            'barcode.value' => $expected['barcode']['value'],
        ];

        $complete = true;

        foreach ($binding as $field => $value) {
            $actual = data_get($object, $field);

            if ($actual === null) {
                $complete = false;
            } elseif (! is_string($actual) || ! hash_equals($value, $actual)) {
                throw new WalletIssuanceException(
                    'object',
                    'definitive',
                    'binding_conflict',
                );
            }
        }

        return $complete;
    }

    // -----------------------------------------------------------------------------
    // provider HTTP transport
    // -----------------------------------------------------------------------------

    /**
     * Sends one bounded pinned-host request without redirects, retries or unsafe exception chains.
     *
     * @param  'oauth'|'class'|'object'  $stage  Safe failure classification.
     * @param  string  $method  GET, POST or PATCH selected by this integration.
     * @param  string  $url  Internal pinned Google endpoint.
     * @param  array<string, mixed>  $payload  Private assertion or provider payload.
     * @param  string|null  $token  Private bearer token, absent for OAuth exchange.
     * @param  array{connect_timeout: int, timeout: int}  $settings  Bounded HTTP timeouts.
     * @return Response Unthrown response for explicit status and binding inspection.
     *
     * @throws WalletIssuanceException On sanitized transport failure; Object results stay unknown.
     */
    private function request(
        string $stage,
        string $method,
        string $url,
        #[SensitiveParameter] array $payload,
        #[SensitiveParameter] ?string $token,
        #[SensitiveParameter] array $settings,
    ): Response {
        try {
            $request = Http::acceptJson()
                ->withoutRedirecting()
                ->connectTimeout($settings['connect_timeout'])
                ->timeout($settings['timeout']);

            if ($token === null) {
                $request = $request->asForm();
            } else {
                $request = $request->withToken($token);
            }

            return $request->send($method, $url, [
                $token === null ? 'form_params' : 'json' => $payload,
            ]);
        } catch (ConnectionException|RequestException) {
            throw new WalletIssuanceException(
                $stage,
                $stage === 'object' ? 'unknown' : 'definitive',
                'transport_failed',
            );
        }
    }
}
