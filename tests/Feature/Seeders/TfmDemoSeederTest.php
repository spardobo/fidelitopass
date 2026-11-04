<?php

use App\Data\Time\DatabaseTime;
use App\Enums\PromotionStatus;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\User;
use App\Support\DatabaseClock;
use Carbon\CarbonImmutable;
use Database\Seeders\TfmDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('seeds only the main MoureDev account and Brais & Beans with their sample history', function () {
    config(['tfm.review_password' => 'fixture-only-password']);
    $this->mock(DatabaseClock::class)
        ->shouldReceive('capture')
        ->once()
        ->with('Europe/Madrid')
        ->andReturn(new DatabaseTime('2026-11-03 23:30:00+00', '2026-11-04'));

    $this->seed(TfmDemoSeeder::class);

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('businesses', 1);
    $this->assertDatabaseHas('users', ['name' => 'MoureDev', 'email' => 'mouredev@fidelito.com']);
    $this->assertDatabaseMissing('users', ['email' => 'mouredev-zero@fidelito.com']);
    $this->assertDatabaseMissing('users', ['email' => 'mouredev-zero@gmail.com']);
    $this->assertDatabaseMissing('businesses', ['name' => 'MoureDev Café Zero']);

    $business = Business::query()->sole();
    $this->assertDatabaseHas('businesses', [
        'id' => $business->id,
        'user_id' => User::query()->sole()->id,
        'name' => 'Brais & Beans',
        'pass_background_color' => '#009beb',
    ]);
    $this->assertDatabaseCount('customer_passes', 4);
    foreach (['promotions', 'customer_passes', 'visits', 'reward_entitlements'] as $table) {
        expect(DB::table($table)->where('business_id', $business->id)->count())->toBeGreaterThan(0);
        expect(DB::table($table)->where('business_id', '!=', $business->id)->count())->toBe(0);
    }
    $this->assertDatabaseHas('promotions', ['business_id' => $business->id, 'status' => 'draft']);
    $this->assertDatabaseHas('promotions', ['business_id' => $business->id, 'status' => 'cancelled']);
});

it('keeps exactly one published offer active with consecutive local-calendar windows through the coverage horizon', function (
    string $localDate,
    string $capturedAt,
    string $requiredEnd,
) {
    config(['tfm.review_password' => 'fixture-only-password']);
    $this->mock(DatabaseClock::class)
        ->shouldReceive('capture')
        ->once()
        ->with('Europe/Madrid')
        ->andReturn(new DatabaseTime($capturedAt, $localDate));

    $this->seed(TfmDemoSeeder::class);

    $business = Business::query()->where('name', 'Brais & Beans')->sole();
    $published = Promotion::query()
        ->where('business_id', $business->id)
        ->where('status', PromotionStatus::Published)
        ->where('ends_at', '>', $capturedAt)
        ->orderBy('starts_at')
        ->get();
    $active = Promotion::query()
        ->where('business_id', $business->id)
        ->where('status', PromotionStatus::Published)
        ->where('starts_at', '<=', $capturedAt)
        ->where('ends_at', '>', $capturedAt)
        ->sole();

    expect($published->first()->id)->toBe($active->id);
    expect($published->last()->ends_at->greaterThanOrEqualTo(CarbonImmutable::parse($requiredEnd)))->toBeTrue();

    foreach ($published as $index => $promotion) {
        expect($promotion->timezone_snapshot)->toBe('Europe/Madrid');
        expect($promotion->starts_at->setTimezone('Europe/Madrid')->format('H:i:s'))->toBe('00:00:00');
        expect($promotion->ends_at->setTimezone('Europe/Madrid')->format('H:i:s'))->toBe('00:00:00');
        expect($promotion->starts_at->lessThan($promotion->ends_at))->toBeTrue();

        if ($index > 0) {
            expect($promotion->starts_at->equalTo($published[$index - 1]->ends_at))->toBeTrue();
        }

        foreach ([$promotion->starts_at, $promotion->ends_at->subSecond()] as $boundary) {
            $covering = Promotion::query()
                ->where('business_id', $business->id)
                ->where('status', PromotionStatus::Published)
                ->where('starts_at', '<=', $boundary)
                ->where('ends_at', '>', $boundary)
                ->sole();

            expect($covering->id)->toBe($promotion->id);
        }
    }
})->with([
    'review date through all of January' => ['2026-11-04', '2026-11-03 23:30:00+00', '2027-01-31 23:00:00+00'],
    'long calendar across both DST transitions and reward reuse' => ['2026-03-20', '2026-03-19 23:30:00+00', '2027-01-31 23:00:00+00'],
    'later rerun retains future coverage across DST' => ['2027-03-20', '2027-03-19 23:30:00+00', '2027-05-01 22:00:00+00'],
]);

it('leaves existing owners untouched without requiring a review password or reading the clock', function () {
    $owner = User::factory()->create();
    $original = $owner->fresh()->getAttributes();
    config(['tfm.review_password' => null]);
    $this->mock(DatabaseClock::class)->shouldNotReceive('capture');

    $this->seed(TfmDemoSeeder::class);

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('businesses', 0);
    $this->assertDatabaseCount('promotions', 0);
    expect($owner->fresh()->getAttributes())->toBe($original);
});
