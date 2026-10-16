<?php

namespace App\Integrations\GoogleWallet;

use App\Enums\CustomerPassCreationFailure;
use App\Enums\GoogleWalletStage;
use App\Enums\GoogleWalletSynchronizationFailure;
use App\Enums\PassCreationStage;
use App\Exceptions\CustomerPassCreationException;
use App\Exceptions\GoogleWalletSynchronizationException;

/**
 * Loads deployment configuration and signing credentials for one Google Wallet operation.
 *
 * @phpstan-type WalletConfiguration array{
 *     issuer_id: string,
 *     account: array{type: string, client_id: string, client_email: string, private_key: string},
 *     origin: string,
 *     logos: array{black: string},
 *     connect_timeout: int,
 *     timeout: int
 * }
 */
class GoogleWalletConfiguration
{
    /**
     * Loads and validates settings without caching credentials or changing invocation ownership.
     *
     * @param  bool  $synchronizing  Whether this invocation belongs to existing-pass synchronization.
     * @return WalletConfiguration Validated settings containing private signing material.
     *
     * @throws CustomerPassCreationException If issuance settings or credentials are unusable.
     * @throws GoogleWalletSynchronizationException If synchronization settings or credentials are unusable.
     */
    public function load(bool $synchronizing = false): array
    {
        // the auth SDK has an independent stdout logger that can expose private HTTP payloads
        if (strtolower((string) getenv('GOOGLE_SDK_PHP_LOGGING')) === 'true') {
            throw $synchronizing
                ? new GoogleWalletSynchronizationException(
                    GoogleWalletSynchronizationFailure::UnsafeSdkLogging,
                    stage: GoogleWalletStage::Configuration,
                )
                : new CustomerPassCreationException(
                    CustomerPassCreationFailure::UnsafeSdkLogging,
                    stage: PassCreationStage::Provider,
                    providerStage: GoogleWalletStage::Configuration,
                );
        }

        $issuerId = config('services.google_wallet.issuer_id');
        $origin = config('app.url');
        $connectTimeout = config('services.google_wallet.connect_timeout');
        $timeout = config('services.google_wallet.timeout');

        if (
            ! is_string($issuerId)
            || ! preg_match('/^[0-9]{1,30}$/D', $issuerId)
            || ! is_string($origin)
            || ! is_int($connectTimeout)
            || $connectTimeout < 1
            || $connectTimeout > 30
            || ! is_int($timeout)
            || $timeout < $connectTimeout
            || $timeout > 60
        ) {
            throw $synchronizing
                ? new GoogleWalletSynchronizationException(
                    GoogleWalletSynchronizationFailure::ConfigurationInvalid,
                    stage: GoogleWalletStage::Configuration,
                )
                : new CustomerPassCreationException(
                    CustomerPassCreationFailure::ConfigurationInvalid,
                    stage: PassCreationStage::Provider,
                    providerStage: GoogleWalletStage::Configuration,
                );
        }

        $origin = $this->normalizeOrigin($origin, $synchronizing);
        $logoUrls = $this->loadLogoUrls($origin, $synchronizing);
        $account = $this->loadServiceAccount($synchronizing);

        return [
            'issuer_id' => $issuerId,
            'account' => $account,
            'origin' => $origin,
            'logos' => $logoUrls,
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
        ];
    }

    /**
     * Loads the selected black logo URL, preserving the original single-logo fallback.
     *
     * @param  string  $origin  Validated application origin used for the default logo path.
     * @param  bool  $synchronizing  Invocation-local exception ownership.
     * @return array{black: string} Private configuration map.
     *
     * @throws CustomerPassCreationException If the selected issuance logo URL is invalid.
     * @throws GoogleWalletSynchronizationException If the selected synchronization logo URL is invalid.
     */
    private function loadLogoUrls(string $origin, bool $synchronizing): array
    {
        $blackUrl = config('services.google_wallet.logo_black_url');
        if ($blackUrl === null) {
            $fallbackUrl = config('services.google_wallet.logo_url');
            $fallbackUrl = $fallbackUrl === null || $fallbackUrl === '' ? $origin.'/logo.png' : $fallbackUrl;
            $blackUrl = $fallbackUrl;
        }

        if (! is_string($blackUrl) || ! $this->hasPublicHttpsFormat($blackUrl)) {
            throw $synchronizing
                ? new GoogleWalletSynchronizationException(
                    GoogleWalletSynchronizationFailure::LogoUrlInvalid,
                    stage: GoogleWalletStage::Configuration,
                )
                : new CustomerPassCreationException(
                    CustomerPassCreationFailure::LogoUrlInvalid,
                    stage: PassCreationStage::Provider,
                    providerStage: GoogleWalletStage::Configuration,
                );
        }

        return ['black' => $blackUrl];
    }

    /**
     * Reads fresh service-account credentials and validates their RSA signing key.
     *
     * @param  bool  $synchronizing  Invocation-local exception ownership.
     * @return array{type: string, client_id: string, client_email: string, private_key: string} Selected SDK credentials.
     *
     * @throws CustomerPassCreationException If the issuance credentials are unusable.
     * @throws GoogleWalletSynchronizationException If the synchronization credentials are unusable.
     */
    private function loadServiceAccount(bool $synchronizing): array
    {
        // resolve a readable deployment-owned credential file
        $path = config('services.google_wallet.credentials_path');
        if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
            throw $synchronizing
                ? new GoogleWalletSynchronizationException(
                    GoogleWalletSynchronizationFailure::CredentialsInvalid,
                    stage: GoogleWalletStage::Configuration,
                )
                : new CustomerPassCreationException(
                    CustomerPassCreationFailure::CredentialsInvalid,
                    stage: PassCreationStage::Provider,
                    providerStage: GoogleWalletStage::Configuration,
                );
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);
        if (! is_file($path) || ! is_readable($path)) {
            throw $synchronizing
                ? new GoogleWalletSynchronizationException(
                    GoogleWalletSynchronizationFailure::CredentialsInvalid,
                    stage: GoogleWalletStage::Configuration,
                )
                : new CustomerPassCreationException(
                    CustomerPassCreationFailure::CredentialsInvalid,
                    stage: PassCreationStage::Provider,
                    providerStage: GoogleWalletStage::Configuration,
                );
        }

        // read and validate the external account fields before loading the key
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
            throw $synchronizing
                ? new GoogleWalletSynchronizationException(
                    GoogleWalletSynchronizationFailure::CredentialsInvalid,
                    stage: GoogleWalletStage::Configuration,
                )
                : new CustomerPassCreationException(
                    CustomerPassCreationFailure::CredentialsInvalid,
                    stage: PassCreationStage::Provider,
                    providerStage: GoogleWalletStage::Configuration,
                );
        }

        // require an RSA key of at least 2048 bits
        $privateKey = @openssl_pkey_get_private($account['private_key']);
        $keyDetails = $privateKey === false ? false : openssl_pkey_get_details($privateKey);
        if (
            $privateKey === false
            || $keyDetails === false
            || $keyDetails['type'] !== OPENSSL_KEYTYPE_RSA
            || $keyDetails['bits'] < 2048
        ) {
            throw $synchronizing
                ? new GoogleWalletSynchronizationException(
                    GoogleWalletSynchronizationFailure::SigningKeyInvalid,
                    stage: GoogleWalletStage::Configuration,
                )
                : new CustomerPassCreationException(
                    CustomerPassCreationFailure::SigningKeyInvalid,
                    stage: PassCreationStage::Provider,
                    providerStage: GoogleWalletStage::Configuration,
                );
        }

        return [
            'type' => 'service_account',
            'client_id' => is_string($account['client_id'] ?? null) ? $account['client_id'] : '',
            'client_email' => $account['client_email'],
            'private_key' => $account['private_key'],
        ];
    }

    /**
     * Validates the application origin and removes its default port.
     *
     * @param  string  $url  Configured application URL without credentials, query or fragment.
     * @param  bool  $synchronizing  Invocation-local exception ownership.
     * @return string Canonical HTTPS origin, or allowed loopback origin in local development.
     *
     * @throws CustomerPassCreationException If the configured issuance origin is not allowed.
     * @throws GoogleWalletSynchronizationException If the configured synchronization origin is not allowed.
     */
    private function normalizeOrigin(string $url, bool $synchronizing): string
    {
        $parts = parse_url($url);
        $isLocalLoopback = app()->environment('local')
            && is_array($parts)
            && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
            && in_array($parts['scheme'] ?? '', ['http', 'https'], true);

        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || (! $this->hasPublicHttpsFormat($url) && ! $isLocalLoopback)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] < 1)
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))
        ) {
            throw $synchronizing
                ? new GoogleWalletSynchronizationException(
                    GoogleWalletSynchronizationFailure::OriginInvalid,
                    stage: GoogleWalletStage::Configuration,
                )
                : new CustomerPassCreationException(
                    CustomerPassCreationFailure::OriginInvalid,
                    stage: PassCreationStage::Provider,
                    providerStage: GoogleWalletStage::Configuration,
                );
        }

        $defaultPort = $parts['scheme'] === 'https' ? 443 : 80;

        return $parts['scheme'].'://'.strtolower($parts['host'])
            .(isset($parts['port']) && $parts['port'] !== $defaultPort ? ':'.$parts['port'] : '');
    }

    /**
     * Checks the allowed HTTPS URL format without making a network request.
     *
     * @param  string  $url  Deployment-controlled URL to validate.
     * @return bool Accepted URL shape; DNS resolution and reachability are not verified.
     */
    private function hasPublicHttpsFormat(string $url): bool
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
}
