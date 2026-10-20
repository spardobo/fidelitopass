<?php

use App\Data\Time\DatabaseTime;
use App\Integrations\GoogleWallet\GoogleWalletClient;
use App\Integrations\GoogleWallet\GoogleWalletConfiguration;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Support\DatabaseClock;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\GoogleWalletHttpFake;
use Tests\Support\PostgresDatabaseTruncation;

/** Guards native cleanup before resetting any data. */
trait GuardsVisitDialogDatabase
{
    use PostgresDatabaseTruncation;

    /** Refuses both configured and actual databases outside testing. */
    protected function beforeTruncatingDatabase(): void
    {
        if (
            ! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'testing'
            || DB::selectOne('SELECT current_database() AS name')->name !== 'testing'
        ) {
            throw new LogicException('Refusing to reset a non-testing database.');
        }
    }
}

uses(GuardsVisitDialogDatabase::class);

beforeEach(function () {
    app()->setLocale('es');
    test()->mock(DatabaseClock::class)->shouldReceive('capture')
        ->andReturn(new DatabaseTime('2030-01-02 12:00:00.123456+00', '2030-01-02'));
});

afterEach(function () {
    $this->truncateTablesForAllConnections();
});

it('identifies a scoped pass read-only without hydrating private credentials', function (string $method, string $credential) {
    [$business, $pass, $promotion] = visitDialogFixture();
    $promotion->extraPoints()->create(['weekday' => 3, 'multiplier' => 3]);
    $component = Livewire::actingAs($business->user)->test('visit-registration');

    $component->call('findPass', $method, $credential);
    $result = $component->effects['returns'][0];

    expect(Str::isUuid($result))->toBeTrue();
    $component->assertSet('stage', 'confirm');
    expect($component->get('preview'))->toBe([
        'checked_at' => '2030-01-02 12:00:00.123456+00',
        'promotion' => [
            'id' => $promotion->id,
            'target_points' => 3,
            'earned_points' => 0,
            'reward_title' => 'Una recompensa real',
            'reward_description' => 'Términos originales',
            'reward_status' => 'none',
            'visit_points' => 3,
        ],
        'can_accept_visit' => true,
        'can_redeem_reward' => false,
    ]);
    expect(json_encode($component->snapshot['data']))->not->toContain(trim($credential), 'validation_token_hash', 'manual_code', 'wallet_object_id');
    expect($pass->fresh()->manual_code)->toBe('00007');
    $this->assertDatabaseCount('visits', 0);
    $this->assertDatabaseCount('reward_entitlements', 0);
})->with(['manual' => ['manual', ' 00007 '], 'scan' => ['token', str_repeat('a', 64)]]);

it('rejects foreign or malformed credentials with the shared invalid-attempt counter', function (string $method, string $credential) {
    [$business] = visitDialogFixture();
    $other = Business::factory()->create();
    $other->customerPasses()->create(['manual_code' => '11111', 'validation_token_hash' => hash('sha256', str_repeat('b', 64))]);
    $component = Livewire::actingAs($business->user)->test('visit-registration');

    $component->call('findPass', $method, $credential);

    expect($component->effects['returns'][0])->toBeNull();
    $component->assertSet('stage', 'identify')->assertSet('errorCode', 'invalid_credential')
        ->assertSet('preview', null)->assertSet('facts', null)->assertSet('details', null);
    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(1);
    $this->assertDatabaseCount('visits', 0);
})->with([
    'foreign manual' => ['manual', '11111'],
    'foreign token' => ['token', str_repeat('b', 64)],
    'public acquisition' => ['token', 'https://passes.example.test/join/public'],
    'token is not trimmed' => ['token', ' '.str_repeat('a', 64)],
    'manual is not repaired' => ['manual', '000-07'],
    'six digits' => ['manual', '000007'],
]);

it('accepts legacy uppercase manual codes but not repaired case', function () {
    [$business, $pass] = visitDialogFixture();
    $pass->update(['manual_code' => 'ABCD01234567']);
    $component = Livewire::actingAs($business->user)->test('visit-registration');

    $component->call('findPass', 'manual', "\tABCD01234567\n");
    expect(Str::isUuid($component->effects['returns'][0]))->toBeTrue();
    $component->call('findPass', 'manual', 'abcd01234567');
    expect($component->effects['returns'][0])->toBeNull();
});

it('rechecks actor permission on every read and mutation request', function (string $actor) {
    [$business, $pass, $promotion] = visitDialogFixture();
    if ($actor === 'unverified') {
        $business->user->forceFill(['email_verified_at' => null])->save();
        Livewire::actingAs($business->user);
    }
    $component = Livewire::test('visit-registration');
    $id = (string) Str::uuid();

    foreach ([['findPass', 'manual', $pass->manual_code], ['acceptVisit', 'manual', $pass->manual_code, $id, $promotion->id], ['findVisitReceipt', 'manual', $pass->manual_code, $id]] as $call) {
        $component->call(...$call);
        expect($component->effects['returns'][0])->toBe(match ($call[0]) {
            'acceptVisit' => 'rejected',
            'findPass' => null,
            'findVisitReceipt' => false,
        });
        $component->assertSet('errorCode', 'forbidden');
    }
    $this->assertDatabaseCount('visits', 0);
})->with(['guest', 'unverified']);

it('throttles pass finding without disclosing a previous selection', function () {
    [$business] = visitDialogFixture();
    config(['pass_lookup.max_invalid_attempts' => 1]);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('findPass', 'manual', '99999');

    $component->call('findPass', 'manual', '00007');

    $component->assertSet('errorCode', 'throttled')->assertSet('preview', null);
});

it('confirms a real Visit and Reward once and reads the immutable original receipt', function () {
    [$business, $pass, $promotion] = visitDialogFixture();
    $http = visitDialogProvider($business, $pass);
    $promotion->extraPoints()->create(['weekday' => 3, 'multiplier' => 3]);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('findPass', 'manual', '00007');
    $id = $component->effects['returns'][0];

    $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
    $accepted = $component->get('facts');
    expect($component->effects['returns'][0])->toBe('confirmed');
    $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
    expect($component->get('facts'))->toBe($accepted);
    $component->call('findVisitReceipt', 'manual', '00007', $id);

    expect($component->effects['returns'][0])->toBeTrue();
    expect(array_keys($accepted))->toBe(['visit_id', 'operation_id', 'customer_pass_id', 'promotion_id', 'points_awarded', 'confirmed_at']);
    expect($accepted['points_awarded'])->toBe(3);
    expect($component->get('facts'))->toBe($accepted);
    expect($component->get('details'))->toBe([
        'reward_title' => 'Una recompensa real',
        'reward_description' => 'Términos originales',
        'target_points' => 3,
        'earned_points' => 3,
        'reward_unlocked' => true,
    ]);
    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 1);
    expect($http->history)->toHaveCount(3);
    expect(json_encode($component->snapshot['data']))->not->toContain('00007', 'manual_code', 'validation_token_hash');
    expect(array_keys($component->snapshot['data']))->toBe(['stage', 'preview', 'facts', 'details', 'errorCode']);
});

it('compares the selected offer inside acceptance and rejects a replacement without provider writes', function () {
    [$business, $pass, $promotion] = visitDialogFixture();
    $http = visitDialogProvider($business, $pass);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('findPass', 'manual', '00007');
    $id = $component->effects['returns'][0];
    $promotion->forceFill(['status' => 'cancelled', 'cancelled_at' => '2030-01-02 11:00:00+00'])->save();
    $replacement = $promotion->replicate(['public_id']);
    $replacement->forceFill(['status' => 'published', 'cancelled_at' => null, 'starts_at' => '2030-01-02 11:00:00+00'])->save();

    $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);

    expect($component->effects['returns'][0])->toBe('rejected');
    $component->assertSet('stage', 'confirm')->assertSet('preview.promotion.id', $promotion->id)
        ->assertDispatched('toast-show', slots: ['text' => __('visit-registration.error_notice')], dataset: ['variant' => 'danger']);
    expect($http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', 0);
});

it('keeps receipt checks read-only and scoped to the current credential', function () {
    [$business, $pass, $promotion] = visitDialogFixture();
    $http = visitDialogProvider($business, $pass);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $id = (string) Str::uuid();
    $component->call('findVisitReceipt', 'manual', '00007', 'not-an-operation-uuid');
    $component->assertSet('stage', 'identify')->assertSet('errorCode', 'unknown');
    expect($http->history)->toBeEmpty();
    $this->assertDatabaseCount('visits', 0);
    $component->call('findVisitReceipt', 'manual', '00007', $id);
    expect($component->effects['returns'][0])->toBeFalse();
    $component->assertSet('stage', 'identify');
    $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
    $facts = $component->get('facts');
    $promotion->forceFill(['status' => 'cancelled', 'cancelled_at' => '2030-01-02 13:00:00+00'])->save();
    $component->call('findVisitReceipt', 'manual', '00007', $id);
    $component->assertSet('facts', $facts);
    $pass->update(['manual_code' => '00008']);

    $component->call('findVisitReceipt', 'manual', '00007', $id);

    expect($component->effects['returns'][0])->toBeFalse();
    $component->assertSet('stage', 'result')->assertSet('facts', $facts);
    expect($http->history)->toHaveCount(3);
    $this->assertDatabaseCount('visits', 1);
});

it('treats a native completion-log failure as unknown and recovers without another mutation', function () {
    [$business, $pass, $promotion] = visitDialogFixture();
    $http = visitDialogProvider($business, $pass);
    $id = (string) Str::uuid();
    $events = [];
    $committedBeforeLogging = false;
    Log::listen(function (MessageLogged $event) use (&$events, &$committedBeforeLogging): void {
        $events[] = $event->message;
        expect(json_encode($event->context))->not->toContain('private payload');
        if ($event->message === 'visit.accepted') {
            $committedBeforeLogging = DB::transactionLevel() === 0 && DB::table('visits')->count() === 1;
            throw new RuntimeException('private payload');
        }
    });
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('findPass', 'manual', '00007');

    $response = $this->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($component->snapshot),
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'acceptVisit', 'params' => ['manual', '00007', $id, $promotion->id]]],
        ]],
    ], ['X-Livewire' => 'true']);
    $response->assertOk()->assertJsonPath('components.0.effects.returns.0', 'unknown')
        ->assertJsonPath('components.0.effects.dispatches.0.name', 'toast-show')
        ->assertJsonPath('components.0.effects.dispatches.0.params.dataset.variant', 'danger');
    expect(json_decode($response->json('components.0.snapshot'), true)['data']['stage'])->toBe('confirm');
    expect($response->json('components.0.effects.html'))->toContain('Una recompensa real');
    expect($committedBeforeLogging)->toBeTrue();
    expect(array_count_values($events)['application.unexpected_exception'])->toBe(1);
    expect($events)->not->toContain('visit.rejected', 'visit.acceptance_failed');
    $component->call('findVisitReceipt', 'manual', '00007', $id);

    expect($component->effects['returns'][0])->toBeTrue();
    $component->assertSet('stage', 'result')->assertSet('facts.points_awarded', 1);
    expect($http->history)->toHaveCount(3);
    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(0);
    $this->assertDatabaseHas('visits', ['operation_id' => $id, 'awarded_points' => 1]);
    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 0);
});

it('renders native stages and safe locked facts without hydrating the private credential', function () {
    [$business, , $promotion] = visitDialogFixture();
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->assertSet('stage', 'identify')->assertSee(__('visit-registration.manual_label'))
        ->assertDontSee(__('visit-registration.confirmation_title'));

    $component->call('findPass', 'manual', '00007');

    $component->assertSet('stage', 'confirm')->assertSet('preview.promotion.id', $promotion->id)
        ->assertSee('Una recompensa real')->assertSee(__('visit-registration.confirm_visit'))
        ->assertDontSee(__('visit-registration.manual_label'));
    expect(Str::isUuid($component->effects['returns'][0]))->toBeTrue();
    expect(json_encode($component->snapshot['data']))->not->toContain('00007', str_repeat('a', 64), 'validation_token_hash');
    expect(fn () => $component->set('stage', 'result'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('keeps known committed facts positive when a receipt is missing or its credential is no longer valid', function () {
    [$business, $pass, $promotion] = visitDialogFixture();
    $http = visitDialogProvider($business, $pass);
    $id = (string) Str::uuid();
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
    $component->assertSet('stage', 'result')->assertSet('facts.points_awarded', 1);
    $facts = $component->get('facts');

    $component->call('findVisitReceipt', 'manual', '00007', (string) Str::uuid());
    $component->assertSet('stage', 'result')->assertSet('facts', $facts)
        ->assertSee(__('visit-registration.success_title'))->assertDontSee('Actualizar detalles')
        ->assertDontSee(__('visit-registration.confirm_visit'));
    $pass->update(['manual_code' => '00008']);
    $component->call('findVisitReceipt', 'manual', '00007', $id);
    $component->assertSet('stage', 'result')->assertSet('facts', $facts);
    expect($http->history)->toHaveCount(3);
    $this->assertDatabaseCount('visits', 1);
});

it('renders scoped loading and current form control semantics for every backend operation', function () {
    [$business] = visitDialogFixture();
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $document = new DOMDocument;
    @$document->loadHTML(mb_convert_encoding($component->html(), 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($document);
    $input = $xpath->query('//input[@id="visit-manual-code"]')->item(0);
    expect($input->getAttribute('class'))->toContain('app-input');
    expect($input->getAttribute('placeholder'))->toBe('Ej: 583');
    expect($input->hasAttribute('autofocus'))->toBeFalse();
    expect($input->getAttribute('x-bind:autofocus'))->toBe('manualAutofocus');
    expect($xpath->query('//button[@data-visit-close]')->item(0)->getAttribute('x-bind:autofocus'))->toBe('!manualAutofocus');
    expect($xpath->query('//*[@data-qr-camera-region and @*[name()="x-cloak"]]')->length)->toBe(1);
    $component->assertSee('Ingresa el código de 5 dígitos que aparece en la Tarjeta del Cliente.');
    expect($input->hasAttribute('wire:model'))->toBeFalse();
    expect($input->getAttribute('inputmode'))->not->toBe('numeric');
    expect($input->getAttribute('maxlength'))->not->toBe('5');
    expect($xpath->query('//button[@type="submit"]//*[@data-flux-loading-indicator]')->length)->toBe(1);
    expect($xpath->query('//*[@data-qr-camera]//button')->length)->toBe(1);
    expect($xpath->query('//button[@data-visit-camera-control]//*[@data-flux-loading-indicator]')->length)->toBe(1);

    $component->call('findPass', 'manual', '00007');
    @$document->loadHTML(mb_convert_encoding($component->html(), 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($document);
    expect($xpath->query('//button[@*[name()="wire:target"]="acceptVisit,findVisitReceipt"]//*[@data-flux-loading-indicator]')->length)->toBe(1);
    expect($xpath->query('//button[@data-visit-back]//*[@data-flux-loading-indicator]')->length)->toBe(1);
    expect($xpath->query('//button[@data-visit-confirm]')->length)->toBe(1);
    expect($xpath->query('//h3[@tabindex]')->length)->toBe(0);
    expect($xpath->query('//*[contains(@class, "app-note-with-icon")]//p[contains(text(), "Revisa los puntos")]/../span/svg')->length)->toBe(1);
});

it('hides the Visit CTA for an available Reward and restores clean native identification', function () {
    [$business, $pass, $promotion] = visitDialogFixture(1);
    $http = visitDialogProvider($business, $pass);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('findPass', 'manual', '00007');
    $id = $component->effects['returns'][0];
    $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
    $component->call('findVisitReceipt', 'manual', '00007', $id);
    $component->assertSee(__('visit-registration.reward_unlocked'))->assertSet('details.earned_points', 1);

    $component->call('resetIdentification');
    $component->assertSet('stage', 'identify')->assertSet('preview', null)->assertSet('facts', null)->assertSet('details', null);
    $component->call('findPass', 'manual', '00007');

    $component->assertSet('stage', 'confirm')->assertSet('preview.can_redeem_reward', true)
        ->assertSee(__('visit-registration.available_reward'))->assertDontSee(__('visit-registration.confirm_visit'));
    expect($http->history)->toHaveCount(3);
    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 1);
});

it('keeps identification on a failed search and dispatches one contextual danger toast', function () {
    [$business] = visitDialogFixture();
    $component = Livewire::actingAs($business->user)->test('visit-registration');

    $component->call('findPass', 'manual', '99999');

    $component->assertSet('stage', 'identify')->assertSee(__('visit-registration.manual_label'))
        ->assertDispatched('toast-show', slots: ['text' => __('visit-registration.error_notice')], dataset: ['variant' => 'danger'])
        ->assertDontSee('Resultado pendiente de comprobar')->assertDontSee('Comprobar estado');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    $error = (new DOMXPath($document))->query('//*[@id="visit-manual-error" and @data-flux-error]')->item(0);
    expect($error?->textContent)->toContain(__('visit-registration.invalid_credential'));
    expect($component->effects['dispatches'])->toHaveCount(1);
    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(1);
    $this->assertDatabaseCount('visits', 0);
});

it('keeps the selected confirmation on rejection instead of replacing it with an error screen', function () {
    [$business, $pass, $promotion] = visitDialogFixture();
    $http = visitDialogProvider($business, $pass);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('findPass', 'manual', '00007');
    $id = $component->effects['returns'][0];
    $preview = $component->get('preview');

    $component->call('acceptVisit', 'manual', '00008', $id, $promotion->id);

    $component->assertSet('stage', 'confirm')->assertSet('preview', $preview)
        ->assertSee(__('visit-registration.confirm_visit'))
        ->assertDispatched('toast-show', slots: ['text' => __('visit-registration.error_notice')], dataset: ['variant' => 'danger'])
        ->assertDontSee('Resultado pendiente de comprobar')->assertDontSee('Comprobar estado');
    expect($component->effects['dispatches'])->toHaveCount(1);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    $error = (new DOMXPath($document))->query('//*[@id="visit-action-error" and @data-flux-error]')->item(0);
    expect($error?->textContent)->toContain(__('visit-registration.invalid_credential'));
    expect($http->history)->toBeEmpty();
    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(1);
    $this->assertDatabaseCount('visits', 0);
});

it('keeps confirmed facts positive with a toast and no technical refresh button when receipt details are unavailable', function () {
    [$business, $pass, $promotion] = visitDialogFixture();
    $http = visitDialogProvider($business, $pass);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('findPass', 'manual', '00007');
    $id = $component->effects['returns'][0];
    $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
    $facts = $component->get('facts');

    $component->call('findVisitReceipt', 'manual', '00007', (string) Str::uuid());

    $component->assertSet('stage', 'result')->assertSet('facts', $facts)
        ->assertDispatched('toast-show', slots: ['text' => __('visit-registration.details_notice')], dataset: ['variant' => 'danger'])
        ->assertSee(__('visit-registration.success_title'))->assertDontSee('Actualizar detalles')->assertDontSee('Comprobar estado');
    expect($component->effects['dispatches'])->toHaveCount(1);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    $error = (new DOMXPath($document))->query('//*[@id="visit-result-error" and @data-flux-error]')->item(0);
    expect($error?->textContent)->toContain(__('visit-registration.details_unavailable'));
    expect($http->history)->toHaveCount(3);
    $this->assertDatabaseHas('visits', ['operation_id' => $id, 'awarded_points' => 1]);
    $this->assertDatabaseCount('visits', 1);
});

it('shows a required manual error without skipping the shared invalid attempt guard', function () {
    [$business] = visitDialogFixture();
    $component = Livewire::actingAs($business->user)->test('visit-registration');

    $component->call('findPass', 'manual', '   ');

    $component->assertSet('stage', 'identify')
        ->assertSet('errorCode', 'manual_required')
        ->assertDispatched('toast-show', slots: ['text' => __('visit-registration.error_notice')], dataset: ['variant' => 'danger']);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    $error = (new DOMXPath($document))->query('//*[@id="visit-manual-error" and @data-flux-error]')->item(0);
    expect($error?->textContent)->toContain(__('visit-registration.manual_required'));
    expect($component->effects['dispatches'])->toHaveCount(1);
    expect(RateLimiter::attempts('pass-lookup:'.$business->user_id))->toBe(1);
    expect($component->effects['returns'][0])->toBeNull();
    $this->assertDatabaseCount('visits', 0);
    $this->assertDatabaseCount('reward_entitlements', 0);
});

it('renders authoritative progress and consistent point and reward roles before and after confirmation', function () {
    [$business, $pass, $promotion] = visitDialogFixture();
    $http = visitDialogProvider($business, $pass);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('findPass', 'manual', '00007');
    $id = $component->effects['returns'][0];
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    $xpath = new DOMXPath($document);
    $bar = $xpath->query('//*[@id="visit-progress" and @data-flux-progress]')->item(0);

    expect($bar)->not->toBeNull();
    expect($bar->getAttribute('value'))->toBe('0');
    expect($bar->getAttribute('max'))->toBe('3');
    expect($xpath->query('//*[@id="visit-points-gain"]')->item(0)->getAttribute('class'))->toContain('app-role-metric');
    $rewardIcon = $xpath->query('//*[@id="visit-reward"]//*[local-name()="svg"]')->item(0)?->C14N();
    expect($rewardIcon)->not->toBeNull();
    $component->assertSee('Una recompensa real');
    $this->assertDatabaseCount('visits', 0);

    $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
    $facts = $component->get('facts');
    $component->call('findVisitReceipt', 'manual', '00007', $id);
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    $xpath = new DOMXPath($document);
    $bar = $xpath->query('//*[@id="visit-progress" and @data-flux-progress]')->item(0);
    expect($bar->getAttribute('value'))->toBe('1');
    expect($bar->getAttribute('max'))->toBe('3');
    expect($xpath->query('//*[@id="visit-points-gain"]')->item(0)->getAttribute('class'))->toContain('app-role-metric');
    expect($xpath->query('//*[@id="visit-reward"]//*[local-name()="svg"]')->item(0)?->C14N())->toBe($rewardIcon);
    expect($facts['points_awarded'])->toBe(1);
    expect($http->history)->toHaveCount(3);
    $this->assertDatabaseHas('visits', ['operation_id' => $id, 'awarded_points' => 1]);
    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 0);
});

it('caps only the visual progress when an authoritative award exceeds the target', function () {
    [$business, $pass, $promotion] = visitDialogFixture(1);
    $promotion->extraPoints()->create(['weekday' => 3, 'multiplier' => 3]);
    $http = visitDialogProvider($business, $pass);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('findPass', 'manual', '00007');
    $id = $component->effects['returns'][0];
    $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
    $component->call('findVisitReceipt', 'manual', '00007', $id);
    $component->assertSet('facts.points_awarded', 3)->assertSet('details.earned_points', 3);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    $bar = (new DOMXPath($document))->query('//*[@id="visit-progress" and @data-flux-progress]')->item(0);

    expect($bar)->not->toBeNull();
    expect($bar->getAttribute('value'))->toBe('1');
    expect($bar->getAttribute('max'))->toBe('1');
    expect($bar->getAttribute('aria-valuetext'))->toBe('3 de 1 puntos');
    expect($http->history)->toHaveCount(3);
    $this->assertDatabaseHas('visits', ['operation_id' => $id, 'awarded_points' => 3]);
    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 1);
});

it('uses a matching icon heading for each operational step', function () {
    [$business, $pass, $promotion] = visitDialogFixture();
    visitDialogProvider($business, $pass);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $document = new DOMDocument;
    foreach (['identify', 'confirm', 'result'] as $stage) {
        if ($stage === 'confirm') {
            $component->call('findPass', 'manual', '00007');
            $id = $component->effects['returns'][0];
        } elseif ($stage === 'result') {
            $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
        }
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new DOMXPath($document);
        $heading = $xpath->query('//*[@data-modal-step-heading]')->item(0);
        expect($heading)->not->toBeNull();
        expect($xpath->query('//*[@data-modal-step-heading]//*[local-name()="svg"]')->length)->toBe(1);
        expect($xpath->query('//*[@data-modal-step-heading]//h3')->length)->toBe(1);
        expect($heading->textContent)->toContain(__([
            'identify' => 'visit-registration.manual_label',
            'confirm' => 'visit-registration.confirmation_title',
            'result' => 'visit-registration.success_title',
        ][$stage]));
    }
});

it('renders one points progress reward composition with truthful descriptions in both cards', function () {
    [$business, $pass, $promotion] = visitDialogFixture();
    visitDialogProvider($business, $pass);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('findPass', 'manual', '00007');
    $id = $component->effects['returns'][0];
    $component->assertSet('preview.promotion.reward_description', 'Términos originales');
    $document = new DOMDocument;
    $structure = null;
    foreach (['confirm', 'result'] as $stage) {
        if ($stage === 'result') {
            $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
            $component->call('findVisitReceipt', 'manual', '00007', $id);
        }
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $xpath = new DOMXPath($document);
        $card = $xpath->query('//*[@data-visit-points-card]')->item(0);
        expect($card)->not->toBeNull();
        expect($card->getAttribute('class'))->toContain($stage === 'confirm' ? 'bg-app-emphasis' : 'bg-app-success-surface');
        $roles = [];
        foreach ($xpath->query('//*[@data-visit-points-card]//*[@id="visit-points-gain" or @id="visit-progress" or @id="visit-reward"]') as $role) {
            $roles[] = $role->getAttribute('id');
        }
        expect($roles)->toBe(['visit-points-gain', 'visit-progress', 'visit-reward']);
        $component->assertSee('Términos originales')->assertSee('Una recompensa real');
        expect($xpath->query('//*[@id="visit-reward"]//p')->item(0)->textContent)->toBe('Términos originales');
        $structure ??= $roles;
        expect($roles)->toBe($structure);
    }
    $this->assertDatabaseCount('visits', 1);
});

it('orders footer actions by the explicit stage rather than their button count', function () {
    [$business, $pass, $promotion] = visitDialogFixture();
    visitDialogProvider($business, $pass);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $document = new DOMDocument;
    foreach (['identify' => ['Cerrar'], 'confirm' => ['Cerrar', 'Volver a leer', 'Confirmar visita'], 'result' => ['Cerrar', 'Volver a leer']] as $stage => $expected) {
        if ($stage === 'confirm') {
            $component->call('findPass', 'manual', '00007');
            $id = $component->effects['returns'][0];
        } elseif ($stage === 'result') {
            $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
        }
        @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        $labels = [];
        foreach ((new DOMXPath($document))->query('//footer//button') as $button) {
            $labels[] = trim($button->textContent);
        }
        expect($labels)->toBe($expected);
        $close = (new DOMXPath($document))->query('//footer//button[@data-visit-close]')->item(0);
        expect($close->getAttribute('class'))->not->toContain('app-button-secondary');
        expect($close->parentNode->getAttribute('class'))->toContain('me-auto');
    }
});

it('checks an original receipt without replacing fresh identification when presentation is suppressed', function () {
    [$business, $pass, $promotion] = visitDialogFixture();
    $http = visitDialogProvider($business, $pass);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('findPass', 'manual', '00007');
    $id = $component->effects['returns'][0];
    $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
    $component->call('resetIdentification');
    $component->call('findVisitReceipt', 'manual', '00007', $id, false);
    expect($component->effects['returns'][0])->toBeTrue();
    $component->assertSet('stage', 'identify')->assertSet('facts', null)->assertSet('details', null)->assertSet('preview', null);
    expect($http->history)->toHaveCount(3);
    $this->assertDatabaseCount('visits', 1);
});

it('shows confirmed reward unlocking beside the success heading rather than inside the card', function () {
    [$business, $pass, $promotion] = visitDialogFixture(1);
    visitDialogProvider($business, $pass);
    $component = Livewire::actingAs($business->user)->test('visit-registration');
    $component->call('findPass', 'manual', '00007');
    $id = $component->effects['returns'][0];
    $component->call('acceptVisit', 'manual', '00007', $id, $promotion->id);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    expect((new DOMXPath($document))->query('//*[@data-visit-reward-unlocked]')->length)->toBe(0);
    $component->call('findVisitReceipt', 'manual', '00007', $id);
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    $xpath = new DOMXPath($document);
    $badge = $xpath->query('//*[@data-modal-step-heading]//*[@data-visit-reward-unlocked]')->item(0);
    expect($badge)->not->toBeNull();
    expect($badge->getAttribute('class'))->not->toContain('uppercase');
    expect(trim($badge->textContent))->toBe(__('visit-registration.reward_unlocked'));
    expect($xpath->query('//*[@data-visit-points-card]//*[@data-visit-reward-unlocked]')->length)->toBe(0);
    expect($xpath->query('//*[@data-visit-points-card]//p[contains(text(), "Recompensa desbloqueada")]')->length)->toBe(0);
    $this->assertDatabaseCount('visits', 1);
    $this->assertDatabaseCount('reward_entitlements', 1);
});

/**
 * Creates owned frozen terms and a private pass without provider effects.
 *
 * @param  int  $targetPoints  Frozen target used by the real acceptance and read paths.
 * @return array{Business, CustomerPass, Promotion} Scoped test records.
 */
function visitDialogFixture(int $targetPoints = 3): array
{
    $business = Business::factory()->create(['timezone' => 'America/Bogota', 'pass_background_color' => '#123456']);
    RateLimiter::clear('pass-lookup:'.$business->user_id);
    $pass = $business->customerPasses()->create(['manual_code' => '00007', 'validation_token_hash' => hash('sha256', str_repeat('a', 64))]);
    $pass->update(['wallet_object_id' => '123456.pass_'.$pass->public_id]);
    $promotion = $business->promotions()->make();
    $promotion->forceFill([
        'status' => 'published',
        'target_points' => $targetPoints,
        'reward_title' => 'Una recompensa real',
        'reward_description' => 'Términos originales',
        'timezone_snapshot' => 'America/Bogota',
        'starts_at' => '2030-01-01 05:00:00+00',
        'ends_at' => '2030-02-01 05:00:00+00',
    ])->save();

    return [$business, $pass, $promotion];
}

/**
 * Binds synthetic signing and the native SDK transport without reading credentials.
 *
 * @param  Business  $business  Owning Business.
 * @param  CustomerPass  $pass  Existing object with immutable credential bindings.
 * @return GoogleWalletHttpFake Observable provider history.
 */
function visitDialogProvider(Business $business, CustomerPass $pass): GoogleWalletHttpFake
{
    $http = new GoogleWalletHttpFake;
    app()->instance(GoogleWalletClient::class, new GoogleWalletClient($http->client));
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    test()->mock(GoogleWalletConfiguration::class)->shouldReceive('load')->with(true)->andReturn([
        'issuer_id' => '123456',
        'account' => ['type' => 'service_account', 'client_id' => 'synthetic-client', 'client_email' => 'wallet@platform.iam.gserviceaccount.com', 'private_key' => $pem],
        'origin' => 'https://passes.example.test',
        'logos' => ['black' => 'https://passes.example.test/logo.png'],
        'connect_timeout' => 3,
        'timeout' => 10,
    ]);
    $remote = [
        'id' => $pass->wallet_object_id,
        'classId' => '123456.business_'.$business->public_id,
        'accountId' => $pass->manual_code,
        'barcode' => ['type' => 'QR_CODE', 'value' => str_repeat('a', 64)],
        'textModulesData' => [],
    ];
    $http->respondWith([
        'https://oauth2.googleapis.com/token' => GoogleWalletHttpFake::json(['access_token' => 'synthetic', 'expires_in' => 3600]),
        'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/'.$pass->wallet_object_id => function (Request $request) use (&$remote) {
            if ($request->method() === 'PATCH') {
                $remote = array_replace($remote, $request->data());
            }

            return GoogleWalletHttpFake::json($remote);
        },
    ]);

    return $http;
}
