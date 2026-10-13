<?php

namespace App\Integrations\GoogleWallet;

use App\Data\Wallet\WalletTextModule;
use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Enums\PassCreationStage;
use App\Exceptions\CustomerPassCreationException;
use App\Models\Business;
use App\Support\CustomerPassCredentials;
use App\Support\InitialWalletPresentation;
use Carbon\Exceptions\InvalidFormatException;
use Carbon\Exceptions\InvalidTimeZoneException;
use Firebase\JWT\JWT;
use Google\Client as GoogleClient;
use Google\Model as GoogleModel;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Walletobjects;
use Google\Service\Walletobjects\LoyaltyClass;
use Google\Service\Walletobjects\LoyaltyObject;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;
use Throwable;

/**
 * Owns Google payloads, authentication and confirmation; never writes local pass records.
 *
 * @phpstan-import-type WalletConfiguration from GoogleWalletConfiguration
 */
class GoogleWalletPassCreator
{
    /**
     * Receives content, configuration and an optional native transport for isolated tests.
     *
     * @param  InitialWalletPresentation  $presentation  Selects the current promotion without domain writes.
     * @param  GoogleWalletConfiguration  $configuration  Loads fresh operation settings and credentials.
     * @param  HttpClient|null  $http  Tests may replace its handler, not SDK behavior.
     */
    public function __construct(
        private InitialWalletPresentation $presentation,
        private GoogleWalletConfiguration $configuration,
        #[SensitiveParameter] private ?HttpClient $http = null,
    ) {}

    /**
     * Confirms the Google object before returning its private handoff to the action.
     *
     * @param  Business  $business  Persisted owner with a valid UUID and appearance.
     * @param  CustomerPassCredentials  $credentials  One credential set reused throughout issuance.
     * @return array{object_id: string, save_url: string, confirmed_by_readback: bool} Confirmed provider result.
     *
     * @throws CustomerPassCreationException If configuration, authentication or confirmation fails.
     * @throws Throwable If domain presentation cannot be read before provider work.
     */
    public function create(Business $business, #[SensitiveParameter] CustomerPassCredentials $credentials): array
    {
        // validate the owner and load this operation's configuration
        $settings = $this->configuration->load();

        if (
            ! $business->exists
            || $business->id !== $credentials->businessId
            || ! Str::isUuid($business->public_id)
            || trim($business->name) === ''
            || ! is_string($business->pass_background_color)
            || ! preg_match('/^#[0-9a-fA-F]{6}$/D', $business->pass_background_color)
        ) {
            throw CustomerPassCreationException::providerRejected(GoogleWalletStage::Configuration, 'business_invalid');
        }

        // prepare payloads and signing before any external write
        $classId = $settings['issuer_id'].'.business_'.$business->public_id;
        $objectId = $settings['issuer_id'].'.pass_'.$credentials->publicPassId;
        $saveUrl = $this->signSaveUrl($settings, $classId, $objectId);
        $class = $this->buildClass($business, $classId, $settings['logos']['black']);
        $object = $this->buildObject($business, $credentials, $classId, $objectId);

        // authenticate, reconcile the business class, and confirm exactly one object
        $wallet = $this->authenticate($settings);
        $this->createOrUpdateClass($wallet, $class);
        $confirmedByReadback = $this->createAndConfirmObject($wallet, $object);

        return [
            'object_id' => $objectId,
            'save_url' => $saveUrl,
            'confirmed_by_readback' => $confirmedByReadback,
        ];
    }

    /**
     * Signs a reference-only handoff without embedding the private barcode token.
     *
     * @param  WalletConfiguration  $settings  Validated account and application origin.
     * @param  string  $classId  Business class identity.
     * @param  string  $objectId  Individual pass identity.
     * @return string Signed Save-to-Wallet URL; the action releases it only after commit.
     *
     * @throws CustomerPassCreationException If signing fails before provider work.
     */
    private function signSaveUrl(#[SensitiveParameter] array $settings, string $classId, string $objectId): string
    {
        try {
            $jwt = JWT::encode([
                'iss' => $settings['account']['client_email'],
                'aud' => 'google',
                'typ' => 'savetowallet',
                'iat' => time(),
                'origins' => [$settings['origin']],
                'payload' => [
                    'loyaltyObjects' => [[
                        'id' => $objectId,
                        'classId' => $classId,
                    ]],
                ],
            ], $settings['account']['private_key'], 'RS256');
        } catch (Throwable) {
            throw CustomerPassCreationException::providerRejected(GoogleWalletStage::Configuration, 'signing_failed');
        }

        return 'https://pay.google.com/gp/v/save/'.$jwt;
    }

    /**
     * Authenticates before object insertion and disables automatic retries and redirects.
     *
     * @param  WalletConfiguration  $settings  Selected service-account fields and request limits.
     * @return Walletobjects Native SDK resources with an operation-local access token.
     *
     * @throws CustomerPassCreationException If OAuth cannot complete safely.
     */
    private function authenticate(#[SensitiveParameter] array $settings): Walletobjects
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

    /**
     * Maps business appearance to the native class payload.
     *
     * @param  Business  $business  Source of program name and background color.
     * @param  string  $classId  Deterministic provider class ID.
     * @param  string  $logoUrl  Validated black logo for the native white holder.
     * @return LoyaltyClass Owned appearance; provider operations set the review request.
     */
    private function buildClass(Business $business, string $classId, string $logoUrl): LoyaltyClass
    {
        // keep compact content on the card, with safe fallbacks for older objects
        $cardFields = [
            ['fieldPath' => "object.textModulesData['compact_promotion']"],
            ['fieldPath' => "object.textModulesData['reward']"],
            ['fieldPath' => "object.textModulesData['promotion']"],
        ];

        // retain full details without repeating the compact summary
        $details = [];

        foreach ([
            [
                ['fieldPath' => "object.textModulesData['progress']"],
                ['fieldPath' => 'object.loyaltyPoints.balance'],
            ],
            [
                ['fieldPath' => "object.textModulesData['manual_code']"],
                ['fieldPath' => 'object.accountId'],
            ],
            [
                ['fieldPath' => "object.textModulesData['reward']"],
                ['fieldPath' => "object.textModulesData['promotion']"],
            ],
            [['fieldPath' => "object.textModulesData['deadline']"]],
            [['fieldPath' => "object.textModulesData['visit']"]],
        ] as $fields) {
            $details[] = [
                'item' => ['firstValue' => ['fields' => $fields]],
            ];
        }

        // map only the appearance owned by this application
        $class = new LoyaltyClass([
            'id' => $classId,
            'issuerName' => 'FidelitoPass',
            'programName' => $business->name,
            'programLogo' => ['sourceUri' => ['uri' => $logoUrl]],
            'hexBackgroundColor' => strtoupper($business->pass_background_color),
            'classTemplateInfo' => [
                'cardTemplateOverride' => [
                    'cardRowTemplateInfos' => [
                        [
                            'oneItem' => [
                                'item' => ['firstValue' => ['fields' => $cardFields]],
                            ],
                        ],
                    ],
                ],
                'cardBarcodeSectionDetails' => [
                    'firstTopDetail' => [
                        'fieldSelector' => [
                            'fields' => [
                                ['fieldPath' => "object.textModulesData['progress'].body"],
                                ['fieldPath' => 'object.loyaltyPoints.balance'],
                            ],
                        ],
                    ],
                ],
                'detailsTemplateOverride' => ['detailsItemInfos' => $details],
            ],
        ]);

        // omission would retain the former below-barcode field during PATCH
        $class->getClassTemplateInfo()->getCardBarcodeSectionDetails()['firstBottomDetail'] = GoogleModel::NULL_VALUE;

        return $class;
    }

    /**
     * Maps initial promotion content and credentials without changing an existing pass.
     *
     * @param  Business  $business  Promotion owner.
     * @param  CustomerPassCredentials  $credentials  Private barcode and separate manual code.
     * @param  string  $classId  Owning Google class.
     * @param  string  $objectId  Individual Google object.
     * @return LoyaltyObject Initial Wallet presentation.
     *
     * @throws QueryException If the initial presentation database read fails.
     * @throws PDOException If the database driver fails.
     * @throws InvalidFormatException If a stored promotion timestamp cannot be parsed.
     * @throws InvalidTimeZoneException If a stored promotion timezone cannot be used.
     */
    private function buildObject(
        Business $business,
        #[SensitiveParameter] CustomerPassCredentials $credentials,
        string $classId,
        string $objectId,
    ): LoyaltyObject {
        // map initial content without creating visits or participation
        $content = $this->presentation->build($business);
        $sections = array_map(static fn (WalletTextModule $section): array => [
            'id' => $section->id,
            'header' => $section->header,
            'body' => $section->body,
        ], $content->sections);

        // select progress without a caption or a default loyalty-points label
        if ($content->progress !== null) {
            $sections[] = [
                'id' => 'progress',
                'body' => $content->progress,
            ];
        }

        return new LoyaltyObject([
            'id' => $objectId,
            'classId' => $classId,
            'state' => 'ACTIVE',
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $credentials->validationToken,
                'alternateText' => __('Código manual: :code', ['code' => $credentials->manualCode]),
            ],
            'accountId' => $credentials->manualCode,
            'textModulesData' => [
                ...$sections,
                [
                    'id' => 'manual_code',
                    'header' => __('Código manual'),
                    'body' => $credentials->manualCode,
                ],
            ],
        ]);
    }

    /**
     * Finds or creates the business class, then reconciles only its owned appearance.
     *
     * @param  Walletobjects  $wallet  Authenticated native SDK resources.
     * @param  LoyaltyClass  $expected  Deterministic identity and current appearance.
     *
     * @throws CustomerPassCreationException If discovery, approval or the appearance update fails.
     */
    private function createOrUpdateClass(Walletobjects $wallet, LoyaltyClass $expected): void
    {
        // discover the class; only a confirmed 404 permits insertion
        $method = 'GET';
        $context = [];
        $classId = $expected->getId();

        try {
            try {
                $actual = $wallet->loyaltyclass->get($classId);
            } catch (GoogleServiceException $exception) {
                if ($exception->getCode() !== 404) {
                    throw $exception;
                }

                // reconcile a concurrent creator with a read, not a second insertion
                $method = 'POST';
                $newClass = clone $expected;
                $newClass->setReviewStatus('UNDER_REVIEW');

                try {
                    $actual = $wallet->loyaltyclass->insert($newClass);
                } catch (GoogleServiceException $exception) {
                    if ($exception->getCode() !== 409) {
                        throw $exception;
                    }

                    $method = 'GET';
                    $context = [
                        'provider_attempt' => [
                            'http_method' => 'POST',
                            'http_status' => 409,
                        ],
                        'confirmation_read' => true,
                    ];
                    $actual = $wallet->loyaltyclass->get($classId);
                }
            }

            // submit owned appearance for review; only Google can approve the update
            $this->requireApprovedClass($actual, $classId, $method);

            if ($this->selectAppearance($actual) === $this->selectAppearance($expected)) {
                return;
            }

            $method = 'PATCH';
            $update = clone $expected;
            $update->setReviewStatus('UNDER_REVIEW');
            $actual = $wallet->loyaltyclass->patch($classId, $update);
            $this->requireApprovedClass($actual, $classId, $method);
        } catch (CustomerPassCreationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw CustomerPassCreationException::fromProvider($exception, GoogleWalletStage::ClassDefinition, $method, $context);
        }
    }

    /**
     * Checks class identity and approval before object creation.
     *
     * @param  LoyaltyClass  $class  Returned SDK model.
     * @param  string  $classId  Expected class identity.
     * @param  string  $method  Last provider operation.
     *
     * @throws CustomerPassCreationException If the class cannot be used.
     */
    private function requireApprovedClass(LoyaltyClass $class, string $classId, string $method): void
    {
        if (
            $class->getId() !== $classId
            || ! in_array($class->getReviewStatus(), ['APPROVED', 'approved'], true)
        ) {
            throw CustomerPassCreationException::providerRejected(
                GoogleWalletStage::ClassDefinition,
                'class_not_usable',
                ['http_method' => $method],
            );
        }
    }

    /**
     * Selects owned appearance fields while ignoring provider metadata and neutral defaults.
     *
     * @param  LoyaltyClass  $class  Provider or authored class.
     * @return array<string, mixed> Comparable identity-free appearance with ordered field references.
     */
    private function selectAppearance(LoyaltyClass $class): array
    {
        // normalize the provider's equivalent short color notation
        $appearance = $class->toSimpleObject();
        $color = strtoupper($appearance->hexBackgroundColor ?? '');

        if (preg_match('/^#[A-F0-9]{3}$/D', $color)) {
            $color = '#'.$color[1].$color[1].$color[2].$color[2].$color[3].$color[3];
        }

        // compare only fields managed by this integration
        $rows = data_get($appearance, 'classTemplateInfo.cardTemplateOverride.cardRowTemplateInfos', []);
        $fieldPaths = array_map(static fn ($row) => array_map(
            static fn ($field) => data_get($field, 'fieldPath'),
            data_get($row, 'oneItem.item.firstValue.fields', []),
        ), $rows);

        $details = data_get($appearance, 'classTemplateInfo.detailsTemplateOverride.detailsItemInfos', []);
        $detailFieldPaths = array_map(static fn ($detail) => array_map(
            static fn ($field) => data_get($field, 'fieldPath'),
            data_get($detail, 'item.firstValue.fields', []),
        ), $details);

        $barcodeFieldPaths = [];

        foreach (['firstTopDetail', 'firstBottomDetail'] as $detail) {
            $barcodeFieldPaths[$detail] = array_map(
                static fn ($field) => data_get($field, 'fieldPath'),
                data_get($appearance, "classTemplateInfo.cardBarcodeSectionDetails.{$detail}.fieldSelector.fields", []),
            );
        }

        return [
            'issuerName' => $class->getIssuerName(),
            'programName' => $class->getProgramName(),
            'programLogo' => data_get($appearance, 'programLogo.sourceUri.uri'),
            'hexBackgroundColor' => $color,
            'fieldPaths' => $fieldPaths,
            'detailFieldPaths' => $detailFieldPaths,
            'barcodeFieldPaths' => $barcodeFieldPaths,
        ];
    }

    /**
     * Inserts once and resolves an uncertain response with one read of the same identity.
     *
     * @param  Walletobjects  $wallet  Authenticated native resources.
     * @param  LoyaltyObject  $expected  Prepared object including its immutable credential bindings.
     * @return bool Whether confirmation required a GET rather than the insertion response.
     *
     * @throws CustomerPassCreationException If bindings conflict or creation remains unconfirmed.
     */
    private function createAndConfirmObject(Walletobjects $wallet, #[SensitiveParameter] LoyaltyObject $expected): bool
    {
        // attempt exactly one insertion; never retry an unknown write with a new identity
        $writeAttempt = ['http_method' => 'POST'];

        try {
            $actual = $wallet->loyaltyobject->insert($expected);
        } catch (Throwable $exception) {
            $failure = CustomerPassCreationException::fromProvider($exception, GoogleWalletStage::ObjectCreation, 'POST');

            if ($failure->outcome === FailureOutcome::Definitive) {
                throw $failure;
            }

            $actual = null;
            $writeAttempt['http_status'] = $failure->providerStatus;
            $writeAttempt['exception_class'] = $exception::class;
        }

        if ($actual !== null && $this->matchesObjectBindings($actual, $expected, 'POST')) {
            return false;
        }

        // confirm immutable bindings without patching the pass's current progress
        $context = [
            'provider_attempt' => $writeAttempt,
            'confirmation_read' => true,
        ];

        try {
            $actual = $wallet->loyaltyobject->get($expected->getId());
        } catch (Throwable $exception) {
            throw CustomerPassCreationException::fromProvider($exception, GoogleWalletStage::ObjectCreation, 'GET', $context);
        }

        if (! $this->matchesObjectBindings($actual, $expected, 'GET', $context)) {
            throw new CustomerPassCreationException(
                PassCreationStage::Provider,
                FailureOutcome::Unknown,
                'confirmation_unavailable',
                GoogleWalletStage::ObjectCreation,
                diagnostics: [
                    'http_method' => 'GET',
                    ...$context,
                ],
            );
        }

        return true;
    }

    /**
     * Confirms immutable bindings without trusting the generated getters' documented types.
     *
     * @param  LoyaltyObject  $actual  Provider response, possibly partial or malformed.
     * @param  LoyaltyObject  $expected  Authored identity and private credentials.
     * @param  string  $method  Locally selected HTTP operation.
     * @param  array<string, mixed>  $context  Safe preceding request evidence.
     * @return bool Whether every binding was present and matched.
     *
     * @throws CustomerPassCreationException If a present binding differs; values are never logged.
     */
    private function matchesObjectBindings(
        #[SensitiveParameter] LoyaltyObject $actual,
        #[SensitiveParameter] LoyaltyObject $expected,
        string $method,
        array $context = [],
    ): bool {
        // read raw decoded fields so malformed values cannot be coerced into a match
        $objectData = $actual->toSimpleObject();
        $bindings = [
            'id' => [$objectData->id ?? null, $expected->getId()],
            'classId' => [$objectData->classId ?? null, $expected->getClassId()],
            'accountId' => [$objectData->accountId ?? null, $expected->getAccountId()],
            'barcode.type' => [data_get($objectData, 'barcode.type'), 'QR_CODE'],
            'barcode.value' => [data_get($objectData, 'barcode.value'), $expected->getBarcode()->getValue()],
        ];
        $hasAllBindings = true;

        // missing data needs confirmation; conflicting data must stop issuance
        foreach ($bindings as $field => [$value, $expectedValue]) {
            if ($value === null) {
                $hasAllBindings = false;

                continue;
            }

            if ($field === 'barcode.type' && in_array($value, ['qrCode', 'qrcode'], true)) {
                $value = 'QR_CODE';
            }

            if (! is_string($value) || ! hash_equals($expectedValue, $value)) {
                throw CustomerPassCreationException::providerRejected(GoogleWalletStage::ObjectCreation, 'binding_conflict', [
                    'http_method' => $method,
                    'binding_field' => $field,
                    'binding_category' => is_string($value) ? 'different' : 'invalid',
                    ...$context,
                ]);
            }
        }

        return $hasAllBindings;
    }
}
