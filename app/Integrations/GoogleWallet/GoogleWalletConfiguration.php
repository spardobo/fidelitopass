<?php

namespace App\Integrations\GoogleWallet;

use App\Enums\GoogleWalletStage;
use App\Exceptions\CustomerPassCreationException;

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
     * Loads and validates settings for one Google Wallet operation.
     *
     * @return WalletConfiguration Validated operation settings; the account contains private signing material.
     *
     * @throws CustomerPassCreationException If configuration or signing credentials are unusable.
     */
    public function load(): array
    {
        // the auth SDK has an independent stdout logger that can expose private HTTP payloads
        if (strtolower((string) getenv('GOOGLE_SDK_PHP_LOGGING')) === 'true') {
            throw CustomerPassCreationException::providerRejected(GoogleWalletStage::Configuration, 'unsafe_sdk_logging');
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
            throw CustomerPassCreationException::providerRejected(GoogleWalletStage::Configuration, 'configuration_invalid');
        }

        $origin = $this->normalizeOrigin($origin);
        $logoUrls = $this->loadLogoUrls($origin);
        $account = $this->loadServiceAccount();

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
     * @return array{black: string} Private configuration map, not an application result.
     *
     * @throws CustomerPassCreationException If the selected logo URL is invalid.
     */
    private function loadLogoUrls(string $origin): array
    {
        $blackUrl = config('services.google_wallet.logo_black_url');

        if ($blackUrl === null) {
            $fallbackUrl = config('services.google_wallet.logo_url');
            $fallbackUrl = $fallbackUrl === null || $fallbackUrl === '' ? $origin.'/logo.png' : $fallbackUrl;
            $blackUrl = $fallbackUrl;
        }

        if (
            ! is_string($blackUrl)
            || ! $this->hasPublicHttpsFormat($blackUrl)
        ) {
            throw CustomerPassCreationException::providerRejected(GoogleWalletStage::Configuration, 'logo_url_invalid');
        }

        return [
            'black' => $blackUrl,
        ];
    }

    /**
     * Reads the service-account file and validates its RSA signing key.
     *
     * Relative paths are resolved against the application root. The raw file is not cached.
     *
     * @return array{type: string, client_id: string, client_email: string, private_key: string} Selected SDK credentials.
     *
     * @throws CustomerPassCreationException If the file, service account or RSA key is unusable.
     */
    private function loadServiceAccount(): array
    {
        // resolve a readable deployment-owned credential file
        $path = config('services.google_wallet.credentials_path');

        if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
            throw CustomerPassCreationException::providerRejected(GoogleWalletStage::Configuration, 'credentials_invalid');
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        if (! is_file($path) || ! is_readable($path)) {
            throw CustomerPassCreationException::providerRejected(GoogleWalletStage::Configuration, 'credentials_invalid');
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
            throw CustomerPassCreationException::providerRejected(GoogleWalletStage::Configuration, 'credentials_invalid');
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
            throw CustomerPassCreationException::providerRejected(GoogleWalletStage::Configuration, 'signing_key_invalid');
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
     * @param  string  $url  Configured application URL, without credentials, query or fragment.
     * @return string Canonical HTTPS origin, or an allowed loopback origin in local development.
     *
     * @throws CustomerPassCreationException If the configured origin is not allowed.
     */
    private function normalizeOrigin(string $url): string
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
            throw CustomerPassCreationException::providerRejected(GoogleWalletStage::Configuration, 'origin_invalid');
        }

        $defaultPort = $parts['scheme'] === 'https' ? 443 : 80;

        return $parts['scheme'].'://'.strtolower($parts['host'])
            .(isset($parts['port']) && $parts['port'] !== $defaultPort ? ':'.$parts['port'] : '');
    }

    /**
     * Checks the allowed HTTPS URL format without making a network request.
     *
     * @param  string  $url  Deployment-controlled URL to validate.
     * @return bool True for an accepted URL shape; DNS resolution and reachability are not verified.
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
