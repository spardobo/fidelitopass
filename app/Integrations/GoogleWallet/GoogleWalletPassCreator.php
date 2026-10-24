<?php

namespace App\Integrations\GoogleWallet;

use App\Enums\CustomerPassCreationFailure;
use App\Enums\FailureOutcome;
use App\Enums\GoogleWalletStage;
use App\Enums\PassCreationStage;
use App\Exceptions\CustomerPassCreationException;
use App\Models\Business;
use App\Support\CustomerPassCredentials;
use App\Support\WalletPresentationBuilder;
use Carbon\Exceptions\InvalidFormatException;
use Carbon\Exceptions\InvalidTimeZoneException;
use Firebase\JWT\JWT;
use Google\Model as GoogleModel;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Walletobjects;
use Google\Service\Walletobjects\ClassTemplateInfo;
use Google\Service\Walletobjects\ListTemplateOverride;
use Google\Service\Walletobjects\LoyaltyClass;
use Google\Service\Walletobjects\LoyaltyObject;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use PDOException;
use ReflectionMethod;
use SensitiveParameter;
use Throwable;

/**
 * Owns Google payloads, authentication and confirmation; never writes local pass records.
 *
 * WalletPresentationBuilder selects content; buildObject() adds each pass's credentials.
 * buildClass() defines the shared Business layout by referencing that Object's module IDs.
 * create() confirms the provider Object; the calling Action owns local commit and URL release.
 *
 * @phpstan-import-type WalletConfiguration from GoogleWalletConfiguration
 */
class GoogleWalletPassCreator
{
    /**
     * Receives content, configuration and the shared Google SDK client.
     *
     * @param  WalletPresentationBuilder  $presentation  Builds initial content through the shared scenario selection.
     * @param  GoogleWalletConfiguration  $configuration  Loads fresh operation settings and credentials.
     * @param  GoogleWalletClient  $client  Authenticates fresh SDK resources for this operation.
     */
    public function __construct(
        private WalletPresentationBuilder $presentation,
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
        $object = $this->buildObject($business, $credentials, $classId, $objectId);

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
     * Maps Business appearance and ordered Object roles to the shared Class template.
     *
     * Each card role occupies one centered row. Each detail position selects its first available
     * role; barcode progress reads only the body. Google owns final geometry and truncation.
     *
     * @param  Business  $business  Source of customer-visible issuer identity and background color.
     * @param  string  $classId  Deterministic provider Class ID.
     * @param  string  $logoUrl  Validated native brand asset or deployment override; Google owns the holder.
     * @return LoyaltyClass Owned appearance; provider operations set the review request.
     */
    private function buildClass(Business $business, string $classId, string $logoUrl): LoyaltyClass
    {
        // build the two compact rows in their reading order
        $rows = [];

        foreach (['card_primary', 'card_secondary'] as $id) {
            $rows[] = [
                'oneItem' => [
                    'item' => [
                        'firstValue' => [
                            'fields' => [['fieldPath' => "object.textModulesData['{$id}']"]],
                        ],
                    ],
                ],
            ];
        }

        // list detail positions explicitly; alternatives share one position
        $detailRoles = [
            ['barcode_top'],
            ['details_code'],
            ['details_primary', 'card_primary'],
            ['details_primary_description'],
            ['details_primary_date'],
            ['details_context'],
            ['details_secondary', 'card_secondary'],
            ['details_secondary_description'],
            ['details_secondary_date'],
            ['details_history'],
        ];
        $details = [];

        foreach ($detailRoles as $roles) {
            $fields = [];

            foreach ($roles as $id) {
                $fields[] = ['fieldPath' => "object.textModulesData['{$id}']"];
            }

            $details[] = [
                'item' => [
                    'firstValue' => ['fields' => $fields],
                ],
            ];
        }

        // apply owned appearance without moving credentials into the Class
        $class = new LoyaltyClass([
            'id' => $classId,
            'issuerName' => "\u{200B}",
            'programName' => $business->name,
            'programLogo' => [
                'sourceUri' => ['uri' => $logoUrl],
            ],
            'hexBackgroundColor' => strtoupper($business->pass_background_color),
            'classTemplateInfo' => [
                'cardTemplateOverride' => ['cardRowTemplateInfos' => $rows],
                'cardBarcodeSectionDetails' => [
                    'firstTopDetail' => [
                        'fieldSelector' => [
                            'fields' => [
                                ['fieldPath' => "object.textModulesData['barcode_top'].body"],
                            ],
                        ],
                    ],
                ],
                'detailsTemplateOverride' => ['detailsItemInfos' => $details],
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

        // clear an old bottom field explicitly without removing the labelled manual code
        $class->getClassTemplateInfo()->getCardBarcodeSectionDetails()['firstBottomDetail'] = GoogleModel::NULL_VALUE;

        return $class;
    }

    /**
     * Builds initial content and private credentials without updating an existing Object.
     *
     * The content builder receives an unsaved pass, so progress starts at zero and personal history is absent.
     * The Class selects the display order. Object state ACTIVE does not imply an active Promotion.
     *
     * @param  Business  $business  Promotion owner.
     * @param  CustomerPassCredentials  $credentials  Private barcode and separate manual code.
     * @param  string  $classId  Owning Google class.
     * @param  string  $objectId  Individual Google object.
     * @return LoyaltyObject Initial Wallet presentation.
     *
     * @throws \IntlException If ICU cannot format a presentation date.
     * @throws \InvalidArgumentException If the progress translation is not a string.
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
        $pass = $business->customerPasses()->make(['manual_code' => $credentials->manualCode]);
        $modules = $this->presentation->build($business, $pass);

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
            'textModulesData' => $modules,
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

        return [
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
