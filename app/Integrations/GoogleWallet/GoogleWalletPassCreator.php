<?php

namespace App\Integrations\GoogleWallet;

use App\Enums\CustomerPassCreationFailure;
use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Enums\PassCreationStage;
use App\Exceptions\CustomerPassCreationException;
use App\Models\Business;
use App\Support\CustomerPassCredentials;
use Firebase\JWT\JWT;
use Google\Model;
use Google\Model as GoogleModel;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Walletobjects;
use Google\Service\Walletobjects\ClassTemplateInfo;
use Google\Service\Walletobjects\ListTemplateOverride;
use Google\Service\Walletobjects\LoyaltyClass;
use Google\Service\Walletobjects\LoyaltyObject;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use ReflectionMethod;
use SensitiveParameter;
use Throwable;

/**
 * Owns Google payloads, authentication and confirmation; never writes local pass records.
 *
 * buildObject() maps permanent credentials and a private informational link.
 * buildClass() defines shared Business identity without variable commercial content.
 * create() confirms the provider Object; the calling Action owns local commit and URL release.
 *
 * @phpstan-import-type WalletConfiguration from GoogleWalletConfiguration
 */
class GoogleWalletPassCreator
{
    /**
     * Receives content, configuration and the shared Google SDK client.
     *
     * @param  GoogleWalletConfiguration  $configuration  Loads fresh operation settings and credentials.
     * @param  GoogleWalletClient  $client  Authenticates fresh SDK resources for this operation.
     */
    public function __construct(
        private GoogleWalletConfiguration $configuration,
        #[SensitiveParameter] private GoogleWalletClient $client,
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
    public function create(
        Business $business,
        #[SensitiveParameter] CustomerPassCredentials $credentials,
    ): array {
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
            throw new CustomerPassCreationException(
                CustomerPassCreationFailure::BusinessInvalid,
                stage: PassCreationStage::Provider,
                providerStage: GoogleWalletStage::Configuration,
            );
        }

        // prepare payloads and signing before any external write
        $classId = $settings['issuer_id'].'.business_'.$business->public_id;
        $objectId = $settings['issuer_id'].'.pass_'.$credentials->publicPassId;
        $saveUrl = $this->signSaveUrl($settings, $classId, $objectId);
        $class = $this->buildClass($business, $classId, $settings['logo_url']);
        $object = $this->buildObject($business, $credentials, $classId, $objectId, $settings['origin']);

        // authenticate, reconcile the business class, and confirm exactly one object
        $wallet = $this->client->authenticate($settings);
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
    private function signSaveUrl(
        #[SensitiveParameter] array $settings,
        string $classId,
        string $objectId,
    ): string {
        try {
            $jwt = JWT::encode(
                [
                    'iss' => $settings['account']['client_email'],
                    'aud' => 'google',
                    'typ' => 'savetowallet',
                    'iat' => time(),
                    'origins' => [$settings['origin']],
                    'payload' => [
                        'loyaltyObjects' => [
                            [
                                'id' => $objectId,
                                'classId' => $classId,
                            ],
                        ],
                    ],
                ],
                $settings['account']['private_key'],
                'RS256',
            );
        } catch (Throwable $exception) {
            throw new CustomerPassCreationException(
                CustomerPassCreationFailure::SigningFailed,
                context: ['previous_class' => $exception::class],
                stage: PassCreationStage::Provider,
                providerStage: GoogleWalletStage::Configuration,
            );
        }

        return 'https://pay.google.com/gp/v/save/'.$jwt;
    }

    /**
     * Maps Business appearance and permanent identity to the shared Class template.
     *
     * Clears retired variable presentation slots. Google owns final geometry and truncation.
     *
     * @param  Business  $business  Source of customer-visible issuer identity and background color.
     * @param  string  $classId  Deterministic provider Class ID.
     * @param  string  $logoUrl  Validated native brand asset or deployment override; Google owns the holder.
     * @return LoyaltyClass Owned appearance; provider operations set the review request.
     */
    private function buildClass(Business $business, string $classId, string $logoUrl): LoyaltyClass
    {
        // apply owned appearance without moving credentials into the Class
        $class = new LoyaltyClass([
            'id' => $classId,
            'issuerName' => "\u{200B}",
            'programName' => $business->name,
            'programLogo' => [
                'sourceUri' => ['uri' => $logoUrl],
            ],
            'hexBackgroundColor' => strtoupper($business->pass_background_color),
            'textModulesData' => [[
                'id' => 'personal_invitation',
                'header' => __('wallet.labels.front_heading'),
                'body' => __('wallet.labels.front_message'),
            ]],
            'classTemplateInfo' => [
                'cardTemplateOverride' => ['cardRowTemplateInfos' => [['oneItem' => [
                    'item' => ['firstValue' => ['fields' => [
                        ['fieldPath' => "class.textModulesData['personal_invitation']"],
                    ]]],
                ]]]],
                'detailsTemplateOverride' => ['detailsItemInfos' => [['item' => [
                    'firstValue' => ['fields' => [
                        ['fieldPath' => "object.linksModuleData.uris['personal_information']"],
                    ]],
                ]]]],
                'cardBarcodeSectionDetails' => ['firstTopDetail' => Model::NULL_VALUE, 'firstBottomDetail' => Model::NULL_VALUE],
                'listTemplateOverride' => [
                    'firstRowOption' => [
                        'fieldOption' => [
                            'fields' => [
                                ['fieldPath' => 'class.programName'],
                            ],
                        ],
                    ],
                    'secondRowOption' => [
                        'fields' => [
                            ['fieldPath' => 'object.accountId'],
                        ],
                    ],
                ],
            ],
        ]);

        $barcodeDetails = $class->getClassTemplateInfo()->getCardBarcodeSectionDetails();
        $barcodeDetails['firstTopDetail'] = GoogleModel::NULL_VALUE;
        $barcodeDetails['firstBottomDetail'] = GoogleModel::NULL_VALUE;

        return $class;
    }

    /**
     * Builds initial content and private credentials without updating an existing Object.
     *
     * The prepared UUID scopes the signed informational link before local persistence.
     * The Class selects the display order. Object state ACTIVE does not imply an active Promotion.
     *
     * @param  Business  $business  Promotion owner.
     * @param  CustomerPassCredentials  $credentials  Private barcode and separate manual code.
     * @param  string  $classId  Owning Google class.
     * @param  string  $objectId  Individual Google object.
     * @param  string  $applicationOrigin  Validated deployment origin, including permitted local loopback schemes.
     * @return LoyaltyObject Initial Wallet presentation.
     */
    private function buildObject(
        Business $business,
        #[SensitiveParameter] CustomerPassCredentials $credentials,
        string $classId,
        string $objectId,
        string $applicationOrigin,
    ): LoyaltyObject {
        // sign the complete scoped path and query; the validated origin remains deployment-owned
        $personalPagePath = app('url')->signedRoute('public.pass', [
            'businessPublicId' => $business->public_id,
            'passPublicId' => $credentials->publicPassId,
        ], absolute: false);

        $personalPageUrl = $applicationOrigin.$personalPagePath;
        $buttonLocale = Lang::hasForLocale('wallet.labels.front_button')
            ? app()->getLocale()
            : Lang::getFallback();

        // attach private credentials only at the provider boundary
        return new LoyaltyObject([
            'id' => $objectId,
            'classId' => $classId,
            'state' => 'ACTIVE',
            'barcode' => [
                'type' => 'QR_CODE',
                'value' => $credentials->validationToken,
                'alternateText' => __('wallet.labels.barcode_alternate', ['code' => $credentials->manualCode]),
            ],
            'accountId' => $credentials->manualCode,
            'appLinkData' => [
                'displayText' => ['defaultValue' => [
                    'language' => str_replace('_', '-', $buttonLocale),
                    'value' => __('wallet.labels.front_button'),
                ]],
                'webAppLinkInfo' => ['appTarget' => ['targetUri' => [
                    'uri' => $personalPageUrl,
                    'description' => __('wallet.labels.personal_link'),
                ]]],
            ],
            'linksModuleData' => ['uris' => [[
                'id' => 'personal_information',
                'uri' => $personalPageUrl,
                'description' => __('wallet.labels.personal_link'),
            ]]],
        ]);
    }

    /**
     * Finds or creates the Business Class and reconciles its owned appearance.
     *
     * @param  Walletobjects  $wallet  Authenticated native SDK resources.
     * @param  LoyaltyClass  $expected  Deterministic identity and current appearance.
     *
     * @throws CustomerPassCreationException If discovery, approval or the appearance update fails.
     */
    private function createOrUpdateClass(Walletobjects $wallet, LoyaltyClass $expected): void
    {
        $method = 'GET';
        $context = [];
        $classId = $expected->getId();

        try {
            // find or create the class without repeating a concurrent insertion
            $actual = $this->findOrCreateClass($wallet, $expected, $method, $context);
            $this->requireApprovedClass($actual, $classId, $method);

            if ($this->selectAppearance($actual) === $this->selectAppearance($expected)) {
                return;
            }

            // submit only the owned appearance and require provider approval
            $method = 'PATCH';
            $update = $this->buildAppearanceUpdate($actual, $expected);
            $actual = $wallet->loyaltyclass->patch($classId, $update);
            $this->requireApprovedClass($actual, $classId, $method);
        } catch (CustomerPassCreationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw CustomerPassCreationException::fromException(
                $exception, PassCreationStage::Provider, GoogleWalletStage::ClassDefinition, $method, $context,
            );
        }
    }

    /**
     * Reads the existing Class or inserts it once after a confirmed 404.
     *
     * A concurrent insertion (409) is reconciled by reading the same Class ID.
     * The last HTTP method and safe attempt context remain available if a request fails.
     *
     * @param  Walletobjects  $wallet  Authenticated native SDK resources.
     * @param  LoyaltyClass  $expected  Class to insert if absent.
     * @param  string  $method  Last attempted HTTP method, updated for failure classification.
     * @param  array<string, mixed>  $context  Safe preceding request evidence.
     * @return LoyaltyClass Existing or newly inserted provider Class.
     *
     * @throws GoogleServiceException If a request fails outside the expected 404/409 cases.
     */
    private function findOrCreateClass(
        Walletobjects $wallet,
        LoyaltyClass $expected,
        string &$method,
        array &$context,
    ): LoyaltyClass {
        $classId = $expected->getId();

        try {
            return $wallet->loyaltyclass->get($classId);
        } catch (GoogleServiceException $exception) {
            if ($exception->getCode() !== 404) {
                throw $exception;
            }
        }

        // create once; never treat a conflict as permission to insert again
        $method = 'POST';
        $newClass = clone $expected;
        $newClass->setReviewStatus('UNDER_REVIEW');

        try {
            return $wallet->loyaltyclass->insert($newClass);
        } catch (GoogleServiceException $exception) {
            if ($exception->getCode() !== 409) {
                throw $exception;
            }
        }

        // a concurrent creator won; verify the existing Class instead
        $method = 'GET';
        $context = [
            'provider_attempt' => [
                'http_method' => 'POST',
                'http_status' => 409,
            ],
            'confirmation_read' => true,
        ];

        return $wallet->loyaltyclass->get($classId);
    }

    /**
     * Reconciles owned Class template slots without losing unrelated provider fields.
     *
     * Preserves the existing native null-sentinel writes for localized names.
     *
     * @param  LoyaltyClass  $actual  Provider Class containing other fields to preserve.
     * @param  LoyaltyClass  $expected  Authoritative Business appearance.
     * @return LoyaltyClass Patch payload with owned appearance and review request.
     *
     * @throws \JsonException If provider template data cannot be serialized.
     * @throws \ReflectionException If the native model field setter is unavailable.
     */
    private function buildAppearanceUpdate(LoyaltyClass $actual, LoyaltyClass $expected): LoyaltyClass
    {
        $update = clone $expected;
        $actualData = $actual->toSimpleObject();
        $ownedTemplate = $expected->getClassTemplateInfo();

        // replace owned slots without dropping unrelated template fields
        $actualTemplate = json_decode(
            json_encode(data_get($actualData, 'classTemplateInfo', []), JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $template = new ClassTemplateInfo($actualTemplate);
        $list = new ListTemplateOverride($actualTemplate['listTemplateOverride'] ?? []);
        $list->setFirstRowOption($ownedTemplate->getListTemplateOverride()->getFirstRowOption());
        $list->setSecondRowOption($ownedTemplate->getListTemplateOverride()->getSecondRowOption());
        $template->setListTemplateOverride($list);
        $template->setCardTemplateOverride($ownedTemplate->getCardTemplateOverride());
        $template->setDetailsTemplateOverride($ownedTemplate->getDetailsTemplateOverride());
        $template->setCardBarcodeSectionDetails($ownedTemplate->getCardBarcodeSectionDetails());
        $update->setClassTemplateInfo($template);
        $update->setTextModulesData([
            ...$expected->getTextModulesData(),
            ...array_values(array_filter(
                data_get($actual, 'textModulesData', []),
                static fn ($module) => $module->getId() !== 'personal_invitation',
            )),
        ]);

        // constructors lose typed null sentinels; bypass Collection's extra writes
        $setModelField = new ReflectionMethod(GoogleModel::class, 'offsetSet');
        foreach (['localizedIssuerName', 'localizedProgramName'] as $field) {
            if ((array) data_get($actualData, $field, []) !== []) {
                $setModelField->invoke($update, $field, GoogleModel::NULL_VALUE);
            }
        }

        $update->setReviewStatus('UNDER_REVIEW');

        return $update;
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
            throw new CustomerPassCreationException(
                CustomerPassCreationFailure::ClassNotUsable,
                context: ['http_method' => $method],
                stage: PassCreationStage::Provider,
                providerStage: GoogleWalletStage::ClassDefinition,
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
        $fieldPaths = array_map(
            static fn ($row) => array_map(
                static fn ($field) => data_get($field, 'fieldPath'),
                data_get($row, 'oneItem.item.firstValue.fields', []),
            ),
            $rows,
        );

        $details = data_get($appearance, 'classTemplateInfo.detailsTemplateOverride.detailsItemInfos', []);
        $detailFieldPaths = array_map(
            static fn ($detail) => array_map(
                static fn ($field) => data_get($field, 'fieldPath'),
                data_get($detail, 'item.firstValue.fields', []),
            ),
            $details,
        );

        $barcodeFieldPaths = [];

        foreach (['firstTopDetail', 'firstBottomDetail'] as $detail) {
            $barcodeFieldPaths[$detail] = array_map(
                static fn ($field) => data_get($field, 'fieldPath'),
                data_get($appearance, "classTemplateInfo.cardBarcodeSectionDetails.{$detail}.fieldSelector.fields", []),
            );
        }

        $invitation = null;
        foreach (data_get($appearance, 'textModulesData', []) as $module) {
            if (data_get($module, 'id') === 'personal_invitation') {
                $invitation = [
                    'header' => data_get($module, 'header'),
                    'body' => data_get($module, 'body'),
                    'localizedHeader' => (array) data_get($module, 'localizedHeader', []) !== [],
                    'localizedBody' => (array) data_get($module, 'localizedBody', []) !== [],
                ];
                break;
            }
        }

        return [
            'invitation' => $invitation,
            'issuerName' => $class->getIssuerName(),
            'programName' => $class->getProgramName(),
            'localizedIssuerName' => (array) data_get($appearance, 'localizedIssuerName', []) !== [],
            'localizedProgramName' => (array) data_get($appearance, 'localizedProgramName', []) !== [],
            'listFirstFields' => array_map(
                static fn ($field) => data_get($field, 'fieldPath'),
                data_get($appearance, 'classTemplateInfo.listTemplateOverride.firstRowOption.fieldOption.fields', []),
            ),
            'listSecondFields' => array_map(
                static fn ($field) => data_get($field, 'fieldPath'),
                data_get($appearance, 'classTemplateInfo.listTemplateOverride.secondRowOption.fields', []),
            ),
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
    private function createAndConfirmObject(
        Walletobjects $wallet,
        #[SensitiveParameter] LoyaltyObject $expected,
    ): bool {
        // attempt exactly one insertion; never retry an unknown write with a new identity
        $writeAttempt = ['http_method' => 'POST'];

        try {
            $actual = $wallet->loyaltyobject->insert($expected);
        } catch (Throwable $exception) {
            $failure = CustomerPassCreationException::fromException(
                $exception, PassCreationStage::Provider, GoogleWalletStage::ObjectCreation, 'POST',
            );

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
            throw CustomerPassCreationException::fromException(
                $exception, PassCreationStage::Provider, GoogleWalletStage::ObjectCreation, 'GET', $context,
            );
        }

        if (! $this->matchesObjectBindings($actual, $expected, 'GET', $context)) {
            throw new CustomerPassCreationException(
                CustomerPassCreationFailure::ConfirmationUnavailable,
                context: ['http_method' => 'GET', ...$context],
                stage: PassCreationStage::Provider,
                outcome: FailureOutcome::Unknown,
                providerStage: GoogleWalletStage::ObjectCreation,
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
                throw new CustomerPassCreationException(
                    CustomerPassCreationFailure::BindingConflict,
                    context: [
                        'http_method' => $method,
                        'binding_field' => $field,
                        'binding_category' => is_string($value) ? 'different' : 'invalid',
                        ...$context,
                    ],
                    stage: PassCreationStage::Provider,
                    providerStage: GoogleWalletStage::ObjectCreation,
                );
            }
        }

        return $hasAllBindings;
    }
}
