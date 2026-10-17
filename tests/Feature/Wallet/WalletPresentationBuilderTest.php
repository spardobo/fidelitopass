<?php

use App\Data\Time\DatabaseTime;
use App\Integrations\GoogleWallet\GoogleWalletPassCreator;
use App\Models\Business;
use App\Models\CustomerPass;
use App\Models\Promotion;
use App\Support\DatabaseClock;
use App\Support\WalletPresentationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('initial issuance', function () {
    beforeEach(function () {
        app()->setLocale('es');

        $this->mock(GoogleWalletPassCreator::class)
            ->shouldReceive('create')
            ->never();
        $this->business = Business::factory()->create(['timezone' => 'America/La_Paz']);
        $this->pass = $this->business->customerPasses()->make(['manual_code' => '00123']);
        $this->mock(DatabaseClock::class)->shouldReceive('capture')->once()
            ->with('America/La_Paz')->andReturn(new DatabaseTime('2030-01-02 12:00:00+00', '2030-01-02'));
    });

    it('localizes the manual-code detail without persisting the prepared pass', function () {
        app()->setLocale('en');
        app('translator')->addLines([
            'wallet.labels.manual_code' => 'Manual code',
        ], 'en');

        $before = lifecycleFacts();
        $attributes = $this->pass->getAttributes();

        $modules = app(WalletPresentationBuilder::class)
            ->build($this->business, $this->pass);

        expect(end($modules))
            ->toBe([
                'id' => 'details_code',
                'header' => 'Manual code',
                'body' => '00123',
            ]);

        expect($this->pass->exists)
            ->toBeFalse();

        expect($this->pass->getAttributes())
            ->toBe($attributes);

        expect(app()->getLocale())
            ->toBe('en');

        expect(lifecycleFacts())
            ->toBe($before);
    });

    // business overview before a pass exists

    it('builds both business announcement slots before issuance without domain writes', function (string $status, ?string $starts, ?string $ends, ?string $previous, ?string $upcoming) {
        lifecyclePromotion($this->business, [
            'status' => $status,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'cancelled_at' => $status === 'cancelled' ? '2030-01-02 11:00:00+00' : null,
            ...($status === 'draft' ? ['timezone_snapshot' => null, 'local_start_date' => '2030-01-03', 'local_end_date' => '2030-01-04'] : []),
        ]);
        lifecyclePromotion(Business::factory()->create(), ['reward_title' => 'Foreign reward']);
        $before = lifecycleFacts();
        $expected = [
            ['id' => 'card_primary', 'header' => 'NUESTRA ÚLTIMA PROMOCIÓN', 'body' => $previous ?? "¡Qué bueno tenerte por aquí!\nAún no hay promociones anteriores."],
            ['id' => 'card_secondary', 'header' => 'LO PRÓXIMO PARA TI', 'body' => $upcoming ?? "Aún no hay una promoción programada.\nCuando haya novedades, las verás aquí."],
        ];

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);
        $sections = array_values(array_filter($content, static fn (array $section): bool => str_starts_with($section['id'], 'card_')));

        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBeNull();
        expect($sections)->toBe($expected);
        expect(end($content))->toBe([
            'id' => 'details_code',
            'header' => 'CÓDIGO MANUAL',
            'body' => '00123',
        ]);
        expect($this->pass->exists)->toBeFalse();
        expect(json_encode($sections, JSON_UNESCAPED_UNICODE))->not->toContain('Foreign reward', 'Todavía ninguna');
        expect(lifecycleFacts())->toBe($before);
        expect(DB::table('customer_passes')->count())->toBe(0);
    })->with([
        'scheduled' => ['published', '2030-01-03 00:00:00+00', '2030-01-05 00:00:00+00', null, "Un pan artesanal\nEmpieza el 2 ene 2030"],
        'ended at exact instant' => ['published', '2030-01-01 00:00:00+00', '2030-01-02 12:00:00+00', "Un pan artesanal\nTerminó el 1 ene 2030", null],
        'draft' => ['draft', null, null, null, null],
        'cancelled' => ['cancelled', '2030-01-01 00:00:00+00', '2030-01-03 00:00:00+00', "Un pan artesanal\nSe canceló el 2 ene 2030", null],
    ]);

    // zero progress, visit value and real reward content

    it('maps zero initial progress actual reward inclusive deadline and promotion timezone visit value', function (?string $startTime, ?string $endTime, int $multiplier, int $expectedPoints) {
        $promotion = lifecyclePromotion($this->business, [
            'starts_at' => '2030-01-02 12:00:00+00',
            'ends_at' => '2030-01-05 05:00:00+00',
            'timezone_snapshot' => 'America/Bogota',
        ]);
        $promotion->extraPoints()->create([
            'weekday' => 3,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'multiplier' => $multiplier,
        ]);
        $before = lifecycleFacts();

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);
        $sections = collect($content)->keyBy('id');

        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBe('0 / 8 puntos');
        expect($sections->keys()->all())->toBe(['card_primary', 'details_primary', 'details_primary_date', 'details_context', 'barcode_top', 'details_code']);
        expect($sections['details_primary']['header'])->toBe('TU PRÓXIMA RECOMPENSA');
        expect($sections['details_primary']['body'])->toBe('Un pan artesanal');
        expect($sections['details_primary_date']['body'])->toBe('Tienes hasta el 4 ene 2030.');
        expect($sections['details_context']['header'])->toBe('');
        expect($sections['details_context']['body'])->toBe($expectedPoints > 1
            ? "⚡ Ahora tu visita vale {$expectedPoints} puntos."
            : 'Tu visita ahora vale 1 punto.');
        expect($sections['card_primary']['body'])->toBe(
            "Un pan artesanal\nTienes hasta el 4 ene 2030.\n\n".($expectedPoints > 1
                ? "⚡ Ahora tu visita vale {$expectedPoints} puntos."
                : 'Tu visita ahora vale 1 punto.'),
        );
        expect(json_encode($content, JSON_UNESCAPED_UNICODE))->not->toContain('Consigue');
        expect(lifecycleFacts())->toBe($before);
    })->with([
        'window start inclusive' => ['07:00:00', '08:00:00', 3, 3],
        'window end exclusive' => ['06:00:00', '07:00:00', 3, 1],
        'outside window' => ['08:00:00', '09:00:00', 3, 1],
        'whole-day double points' => [null, null, 2, 2],
        'whole-day five points' => [null, null, 5, 5],
    ]);

    it('separates the optional real Reward description without inventing absent content', function (?string $description) {
        lifecyclePromotion($this->business, ['reward_description' => $description]);

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);
        $reward = collect($content)->firstWhere('id', 'details_primary');

        expect($reward['header'])->toBe('TU PRÓXIMA RECOMPENSA');
        expect($reward['body'])->toBe('Un pan artesanal');
        $descriptionSection = collect($content)->firstWhere('id', 'details_primary_description');
        if ($description === null || $description === '') {
            expect($descriptionSection)->toBeNull();
        } else {
            expect($descriptionSection['header'])->toBe('QUÉ INCLUYE');
            expect($descriptionSection['body'])->toBe($description);
        }
        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBe('0 / 8 puntos');
        $compact = collect($content)->firstWhere('id', 'card_primary');
        expect($compact['header'])->toBe('TU PRÓXIMA RECOMPENSA');
        expect($compact['body'])->toBe("Un pan artesanal\nTienes hasta el 4 ene 2030.\n\nTu visita ahora vale 1 punto.");
    })->with([
        'real description' => ['Pan integral de masa madre.'],
        'absent description' => [null],
        'empty description' => [''],
    ]);
});

describe('existing pass', function () {
    beforeEach(function () {
        app()->setLocale('es');

        $this->business = Business::factory()->create(['timezone' => 'America/La_Paz']);
        $this->pass = CustomerPass::factory()->for($this->business)->create();
        $this->instant = '2030-01-02 12:00:00+00';

        $this->mock(DatabaseClock::class)
            ->shouldReceive('capture')
            ->atMost()->once()
            ->with('America/La_Paz')
            ->andReturnUsing(fn () => new DatabaseTime($this->instant, '2030-01-02'));
        $this->mock(GoogleWalletPassCreator::class)
            ->shouldReceive('create')
            ->never();
    });

    // active reward states and immutable progress

    it('shows only relevant active content while preserving earned and redeemed facts', function (string $state) {
        $promotion = lifecyclePromotion($this->business);
        lifecycleActivity(
            $this->pass,
            $promotion,
            points: $state === 'progress' ? 3 : 8,
            unlocked: $state !== 'progress',
            redeemed: $state === 'redeemed',
        );
        $before = lifecycleFacts();
        [$heading, $date, $context] = match ($state) {
            'available' => ['🎉 ¡LO LOGRASTE!', 'Canjea tu recompensa hasta el 4 ene 2030.', 'Muéstranos tu tarjeta y pide tu recompensa.'],
            'redeemed' => ['✅ RECOMPENSA CANJEADA', 'Canjeada el 2 ene 2030', '¡Gracias por volver!'],
            default => ['TU PRÓXIMA RECOMPENSA', 'Tienes hasta el 4 ene 2030.', 'Tu visita ahora vale 1 punto.'],
        };

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);
        $sections = collect($content)->keyBy('id');

        expect($sections->keys()->all())->toBe([
            'card_primary',
            'details_primary',
            'details_primary_date',
            'details_context',
            ...($state === 'redeemed' ? ['details_history'] : []),
            ...($state === 'progress' ? ['barcode_top'] : []),
            'details_code',
        ]);
        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBe($state === 'progress' ? '3 / 8 puntos' : null);
        expect($sections['details_primary']['header'])->toBe($heading);
        expect($sections['details_primary']['body'])->toBe('Un pan artesanal');
        expect($sections['details_primary_date']['header'])->toBe($state === 'redeemed' ? 'FECHA DEL CANJE' : 'FECHA LÍMITE');
        expect($sections['details_primary_date']['body'])->toBe($date);
        expect($sections['card_primary']['header'])->toBe($heading);
        expect($sections['card_primary']['body'])->toBe("Un pan artesanal\n{$date}\n\n{$context}");
        expect($sections['details_context']['header'])->toBe('');
        expect($sections['details_context']['body'])->toBe($context);

        if ($state === 'redeemed') {
            expect($sections['details_history']['header'])->toBe('TU ÚLTIMO CANJE');
            expect($sections['details_history']['body'])->toBe("Un pan artesanal\nCanjeada el 2 ene 2030");
        }

        expect(lifecycleFacts())->toBe($before);
    })->with(['progress', 'available', 'redeemed']);

    it('hides completed progress without rewriting immutable points beyond the target', function () {
        lifecyclePromotion($this->business, [
            'ends_at' => '2030-01-01 04:00:00+00',
            'starts_at' => '2029-12-30 04:00:00+00',
            'reward_title' => 'Past announcement',
        ]);
        $active = lifecyclePromotion($this->business);
        lifecycleActivity($this->pass, $active, 10, true);
        lifecyclePromotion($this->business, [
            'starts_at' => '2030-01-06 04:00:00+00',
            'ends_at' => '2030-01-08 04:00:00+00',
            'reward_title' => 'Future announcement',
        ]);
        $before = lifecycleFacts();

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);

        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBeNull();
        expect(DB::table('visits')->sum('awarded_points'))->toBe(10);
        expect(json_encode($content, JSON_UNESCAPED_UNICODE))->not->toContain('Past announcement', 'Future announcement');
        expect(lifecycleFacts())->toBe($before);
    });

    // personal redemption history across promotions

    it('retains the latest personal redemption in its frozen timezone when a new promotion starts', function () {
        $old = lifecyclePromotion($this->business, [
            'ends_at' => '2030-01-02 00:00:00+00',
            'reward_title' => 'Previous reward',
        ]);
        lifecycleActivity($this->pass, $old, 8, true, true, '2030-01-02 01:00:00+00');
        $foreignPass = CustomerPass::factory()->for($this->business)->create();
        lifecycleActivity($foreignPass, $old, 8, true, true, '2030-01-02 02:00:00+00');
        $active = lifecyclePromotion($this->business, [
            'starts_at' => '2030-01-02 00:00:00+00',
            'reward_title' => 'New reward',
        ]);

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);
        $sections = collect($content)->keyBy('id');

        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBe('0 / 8 puntos')
            ->and($sections['details_primary']['body'])->toBe('New reward')
            ->and($sections['details_history']['body'])->toBe("Previous reward\nCanjeada el 1 ene 2030")
            ->and(DB::table('promotion_participations')->where('promotion_id', $active->id)->count())->toBe(0);
    });

    it('keeps genuine personal redemption separate from business announcements and unredeemed entitlements', function () {
        $old = lifecyclePromotion($this->business, [
            'ends_at' => '2030-01-02 04:00:00+00',
            'reward_title' => 'My redeemed reward',
        ]);
        lifecycleActivity($this->pass, $old, 8, true, true, '2030-01-02 01:00:00+00');
        $previous = lifecyclePromotion($this->business, [
            'status' => 'cancelled',
            'starts_at' => '2030-01-02 04:00:00+00',
            'cancelled_at' => '2030-01-02 10:00:00+00',
            'reward_title' => 'More recent business reward',
        ]);
        lifecycleActivity($this->pass, $previous, 8, true);
        $otherPass = CustomerPass::factory()->for($this->business)->create();
        lifecycleActivity($otherPass, $previous, 8, true, true, '2030-01-02 09:00:00+00');
        $before = lifecycleFacts();

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);

        expect($content[0]['body'])->toBe("More recent business reward\nSe canceló el 2 ene 2030");
        expect(collect($content)->firstWhere('id', 'details_history')['body'])
            ->toBe("My redeemed reward\nCanjeada el 1 ene 2030");
        expect(lifecycleFacts())->toBe($before);
    });

    // business overview and promotion selection

    it('shows both business announcement slots without requiring participation', function (bool $previous, bool $upcoming) {
        if ($previous) {
            lifecyclePromotion($this->business, [
                'ends_at' => '2030-01-02 04:00:00+00',
                'reward_title' => 'Previous business reward',
            ]);
        }

        if ($upcoming) {
            lifecyclePromotion($this->business, [
                'starts_at' => '2030-01-03 04:00:00+00',
                'reward_title' => 'Upcoming business reward',
            ]);
        }

        $foreign = Business::factory()->create();
        lifecyclePromotion($foreign, ['ends_at' => '2030-01-02 05:00:00+00', 'reward_title' => 'Foreign previous']);
        lifecyclePromotion($foreign, ['starts_at' => '2030-01-03 00:00:00+00', 'reward_title' => 'Foreign future']);
        $before = lifecycleFacts();
        $expected = [
            ['id' => 'card_primary', 'header' => 'NUESTRA ÚLTIMA PROMOCIÓN', 'body' => $previous ? "Previous business reward\nTerminó el 1 ene 2030" : "¡Qué bueno tenerte por aquí!\nAún no hay promociones anteriores."],
            ['id' => 'card_secondary', 'header' => 'LO PRÓXIMO PARA TI', 'body' => $upcoming ? "Upcoming business reward\nEmpieza el 3 ene 2030" : "Aún no hay una promoción programada.\nCuando haya novedades, las verás aquí."],
        ];

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);
        $sections = array_values(array_filter($content, static fn (array $section): bool => str_starts_with($section['id'], 'card_')));

        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBeNull();
        expect($sections)->toBe($expected);
        expect(lifecycleFacts())->toBe($before);
    })->with([
        'both' => [true, true],
        'previous only' => [true, false],
        'upcoming only' => [false, true],
        'neither' => [false, false],
    ]);

    it('distinguishes early cancellation from natural end and excludes cancellations without an active period', function (string $cancelledAt, ?string $expectedDate) {
        lifecyclePromotion($this->business, [
            'status' => 'cancelled',
            'starts_at' => '2030-01-01 04:00:00+00',
            'ends_at' => '2030-01-02 04:00:00+00',
            'cancelled_at' => $cancelledAt,
        ]);
        $before = lifecycleFacts();

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);

        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBeNull();
        expect($content[0]['body'])->toBe($expectedDate === null
            ? "¡Qué bueno tenerte por aquí!\nAún no hay promociones anteriores."
            : "Un pan artesanal\n".$expectedDate);
        expect(lifecycleFacts())->toBe($before);
    })->with([
        'before start' => ['2030-01-01 03:59:59+00', null],
        'at start' => ['2030-01-01 04:00:00+00', null],
        'during activity' => ['2030-01-02 01:00:00+00', 'Se canceló el 1 ene 2030'],
        'at natural end' => ['2030-01-02 04:00:00+00', 'Terminó el 1 ene 2030'],
        'after natural end' => ['2030-01-02 05:00:00+00', 'Terminó el 1 ene 2030'],
    ]);

    it('selects the latest effective end rather than the longest original window and excludes draft and cancelled announcements', function () {
        lifecyclePromotion($this->business, [
            'status' => 'cancelled',
            'ends_at' => '2030-02-01 04:00:00+00',
            'cancelled_at' => '2030-01-01 12:00:00+00',
            'reward_title' => 'Earlier cancellation',
        ]);
        lifecyclePromotion($this->business, [
            'ends_at' => '2030-01-02 04:00:00+00',
            'reward_title' => 'Latest effective end',
        ]);
        lifecyclePromotion($this->business, [
            'status' => 'cancelled',
            'starts_at' => '2030-01-03 04:00:00+00',
            'cancelled_at' => '2030-01-02 11:00:00+00',
            'reward_title' => 'Cancelled future',
        ]);
        lifecyclePromotion($this->business, [
            'status' => 'draft',
            'starts_at' => null,
            'ends_at' => null,
            'timezone_snapshot' => null,
            'local_start_date' => '2030-01-03',
            'local_end_date' => '2030-01-04',
            'reward_title' => 'Private draft',
        ]);
        lifecyclePromotion($this->business, [
            'starts_at' => '2030-01-04 04:00:00+00',
            'reward_title' => 'Nearest announced',
        ]);
        lifecyclePromotion($this->business, [
            'starts_at' => '2030-01-05 04:00:00+00',
            'ends_at' => '2030-01-07 04:00:00+00',
            'reward_title' => 'Later announced',
        ]);

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);

        expect($content[0]['body'])->toBe("Latest effective end\nTerminó el 1 ene 2030");
        expect($content[1]['body'])->toBe("Nearest announced\nEmpieza el 4 ene 2030");
        expect(DB::table('promotion_participations')->count())->toBe(0);
    });

    // publication boundaries and local calendar dates

    it('switches from future to current to previous at the exact publication boundaries', function (string $instant, ?string $progress, string $primary, string $secondary) {
        $this->instant = $instant;
        lifecyclePromotion($this->business, [
            'starts_at' => '2030-01-02 04:00:00+00',
            'ends_at' => '2030-01-03 04:00:00+00',
        ]);

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);
        $sections = collect($content)->keyBy('id');

        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBe($progress);
        expect($content[0]['header'])->toBe($primary);
        expect($sections->has('card_secondary'))->toBe($secondary !== '');

        if ($secondary !== '') {
            expect($sections['card_secondary']['body'])->toBe($secondary);
        }
    })->with([
        'before start' => ['2030-01-02 03:59:59.999999+00', null, 'NUESTRA ÚLTIMA PROMOCIÓN', "Un pan artesanal\nEmpieza el 2 ene 2030"],
        'start inclusive' => ['2030-01-02 04:00:00+00', '0 / 8 puntos', 'TU PRÓXIMA RECOMPENSA', ''],
        'end exclusive' => ['2030-01-03 04:00:00+00', null, 'NUESTRA ÚLTIMA PROMOCIÓN', "Aún no hay una promoción programada.\nCuando haya novedades, las verás aquí."],
    ]);

    it('derives the final date after frozen-zone conversion across a DST day', function (bool $active) {
        $this->instant = $active ? '2030-03-10 12:00:00+00' : '2030-03-11 04:00:00+00';
        lifecyclePromotion($this->business, [
            'starts_at' => '2030-03-09 05:00:00+00',
            'ends_at' => '2030-03-11 04:00:00+00',
            'timezone_snapshot' => 'America/New_York',
        ]);

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);
        $sections = collect($content)->keyBy('id');

        expect($sections['card_primary']['body'])->toContain($active ? 'Tienes hasta el 10 mar 2030.' : 'Terminó el 10 mar 2030');
    })->with(['active' => true, 'ended' => false]);

    // active locale, translation fallback and regional formatting

    it('uses the active Laravel catalog without changing the callers locale or translating merchant content', function (bool $active) {
        app()->setLocale('en');
        lifecyclePromotion($this->business, [
            'reward_title' => 'wallet.active.title',
            'ends_at' => $active ? '2030-01-05 04:00:00+00' : '2030-01-02 04:00:00+00',
        ]);
        app('translator')->addLines([
            'wallet.active.title' => 'Active catalog title',
            'wallet.previous.title' => 'Previous catalog title',
        ], 'en');

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);
        $sections = collect($content)->keyBy('id');

        expect($sections['card_primary']['header'])->toBe($active ? 'Active catalog title' : 'Previous catalog title');
        expect($sections['card_primary']['body'])->toContain('wallet.active.title');
        expect($sections->has('details_history'))->toBeFalse();
        expect(app()->getLocale())->toBe('en');
    })->with(['active' => true, 'inactive' => false]);

    it('falls back to Spanish for an unshipped English locale in each active reward state', function (string $state) {
        app()->setLocale('en');
        $promotion = lifecyclePromotion($this->business, [
            'reward_title' => 'Un pan artesanal',
            'reward_description' => 'Contenido del negocio sin traducir.',
        ]);
        lifecycleActivity($this->pass, $promotion, $state === 'progress' ? 3 : 8, $state !== 'progress', $state === 'redeemed');
        $before = lifecycleFacts();
        [$heading, $date, $context] = match ($state) {
            'available' => ['🎉 ¡LO LOGRASTE!', 'Canjea tu recompensa hasta el Jan 4, 2030.', 'Muéstranos tu tarjeta y pide tu recompensa.'],
            'redeemed' => ['✅ RECOMPENSA CANJEADA', 'Canjeada el Jan 2, 2030', '¡Gracias por volver!'],
            default => ['TU PRÓXIMA RECOMPENSA', 'Tienes hasta el Jan 4, 2030.', 'Tu visita ahora vale 1 punto.'],
        };

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);
        $sections = collect($content)->keyBy('id');

        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBe($state === 'progress' ? '3 / 8 puntos' : null);
        expect($sections['details_primary']['header'])->toBe($heading);
        expect($sections['details_primary']['body'])->toBe('Un pan artesanal');
        expect($sections['details_primary_description']['header'])->toBe('QUÉ INCLUYE');
        expect($sections['details_primary_description']['body'])->toBe('Contenido del negocio sin traducir.');
        expect($sections['details_primary_date']['header'])->toBe($state === 'redeemed' ? 'FECHA DEL CANJE' : 'FECHA LÍMITE');
        expect($sections['details_primary_date']['body'])->toBe($date);
        expect($sections['card_primary']['header'])->toBe($heading);
        expect($sections['card_primary']['body'])->toBe("Un pan artesanal\n{$date}\n\n{$context}");
        expect($sections['details_context']['header'])->toBe('');
        expect($sections['details_context']['body'])->toBe($context);
        expect($sections->has('details_history'))->toBe($state === 'redeemed');

        if ($state === 'redeemed') {
            expect($sections['details_history']['body'])->toBe("Un pan artesanal\nCanjeada el Jan 2, 2030");
        }

        expect($sections->has('details_context'))->toBeTrue();
        expect($sections->has('details_tertiary'))->toBeFalse();
        expect(app()->getLocale())->toBe('en');
        expect(lifecycleFacts())->toBe($before);
    })->with(['progress', 'available', 'redeemed']);

    it('falls back to Spanish with regional dates for previous upcoming and personal history', function (bool $cancelled) {
        app()->setLocale('en');
        $previous = lifecyclePromotion($this->business, [
            'status' => $cancelled ? 'cancelled' : 'published',
            'ends_at' => '2030-01-02 04:00:00+00',
            'cancelled_at' => $cancelled ? '2030-01-02 01:30:00+00' : null,
            'reward_title' => 'Recompensa anterior',
        ]);
        lifecycleActivity($this->pass, $previous, 8, true, true, '2030-01-02 01:00:00+00');
        lifecyclePromotion($this->business, [
            'starts_at' => '2030-01-03 04:00:00+00',
            'reward_title' => 'Próxima recompensa',
        ]);
        $before = lifecycleFacts();

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);

        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBeNull();
        expect(collect($content)->keyBy('id')->only(['card_primary', 'card_secondary', 'details_history'])->pluck('header')->all())->toBe([
            'NUESTRA ÚLTIMA PROMOCIÓN',
            'LO PRÓXIMO PARA TI',
            'TU ÚLTIMO CANJE',
        ]);
        expect(collect($content)->keyBy('id')->only(['card_primary', 'card_secondary', 'details_history'])->pluck('body')->all())->toBe([
            "Recompensa anterior\n".($cancelled ? 'Se canceló el Jan 1, 2030' : 'Terminó el Jan 1, 2030'),
            "Próxima recompensa\nEmpieza el Jan 3, 2030",
            "Recompensa anterior\nCanjeada el Jan 1, 2030",
        ]);
        expect(app()->getLocale())->toBe('en');
        expect(lifecycleFacts())->toBe($before);
    })->with(['ended' => false, 'cancelled' => true]);

    it('lets Laravel choose the configured translation fallback without changing the active locale', function () {
        app()->setLocale('fr');
        app('translator')->setFallback('es');
        $before = lifecycleFacts();

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);

        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBeNull();
        expect($content)->toHaveCount(3);
        expect($content[0]['id'])->toBe('card_primary');
        expect($content[0]['header'])->toBe('NUESTRA ÚLTIMA PROMOCIÓN');
        expect($content[0]['body'])->toBe("¡Qué bueno tenerte por aquí!\nAún no hay promociones anteriores.");
        expect(app()->getLocale())->toBe('fr');
        expect(lifecycleFacts())->toBe($before);
    });

    it('formats active and ended deadlines in the current region without changing the promotion date', function (
        string $locale,
        string $expectedDate,
        bool $active,
    ) {
        app()->setLocale($locale);
        $this->instant = $active ? '2030-08-20 12:00:00+00' : '2030-08-21 04:00:00+00';
        lifecyclePromotion($this->business, [
            'starts_at' => '2030-08-19 04:00:00+00',
            'ends_at' => '2030-08-21 04:00:00+00',
            'reward_title' => 'Merchant-authored reward',
        ]);
        $before = lifecycleFacts();

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);
        $sections = collect($content)->keyBy('id');
        $dateLine = $active
            ? $sections['details_primary_date']['body']
            : explode("\n", $sections['card_primary']['body'])[1];

        expect($dateLine)->toBe(($active ? 'Tienes hasta el ' : 'Terminó el ').$expectedDate.($active ? '.' : ''));
        expect($sections['card_primary']['body'])->toStartWith("Merchant-authored reward\n");
        expect(app()->getLocale())->toBe($locale);
        expect(lifecycleFacts())->toBe($before);
    })->with([
        'Spanish' => ['es', '20 ago 2030'],
        'English' => ['en', 'Aug 20, 2030'],
        'British English' => ['en_GB', '20 Aug 2030'],
        'French' => ['fr', '20 août 2030'],
        'German' => ['de', '20.08.2030'],
        'Japanese' => ['ja', '2030/08/20'],
    ])->with(['active' => true, 'ended' => false]);

    it('separates both announcement descriptions and dates before genuine history within the object text budget', function (?string $previousDescription, ?string $upcomingDescription) {
        app()->setLocale('en');
        app('translator')->addLines(['wallet.labels.description' => 'INCLUDED ITEMS'], 'en');
        $previous = lifecyclePromotion($this->business, [
            'reward_title' => 'Capuchino de la casa',
            'reward_description' => $previousDescription,
            'ends_at' => '2030-01-02 04:00:00+00',
        ]);
        lifecycleActivity($this->pass, $previous, 8, true, true, '2030-01-02 01:00:00+00');
        lifecyclePromotion($this->business, [
            'reward_title' => 'Desayuno para dos',
            'reward_description' => $upcomingDescription,
            'starts_at' => '2030-01-03 04:00:00+00',
        ]);
        $before = lifecycleFacts();

        $content = app(WalletPresentationBuilder::class)->build($this->business, $this->pass);
        $sections = collect($content)->keyBy('id');

        expect((collect($content)->firstWhere('id', 'barcode_top')['body'] ?? null))->toBeNull();
        expect($sections->keys()->all())->toBe([
            'card_primary', 'card_secondary',
            'details_primary',
            ...($previousDescription === null || $previousDescription === '' ? [] : ['details_primary_description']),
            'details_primary_date',
            'details_secondary',
            ...($upcomingDescription === null || $upcomingDescription === '' ? [] : ['details_secondary_description']),
            'details_secondary_date', 'details_history', 'details_code',
        ]);
        expect($sections['details_primary']['body'])->toBe('Capuchino de la casa');
        expect($sections['details_secondary']['body'])->toBe('Desayuno para dos');
        expect($sections['details_primary_date']['header'])->toBe('FINALIZACIÓN');
        expect($sections['details_primary_date']['body'])->toBe('Terminó el Jan 1, 2030');
        expect($sections['details_secondary_date']['header'])->toBe('FECHA DE INICIO');
        expect($sections['details_secondary_date']['body'])->toBe('Empieza el Jan 3, 2030');
        expect($sections['details_history']['body'])->toBe("Capuchino de la casa\nCanjeada el Jan 1, 2030");
        foreach (['primary' => $previousDescription, 'secondary' => $upcomingDescription] as $position => $description) {
            $section = $sections->get('details_'.$position.'_description');
            if ($description === null || $description === '') {
                expect($section)->toBeNull();
            } else {
                expect($section['header'])->toBe('INCLUDED ITEMS');
                expect($section['body'])->toBe($description);
            }
        }
        expect($sections->has('details_context'))->toBeFalse();
        expect($sections->count())->toBeLessThanOrEqual(10);
        expect(app()->getLocale())->toBe('en');
        expect(lifecycleFacts())->toBe($before);
    })->with([
        'merchant description' => ['Texto original del negocio.', 'Texto original del negocio.'],
        'absent description' => [null, null],
        'empty description' => ['', ''],
        'previous absent' => [null, 'Tostada y Té.'],
        'upcoming empty' => ["Pan Mixto.\nSin azúcar.", ''],
        'zero is merchant text' => ['', '0'],
    ]);

    // pass ownership isolation

    it('rejects an invalid pass before reading its presentation', function (bool $persisted) {
        $foreignPass = $persisted
            ? CustomerPass::factory()->create()
            : CustomerPass::factory()->for(Business::factory()->create())->make();
        $before = lifecycleFacts();

        expect(fn () => app(WalletPresentationBuilder::class)->build($this->business, $foreignPass))
            ->toThrow(InvalidArgumentException::class)
            ->and(lifecycleFacts())->toBe($before);
    })->with(['foreign persisted pass' => true, 'foreign unsaved pass' => false]);
});

describe('shared operation instant', function () {
    it('uses the supplied instant for promotion selection and frozen-zone content without capturing another clock', function (string $operationAt, string $heading, string $context) {
        app()->setLocale('es');
        $business = Business::factory()->create(['timezone' => 'America/La_Paz']);
        $pass = CustomerPass::factory()->for($business)->create();
        $promotion = lifecyclePromotion($business, [
            'starts_at' => '2030-01-02 12:00:00+00',
            'ends_at' => '2030-01-03 05:00:00+00',
            'timezone_snapshot' => 'America/Bogota',
        ]);
        $promotion->extraPoints()->create([
            'weekday' => 3,
            'start_time' => '07:00:00',
            'end_time' => '08:00:00',
            'multiplier' => 3,
        ]);
        lifecycleActivity($pass, $promotion, 3);
        $before = lifecycleFacts();
        $this->mock(DatabaseClock::class)
            ->shouldReceive('capture')
            ->never()
            ->andReturn(new DatabaseTime('2030-01-03 05:00:00+00', '2030-01-03'));

        $content = app(WalletPresentationBuilder::class)->build($business, $pass, $operationAt);
        $sections = collect($content)->keyBy('id');

        expect($sections['card_primary']['header'])->toBe($heading);
        expect($sections['details_context']['body'] ?? null)->toBe($context === '' ? null : $context);
        expect($sections['barcode_top']['body'] ?? null)->toBe($context === '' ? null : '3 / 8 puntos');
        expect(lifecycleFacts())->toBe($before);
    })->with([
        'before publication start' => ['2030-01-02 11:59:59.999999+00', 'NUESTRA ÚLTIMA PROMOCIÓN', ''],
        'publication and multiplier start inclusive' => ['2030-01-02 12:00:00+00', 'TU PRÓXIMA RECOMPENSA', '⚡ Ahora tu visita vale 3 puntos.'],
        'last multiplier microsecond' => ['2030-01-02 12:59:59.999999+00', 'TU PRÓXIMA RECOMPENSA', '⚡ Ahora tu visita vale 3 puntos.'],
        'multiplier end exclusive' => ['2030-01-02 13:00:00+00', 'TU PRÓXIMA RECOMPENSA', 'Tu visita ahora vale 1 punto.'],
        'publication end exclusive' => ['2030-01-03 05:00:00+00', 'NUESTRA ÚLTIMA PROMOCIÓN', ''],
    ]);

    it('captures the current clock once when the operation instant is omitted or explicitly null', function (bool $explicitNull) {
        app()->setLocale('es');
        $business = Business::factory()->create(['timezone' => 'America/La_Paz']);
        $pass = $business->customerPasses()->make(['manual_code' => '00123']);
        lifecyclePromotion($business, ['starts_at' => '2030-01-02 12:00:00+00']);
        $this->mock(DatabaseClock::class)
            ->shouldReceive('capture')
            ->once()
            ->with('America/La_Paz')
            ->andReturn(new DatabaseTime('2030-01-02 11:59:59.999999+00', '2030-01-02'));
        $before = lifecycleFacts();
        $builder = app(WalletPresentationBuilder::class);

        $content = $explicitNull
            ? $builder->build($business, $pass, null)
            : $builder->build($business, $pass);
        $sections = collect($content)->keyBy('id');

        expect($sections['card_primary']['header'])->toBe('NUESTRA ÚLTIMA PROMOCIÓN');
        expect($sections['card_secondary']['body'])->toBe("Un pan artesanal\nEmpieza el 2 ene 2030");
        expect($sections->has('barcode_top'))->toBeFalse();
        expect($sections['details_code']['body'])->toBe('00123');
        expect($pass->exists)->toBeFalse();
        expect(lifecycleFacts())->toBe($before);
    })->with(['two arguments' => false, 'explicit null' => true]);
});

/**
 * Persists frozen same-Business Promotion fixtures without publication side effects.
 *
 * @param  Business  $business  Fixture owner.
 * @param  array<string, mixed>  $overrides  State and frozen terms under test.
 * @return Promotion Persisted fixture.
 */
function lifecyclePromotion(Business $business, array $overrides = []): Promotion
{
    $promotion = $business->promotions()->make();
    $promotion->forceFill([
        'status' => 'published',
        'target_points' => 8,
        'reward_title' => 'Un pan artesanal',
        'timezone_snapshot' => 'America/La_Paz',
        'starts_at' => '2030-01-01 04:00:00+00',
        'ends_at' => '2030-01-05 04:00:00+00',
        ...$overrides,
    ])->save();

    return $promotion;
}

/**
 * Inserts historical facts for a single pass/Promotion without invoking future domain commands.
 *
 * @param  CustomerPass  $pass  Fixture identity.
 * @param  Promotion  $promotion  Same-Business frozen terms.
 * @param  int  $points  Immutable awarded total.
 * @param  bool  $unlocked  Whether an entitlement exists.
 * @param  bool  $redeemed  Whether final redemption exists.
 * @param  string  $redeemedAt  Controlled redemption instant.
 */
function lifecycleActivity(
    CustomerPass $pass,
    Promotion $promotion,
    int $points,
    bool $unlocked = false,
    bool $redeemed = false,
    string $redeemedAt = '2030-01-02 11:00:00+00',
): void {
    $ownership = [
        'business_id' => $pass->business_id,
        'customer_pass_id' => $pass->id,
        'promotion_id' => $promotion->id,
    ];

    DB::table('promotion_participations')->insert($ownership);
    DB::table('visits')->insert($ownership + [
        'operation_id' => (string) Str::uuid(),
        'confirmed_by_user_id' => $pass->business->user_id,
        'confirmed_at' => '2030-01-01 12:00:00+00',
        'awarded_points' => $points,
    ]);

    if ($unlocked) {
        DB::table('reward_entitlements')->insert($ownership + [
            'unlocked_at' => '2030-01-01 12:00:00+00',
            'redeemed_at' => $redeemed ? $redeemedAt : null,
            'redeemed_by_user_id' => $redeemed ? $pass->business->user_id : null,
        ]);
    }
}

/**
 * Captures complete domain rows to detect unintended presentation writes.
 *
 * @return array<string, array<int, array<string, mixed>>> Facts before or after building content.
 */
function lifecycleFacts(): array
{
    $facts = [];

    foreach ([
        'businesses',
        'promotions',
        'promotion_multiplier_windows',
        'customer_passes',
        'promotion_participations',
        'visits',
        'reward_entitlements',
    ] as $table) {
        $facts[$table] = DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    return $facts;
}
