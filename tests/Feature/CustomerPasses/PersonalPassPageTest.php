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
    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $expectedHeading = match ($state) {
        'waiting' => '¡Qué gusto verte por aquí!',
        'scheduled' => '¡Se viene algo bueno!',
        'zero' => '¡Vamos por tu recompensa!',
        'earning' => '¡Vas sumando!',
        'available' => '¡Lo lograste!',
        'redeemed' => '¡Listo, recompensa canjeada!',
        'ended' => 'Esta promoción llegó a su fin.',
        'expired' => 'Esta recompensa ya venció.',
        default => 'Esta promoción fue cancelada.',
    };
    expect(trim($xpath->query('//*[@id="personal-status"]')->item(0)->textContent))->toBe($expectedHeading);
    $facts = match ($state) {
        'waiting' => ['Por ahora no hay promociones disponibles.'],
        'scheduled' => ['Empiezas a sumar el 5 ene 2030.', 'Cuando empiece, muestra tu tarjeta en el negocio para sumar.'],
        'zero' => ['0 de 8 puntos', 'Tienes hasta el 31 ene 2030 para sumar.', 'Muestra tu tarjeta en tu próxima visita y empieza a sumar.'],
        'earning' => ['3 de 8 puntos', 'Tienes hasta el 31 ene 2030 para sumar.', 'Muestra tu tarjeta en tu próxima visita y sigue sumando.'],
        'available' => ['Puedes canjearla hasta el 31 ene 2030.', 'Muestra tu tarjeta en el negocio para canjearla.'],
        'redeemed' => ['La canjeaste el 1 ene 2030.'],
        'ended' => ['Estuvo disponible hasta el 1 ene 2030.'],
        'expired' => ['Podías canjearla hasta el 1 ene 2030.'],
        default => [],
    };
    $paragraphs = iterator_to_array($xpath->query('//section[@aria-labelledby="personal-status"]/p'));
    $visibleParagraphs = array_map(static fn (DOMNode $node): string => trim(preg_replace('/\s+/u', ' ', $node->textContent)), $paragraphs);
    expect($visibleParagraphs)->toBe($state === 'waiting' ? $facts : ['Un pan artesanal', 'Preparado cada mañana.', ...$facts]);
    if ($state !== 'waiting') {
        expect($xpath->query('//section[@aria-labelledby="personal-status"]/p[normalize-space(.)="Un pan artesanal"]')->length)->toBe(1);
    }
    if (in_array($state, ['zero', 'earning'], true)) {
        $progress = $xpath->query('//p[@data-personal-progress]')->item(0);
        expect($progress)->not->toBeNull();
        expect(trim($progress->lastElementChild->textContent))->toBe('puntos');
        expect($progress->firstElementChild->childElementCount)->toBe(2);
    }
    $response->assertDontSee($pass->manual_code)->assertDontSee($pass->validation_token_hash)
        ->assertSee('wire:snapshot', false)->assertDontSee('data-customer-pass-acquisition');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    expect(personalPageFacts())->toBe($factsBefore);
    expect($this->http->history)->toBeEmpty();
    Log::shouldNotHaveReceived('log');

    if (in_array($state, ['waiting', 'scheduled', 'zero', 'earning', 'available', 'redeemed', 'ended', 'expired', 'cancelled'], true)) {
        personalPageArtifact($state, $response->getContent());
    }
})->with([
    'no offer' => ['waiting', 'Por ahora no hay promociones disponibles.'],
    'scheduled' => ['scheduled', 'Empiezas a sumar el'],
    'active zero' => ['zero', '¡Vamos por tu recompensa!'],
    'active earning' => ['earning', '¡Vas sumando!'],
    'available reward' => ['available', '¡Lo lograste!'],
    'actual redemption' => ['redeemed', '¡Listo, recompensa canjeada!'],
    'ended' => ['ended', 'Esta promoción llegó a su fin.'],
    'expired entitlement' => ['expired', 'Esta recompensa ya venció.'],
    'cancelled active' => ['cancelled', 'Esta promoción fue cancelada.'],
    'cancelled scheduled' => ['cancelled_scheduled', 'Esta promoción fue cancelada.'],
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
        ->assertSee('Vuelve a entrar desde tu tarjeta en Google Wallet.')
        ->assertDontSee($business->name)->assertDontSee($pass->manual_code);

    personalPageArtifact('invalid', $response->getContent());
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

    $response = $this->get($personalPageUrl)->assertStatus(503)->assertSee('Por ahora no pudimos mostrar el detalle de tu tarjeta.')
        ->assertDontSee('private-signed-url-and-database-marker')->assertDontSee('Llevas 0')
        ->assertDontSee('Ahora no hay una promoción');

    $response->assertSee('Inténtalo nuevamente más tarde.')->assertDontSee($business->name);
    personalPageArtifact('unavailable', $response->getContent());
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
        ->assertOk()->assertSeeTextInOrder(['3', 'de 2', 'puntos'])->assertSee('<script>merchant</script>')
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
    ['2030-01-01 03:59:59.999999+00', 'Empiezas a sumar el'],
    ['2030-01-01 04:00:00+00', '¡Vamos por tu recompensa!'],
    ['2030-02-01 03:59:59.999999+00', '¡Vamos por tu recompensa!'],
    ['2030-02-01 04:00:00+00', 'Esta promoción llegó a su fin.'],
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
        ->assertOk()->assertSeeTextInOrder(['3', 'de 8', 'puntos']);
    if ($expectedVisitPoints > 1) {
        $response->assertSee('¡Ahora cada visita suma '.$expectedVisitPoints.' puntos!');
    } else {
        $response->assertDontSee('¡Ahora cada visita suma');
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
        ->assertOk()->assertSee('Una merienda artesanal')->assertSee('Empiezas a sumar el')->assertDontSee('Esta recompensa ya venció');
});

it('keeps optional merchant copy and visit value in the approved reading order', function (string $state, bool $description, int $multiplier) {
    [$business, $customerPass, $promotion] = personalPageFixture($state);
    $promotion->forceFill(['reward_description' => $description ? 'Preparado cada mañana.' : null])->save();
    if ($multiplier > 1) {
        $promotion->extraPoints()->create(['weekday' => 3, 'multiplier' => $multiplier]);
    }
    $factsBefore = personalPageFacts();

    $response = $this->get(URL::signedRoute('public.pass', ['businessPublicId' => $business->public_id, 'passPublicId' => $customerPass->public_id], absolute: false))->assertOk();

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $paragraphs = iterator_to_array($xpath->query('//section[@aria-labelledby="personal-status"]/p'));
    expect(trim($paragraphs[0]->textContent))->toBe('Un pan artesanal');
    if ($description) {
        expect(trim($paragraphs[1]->textContent))->toBe('Preparado cada mañana.');
    } else {
        $response->assertDontSee('Preparado cada mañana.');
    }
    if (in_array($state, ['zero', 'earning'], true)) {
        $response->assertSee($state === 'zero' ? '¡Vamos por tu recompensa!' : '¡Vas sumando!')
            ->assertSee('Tienes hasta el 31 ene 2030 para sumar.')
            ->assertSee($state === 'zero' ? 'Muestra tu tarjeta en tu próxima visita y empieza a sumar.' : 'Muestra tu tarjeta en tu próxima visita y sigue sumando.');
        $expectedParagraphs = ['Un pan artesanal'];
        if ($description) {
            $expectedParagraphs[] = 'Preparado cada mañana.';
        }
        $expectedParagraphs[] = $state === 'zero' ? '0 de 8 puntos' : '3 de 8 puntos';
        $expectedParagraphs[] = 'Tienes hasta el 31 ene 2030 para sumar.';
        if ($multiplier > 1) {
            $expectedParagraphs[] = '¡Ahora cada visita suma '.$multiplier.' puntos!';
        } else {
            $response->assertDontSee('¡Ahora cada visita suma');
        }
        $expectedParagraphs[] = $state === 'zero'
            ? 'Muestra tu tarjeta en tu próxima visita y empieza a sumar.'
            : 'Muestra tu tarjeta en tu próxima visita y sigue sumando.';
        $visibleParagraphs = array_map(static fn (DOMNode $node): string => trim(preg_replace('/\s+/u', ' ', $node->textContent)), $paragraphs);
        expect($visibleParagraphs)->toBe($expectedParagraphs);
    } elseif ($state === 'available') {
        $expectedParagraphs = ['Un pan artesanal'];
        if ($description) {
            $expectedParagraphs[] = 'Preparado cada mañana.';
        }
        $expectedParagraphs[] = 'Puedes canjearla hasta el 31 ene 2030.';
        $expectedParagraphs[] = 'Muestra tu tarjeta en el negocio para canjearla.';
        $visibleParagraphs = array_map(static fn (DOMNode $node): string => trim(preg_replace('/\s+/u', ' ', $node->textContent)), $paragraphs);
        expect($visibleParagraphs)->toBe($expectedParagraphs);
    }
    expect(personalPageFacts())->toBe($factsBefore);
    expect($this->http->history)->toBeEmpty();
    personalPageArtifact($state.'-'.($description ? 'description' : 'plain').'-'.$multiplier, $response->getContent());
})->with([
    ['scheduled', false, 1],
    ['zero', false, 1], ['zero', false, 2], ['zero', false, 3], ['zero', false, 5],
    ['zero', true, 2], ['zero', true, 3], ['zero', true, 5],
    ['earning', false, 1], ['earning', false, 2], ['earning', false, 3], ['earning', false, 5],
    ['earning', true, 2], ['earning', true, 3], ['earning', true, 5],
    ['available', false, 1], ['redeemed', false, 1], ['ended', false, 1], ['expired', false, 1], ['cancelled', false, 1],
]);

it('shows only genuine prior redemption after cancellation without a redundant cancellation sentence', function (bool $description) {
    [$business, $customerPass, $promotion] = personalPageFixture('redeemed');
    $promotion->forceFill(['status' => 'cancelled', 'cancelled_at' => '2030-01-02 10:00:00+00', 'reward_description' => $description ? 'Preparado cada mañana.' : null])->save();
    $factsBefore = personalPageFacts();

    $response = $this->get(URL::signedRoute('public.pass', ['businessPublicId' => $business->public_id, 'passPublicId' => $customerPass->public_id], absolute: false))->assertOk()
        ->assertSee('Esta promoción fue cancelada.')->assertSee('Tu recompensa fue canjeada el 1 ene 2030.')
        ->assertDontSee('El negocio canceló')->assertDontSee('La canjeaste el')->assertDontSee('Muestra tu tarjeta');

    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $paragraphs = iterator_to_array($xpath->query('//section[@aria-labelledby="personal-status"]/p'));
    $expectedParagraphs = ['Un pan artesanal'];
    if ($description) {
        $expectedParagraphs[] = 'Preparado cada mañana.';
    }
    $expectedParagraphs[] = 'Tu recompensa fue canjeada el 1 ene 2030.';
    $visibleParagraphs = array_map(static fn (DOMNode $node): string => trim(preg_replace('/\s+/u', ' ', $node->textContent)), $paragraphs);
    expect($visibleParagraphs)->toBe($expectedParagraphs);

    expect(personalPageFacts())->toBe($factsBefore);
    expect($this->http->history)->toBeEmpty();
    personalPageArtifact('cancelled-redeemed-'.($description ? 'description' : 'plain'), $response->getContent());
})->with([true, false]);

/**
 * Stores synthetic rendered HTTP output for the isolated browser checks.
 *
 * @param  string  $name  Owned fixture name without customer identifiers.
 * @param  string  $pageHtml  Rendered response containing only test-owned facts.
 */
function personalPageArtifact(string $name, string $pageHtml): void
{
    $directory = base_path('test-results/personal-pass');
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($directory.'/'.$name.'.html', $pageHtml);
}

it('preserves long merchant content and a large target without changing personal facts', function () {
    [$business, $customerPass, $promotion] = personalPageFixture('earning');
    $business->forceFill(['name' => str_repeat('Negocio', 35)])->save();
    $promotion->forceFill(['reward_title' => str_repeat('Recompensa', 22), 'target_points' => 2147483647])->save();
    $factsBefore = personalPageFacts();

    $response = $this->get(URL::signedRoute('public.pass', ['businessPublicId' => $business->public_id, 'passPublicId' => $customerPass->public_id], absolute: false))->assertOk()
        ->assertSee($business->name)->assertSee($promotion->reward_title)->assertSeeTextInOrder(['3', 'de 2147483647', 'puntos']);

    expect(personalPageFacts())->toBe($factsBefore);
    expect($this->http->history)->toBeEmpty();
    personalPageArtifact('large-facts', $response->getContent());
});
