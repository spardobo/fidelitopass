<?php

use App\Data\Time\DatabaseTime;
use App\Integrations\GoogleWallet\GoogleWalletClient;
use App\Integrations\GoogleWallet\GoogleWalletConfiguration;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Support\DatabaseClock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\GoogleWalletHttpFake;

/** Prevents framework cleanup against any database outside the isolated testing target. */
trait GuardsPersonalPassDatabase
{
    use RefreshDatabase;

    /** Verifies configured and actual PostgreSQL ownership before any reset. */
    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment('testing')
            || DB::connection()->getDriverName() !== 'pgsql'
            || DB::connection()->getDatabaseName() !== 'testing'
            || DB::scalar('SELECT current_database()') !== 'testing') {
            throw new LogicException('Refusing to reset a non-testing database.');
        }
    }
}

uses(GuardsPersonalPassDatabase::class);

beforeEach(function () {
    app()->setLocale('es');
    $this->http = new GoogleWalletHttpFake;
    app()->instance(GoogleWalletClient::class, new GoogleWalletClient($this->http->client));
    $this->mock(GoogleWalletConfiguration::class)->shouldNotReceive('load');
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->with('America/La_Paz')
        ->andReturn(new DatabaseTime('2030-01-02 12:00:00+00', '2030-01-02'));
    Log::partialMock()->shouldReceive('error')->andReturnNull();
});

it('shows current personal facts without provider calls or domain mutations', function (string $state, string $message) {
    [$business, $pass] = personalPageFixture($state);
    $factsBefore = personalPageFacts();

    $response = $this->get(URL::signedRoute('public.pass', ['businessPublicId' => $business->public_id, 'passPublicId' => $pass->public_id], absolute: false));

    $response->assertOk()->assertSee($business->name)->assertSee($message);
    $response->assertDontSee($pass->manual_code)->assertDontSee($pass->validation_token_hash)
        ->assertSee('wire:snapshot', false)->assertDontSee('data-customer-pass-acquisition');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    expect(personalPageFacts())->toBe($factsBefore);
    expect($this->http->history)->toBeEmpty();
    Log::shouldNotHaveReceived('log');

    if (in_array($state, ['waiting', 'scheduled', 'zero', 'earning', 'available', 'redeemed', 'ended', 'expired', 'cancelled'], true)) {
        $directory = base_path('test-results/personal-pass');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/'.$state.'.html', $response->getContent());
    }
})->with([
    'no offer' => ['waiting', 'Por ahora no hay una promoción disponible.'],
    'scheduled' => ['scheduled', 'Tu promoción empieza el'],
    'active zero' => ['zero', 'Llevas 0 de 8 puntos.'],
    'active earning' => ['earning', 'Llevas 3 de 8 puntos.'],
    'available reward' => ['available', '¡Tu recompensa te está esperando!'],
    'actual redemption' => ['redeemed', '¡Ya canjeaste tu recompensa!'],
    'ended' => ['ended', 'Esta promoción ya terminó.'],
    'expired entitlement' => ['expired', 'Ya no puedes canjear esta recompensa.'],
    'cancelled active' => ['cancelled', 'El negocio canceló esta promoción.'],
    'cancelled scheduled' => ['cancelled_scheduled', 'El negocio canceló esta promoción.'],
]);

it('uses identical content for equal active facts irrespective of earlier Promotions', function (string $state) {
    [$business, $pass] = personalPageFixture($state);
    $personalPageUrl = URL::signedRoute('public.pass', ['businessPublicId' => $business->public_id, 'passPublicId' => $pass->public_id], absolute: false);
    $ordinaryContent = personalPageContent($this->get($personalPageUrl)->assertOk()->getContent());
    $previousPromotion = $business->promotions()->make(['target_points' => 2, 'reward_title' => 'Old private reward']);
    $previousPromotion->forceFill(['status' => 'published', 'timezone_snapshot' => 'America/La_Paz',
        'starts_at' => '2029-01-01 04:00:00+00', 'ends_at' => '2029-02-01 04:00:00+00'])->save();
    DB::table('reward_entitlements')->insert(['business_id' => $business->id, 'customer_pass_id' => $pass->id,
        'promotion_id' => $previousPromotion->id, 'unlocked_at' => '2029-01-02 12:00:00+00',
        'redeemed_at' => '2029-01-03 12:00:00+00', 'redeemed_by_user_id' => $business->user_id]);

    $response = $this->get($personalPageUrl)->assertOk()->assertDontSee('Old private reward');
    expect(personalPageContent($response->getContent()))->toBe($ordinaryContent);
})->with(['zero', 'earning', 'available', 'redeemed']);

it('rejects missing tampered malformed and foreign scope without exposing a Business', function (string $invalidScope) {
    [$business, $pass] = personalPageFixture('earning');
    $routeParameters = ['businessPublicId' => $business->public_id, 'passPublicId' => $pass->public_id];
    if ($invalidScope === 'foreign') {
        $routeParameters['businessPublicId'] = Business::factory()->create()->public_id;
    } elseif ($invalidScope === 'missing') {
        $routeParameters['passPublicId'] = (string) Str::uuid();
    } elseif ($invalidScope === 'malformed') {
        $routeParameters['passPublicId'] = 'not-a-uuid';
    }
    if ($invalidScope === 'query-value') {
        $routeParameters['context'] = 'original';
    }
    $personalPageUrl = URL::signedRoute('public.pass', $routeParameters, absolute: $invalidScope === 'absolute-signature');
    if ($invalidScope === 'unsigned') {
        $personalPageUrl = strtok($personalPageUrl, '?');
    } elseif ($invalidScope === 'tampered') {
        $personalPageUrl .= '&extra=untrusted';
    } elseif ($invalidScope === 'tampered-business') {
        $personalPageUrl = str_replace($business->public_id, (string) Str::uuid(), $personalPageUrl);
    } elseif ($invalidScope === 'tampered-pass') {
        $personalPageUrl = str_replace($pass->public_id, (string) Str::uuid(), $personalPageUrl);
    } elseif ($invalidScope === 'query-value') {
        $personalPageUrl = str_replace('context=original', 'context=changed', $personalPageUrl);
    }
    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries) {
        $queries[] = $event->sql;
    });

    $response = $this->get($personalPageUrl)->assertNotFound()->assertSee('No pudimos abrir este enlace.')
        ->assertDontSee($business->name)->assertDontSee($pass->manual_code);

    $response->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    if (in_array($invalidScope, ['unsigned', 'tampered', 'malformed', 'tampered-business', 'tampered-pass', 'query-value', 'absolute-signature'], true)) {
        expect($queries)->toBeEmpty();
    }
    expect($this->http->history)->toBeEmpty();
    Log::shouldNotHaveReceived('error');
})->with(['unsigned', 'tampered', 'malformed', 'missing', 'foreign', 'tampered-business', 'tampered-pass', 'query-value', 'absolute-signature']);

it('returns safe private unavailability instead of fabricating absent or zero facts', function () {
    [$business, $pass] = personalPageFixture('earning');
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()
        ->andThrow(new PDOException('private-signed-url-and-database-marker'));
    $personalPageUrl = URL::signedRoute('public.pass', ['businessPublicId' => $business->public_id, 'passPublicId' => $pass->public_id], absolute: false);

    $response = $this->get($personalPageUrl)->assertStatus(503)->assertSee('Ahora no pudimos mostrarte el detalle.')
        ->assertDontSee('private-signed-url-and-database-marker')->assertDontSee('Llevas 0')
        ->assertDontSee('Ahora no hay una promoción');

    $response->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    Log::shouldHaveReceived('error')->once()->with('customer_pass.consultation_failed', Mockery::on(
        fn ($context) => $context['exception_class'] === PDOException::class
            && ! str_contains(json_encode($context), 'private-signed-url-and-database-marker')
            && ! str_contains(json_encode($context), 'signature'),
    ));
    expect($this->http->history)->toBeEmpty();
});

it('preserves real overshoot escaped descriptions and caller locale without showing validation credentials', function () {
    [$business, $pass, $promotion] = personalPageFixture('earning');
    $promotion->forceFill(['target_points' => 2, 'reward_title' => '<script>merchant</script>', 'reward_description' => 'Full <b>description</b>'])->save();
    app()->setLocale('en');

    $this->get(URL::signedRoute('public.pass', ['businessPublicId' => $business->public_id, 'passPublicId' => $pass->public_id], absolute: false))
        ->assertOk()->assertSee('Llevas 3 de 2 puntos.')->assertSee('<script>merchant</script>')
        ->assertDontSee('<script>merchant</script>', false)->assertSee('Full <b>description</b>');

    expect(app()->getLocale())->toBe('en');
});

it('uses the exclusive end and inclusive start of the original frozen timezone', function (string $capturedAt, string $message) {
    [$business, $pass] = personalPageFixture('zero');
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()
        ->andReturn(new DatabaseTime($capturedAt, '2030-01-02'));

    $this->get(URL::signedRoute('public.pass', ['businessPublicId' => $business->public_id, 'passPublicId' => $pass->public_id], absolute: false))
        ->assertOk()->assertSee($message);
})->with([
    ['2030-01-01 03:59:59.999999+00', 'Tu promoción empieza el'],
    ['2030-01-01 04:00:00+00', 'Llevas 0 de 8 puntos.'],
    ['2030-02-01 03:59:59.999999+00', 'Llevas 0 de 8 puntos.'],
    ['2030-02-01 04:00:00+00', 'Esta promoción ya terminó.'],
]);

/**
 * Creates only the immutable loyalty facts relevant to one consultation scenario.
 *
 * @param  string  $state  Requested fixture state.
 * @return array{Business, CustomerPass, Promotion|null} Scoped identity and optional offer.
 */
function personalPageFixture(string $state): array
{
    $business = Business::factory()->create(['name' => 'Panadería y pastelería del barrio — productos artesanales para compartir', 'timezone' => 'America/La_Paz']);
    $pass = $business->customerPasses()->create(['manual_code' => '00123', 'validation_token_hash' => hash('sha256', str_repeat('b', 64))]);
    if ($state === 'waiting') {
        $draftPromotion = $business->promotions()->make(['reward_title' => 'Secret draft', 'target_points' => 8, 'local_start_date' => '2030-01-01', 'local_end_date' => '2030-01-31']);
        $draftPromotion->save();

        return [$business, $pass, null];
    }
    $promotion = $business->promotions()->make(['reward_title' => 'Un pan artesanal', 'reward_description' => 'Preparado cada mañana.', 'target_points' => 8]);
    $isScheduled = in_array($state, ['scheduled', 'cancelled_scheduled'], true);
    $isEnded = in_array($state, ['ended', 'expired'], true);
    $isCancelled = in_array($state, ['cancelled', 'cancelled_scheduled'], true);
    $promotion->forceFill(['status' => $isCancelled ? 'cancelled' : 'published', 'timezone_snapshot' => 'America/La_Paz',
        'starts_at' => $isScheduled ? '2030-01-05 04:00:00+00' : '2030-01-01 04:00:00+00',
        'ends_at' => $isEnded ? '2030-01-02 04:00:00+00' : '2030-02-01 04:00:00+00',
        'cancelled_at' => $isCancelled ? '2030-01-02 10:00:00+00' : null])->save();
    if (in_array($state, ['earning', 'available', 'redeemed', 'expired'], true)) {
        DB::table('visits')->insert(['business_id' => $business->id, 'customer_pass_id' => $pass->id, 'promotion_id' => $promotion->id,
            'confirmed_by_user_id' => $business->user_id, 'operation_id' => (string) Str::uuid(),
            'confirmed_at' => '2030-01-01 12:00:00+00', 'awarded_points' => $state === 'earning' ? 3 : 8]);
    }
    if (in_array($state, ['available', 'redeemed', 'expired'], true)) {
        DB::table('reward_entitlements')->insert(['business_id' => $business->id, 'customer_pass_id' => $pass->id, 'promotion_id' => $promotion->id,
            'unlocked_at' => '2030-01-01 12:00:00+00', 'redeemed_at' => $state === 'redeemed' ? '2030-01-01 13:00:00+00' : null,
            'redeemed_by_user_id' => $state === 'redeemed' ? $business->user_id : null]);
    }

    return [$business, $pass, $promotion];
}

/**
 * Captures all scoped commercial tables without allowing session maintenance to affect the assertion.
 *
 * @return array<string, array<int, array<string, mixed>>> Persisted loyalty facts.
 */
function personalPageFacts(): array
{
    $facts = [];
    foreach (['businesses', 'customer_passes', 'promotions', 'promotion_participations', 'visits', 'reward_entitlements'] as $table) {
        $facts[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
    }

    return $facts;
}

/**
 * Selects stable visible facts, excluding Livewire's per-request transport metadata.
 *
 * @param  string  $pageHtml  Actual rendered HTTP response.
 * @return string Current information section used to compare equal facts.
 */
function personalPageContent(string $pageHtml): string
{
    preg_match('~<section[^>]*aria-labelledby="personal-status".*?</section>~s', $pageHtml, $match);

    return $match[0] ?? throw new RuntimeException('Missing personal status content.');
}

it('refuses every unnecessary hydration before reading personal facts or disclosing snapshot data', function (string $attempt) {
    [$business, $pass] = personalPageFixture('earning');
    $personalPageUrl = URL::signedRoute('public.pass', ['businessPublicId' => $business->public_id, 'passPublicId' => $pass->public_id], absolute: false);
    $pageHtml = $this->get($personalPageUrl)->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $pageHtml, $match);
    expect($match)->toHaveCount(2);
    $snapshotJson = html_entity_decode($match[1], ENT_QUOTES);
    $decodedSnapshot = json_decode($snapshotJson, true, flags: JSON_THROW_ON_ERROR);
    expect($decodedSnapshot['data'])->toBe([]);
    expect($snapshotJson)->not->toContain('signature', $pass->manual_code, $pass->validation_token_hash, $business->name);
    $this->mock(DatabaseClock::class)->shouldNotReceive('capture');
    $factsBefore = personalPageFacts();
    $calls = $attempt === 'refresh' ? [['path' => '', 'method' => '$refresh', 'params' => []]] : [];
    $response = $this->withHeaders(['X-Livewire' => 'true'])->postJson(Livewire\Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshotJson, 'updates' => $attempt === 'inject' ? ['businessPublicId' => (string) Str::uuid()] : [], 'calls' => $calls]],
    ]);
    $response->assertForbidden()->assertDontSee($business->name)->assertDontSee('Un pan artesanal');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    expect(personalPageFacts())->toBe($factsBefore);
    expect($this->http->history)->toBeEmpty();
})->with(['refresh', 'inject', 'empty']);

it('uses frozen weekday windows across boundaries without recomputing awarded progress', function (string $capturedAt, string $promotionTimezone, int $ruleWeekday, ?string $windowStartsAt, ?string $windowEndsAt, int $ruleMultiplier, int $expectedVisitPoints) {
    [$business, $pass, $promotion] = personalPageFixture('earning');
    $promotion->forceFill(['timezone_snapshot' => $promotionTimezone, 'starts_at' => '2030-01-01 00:00:00+00', 'ends_at' => '2031-01-01 00:00:00+00'])->save();
    $promotion->extraPoints()->create(['weekday' => $ruleWeekday, 'start_time' => $windowStartsAt, 'end_time' => $windowEndsAt, 'multiplier' => $ruleMultiplier]);
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()->andReturn(new DatabaseTime($capturedAt, '2030-01-02'));
    $factsBefore = personalPageFacts();

    $response = $this->get(URL::signedRoute('public.pass', ['businessPublicId' => $business->public_id, 'passPublicId' => $pass->public_id], absolute: false))
        ->assertOk()->assertSee('Llevas 3 de 8 puntos.');
    if ($expectedVisitPoints > 1) {
        $response->assertSee('Ahora tu visita vale '.$expectedVisitPoints.' puntos.');
    } else {
        $response->assertDontSee('Ahora tu visita vale');
    }
    expect(personalPageFacts())->toBe($factsBefore);
})->with([
    ['2030-01-02 12:00:00+00', 'America/Bogota', 3, null, null, 2, 2],
    ['2030-01-02 12:00:00+00', 'America/Bogota', 3, '07:00:00', '08:00:00', 3, 3],
    ['2030-01-02 12:59:59.999999+00', 'America/Bogota', 3, '07:00:00', '08:00:00', 5, 5],
    ['2030-01-02 13:00:00+00', 'America/Bogota', 3, '07:00:00', '08:00:00', 3, 1],
    ['2030-01-02 12:00:00+00', 'America/Bogota', 2, null, null, 3, 1],
    ['2030-03-10 06:59:59.999999+00', 'America/New_York', 7, '01:00:00', '02:00:00', 3, 3],
    ['2030-03-10 07:00:00+00', 'America/New_York', 7, '01:00:00', '02:00:00', 3, 1],
    ['2030-11-03 05:30:00+00', 'America/New_York', 7, '01:00:00', '02:00:00', 2, 2],
    ['2030-11-03 06:30:00+00', 'America/New_York', 7, '01:00:00', '02:00:00', 2, 2],
]);

it('keeps a local inclusive deadline across a DST day and does not invent absent descriptions', function () {
    [$business, $pass, $promotion] = personalPageFixture('zero');
    $promotion->forceFill(['timezone_snapshot' => 'America/New_York', 'ends_at' => '2030-03-11 04:00:00+00', 'reward_description' => null])->save();
    $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()->andReturn(new DatabaseTime('2030-03-10 12:00:00+00', '2030-03-10'));

    $this->get(URL::signedRoute('public.pass', ['businessPublicId' => $business->public_id, 'passPublicId' => $pass->public_id], absolute: false))
        ->assertOk()->assertSee('10 mar 2030')->assertDontSee('Preparado cada mañana.');
});

it('prioritizes the nearest published future offer over old personal facts without advertising drafts or cancellations', function () {
    [$business, $pass] = personalPageFixture('expired');
    $scheduledPromotion = $business->promotions()->make(['target_points' => 4, 'reward_title' => 'Una merienda artesanal']);
    $scheduledPromotion->forceFill(['status' => 'published', 'timezone_snapshot' => 'America/La_Paz', 'starts_at' => '2030-02-03 04:00:00+00', 'ends_at' => '2030-02-05 04:00:00+00'])->save();

    $this->get(URL::signedRoute('public.pass', ['businessPublicId' => $business->public_id, 'passPublicId' => $pass->public_id], absolute: false))
        ->assertOk()->assertSee('Una merienda artesanal')->assertSee('Tu promoción empieza el')->assertDontSee('Ya no puedes canjear');
});
