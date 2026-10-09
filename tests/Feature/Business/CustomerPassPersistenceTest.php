<?php

use App\Models\Business;
use App\Models\CustomerPass;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('persists an anonymous pass owned by one Business without provisioning credentials', function () {
    $business = Business::factory()->create();
    $otherBusiness = Business::factory()->create();

    $pass = $business->customerPasses()->create();

    $this->assertModelExists($pass);
    expect($pass->id)->toBeInt();
    expect(Str::isUuid($pass->public_id, 7))->toBeTrue();
    expect($pass->business->is($business))->toBeTrue();
    expect($business->customerPasses->modelKeys())->toBe([$pass->id]);
    expect($otherBusiness->customerPasses)->toBeEmpty();
    $this->assertDatabaseHas('customer_passes', [
        'id' => $pass->id,
        'wallet_object_id' => null,
        'validation_token_hash' => null,
        'manual_code' => null,
    ]);
});

it('allows multiple unprovisioned anonymous identities within one Business', function () {
    $business = Business::factory()->create();

    $passes = CustomerPass::factory()->for($business)->count(2)->create();

    expect($passes->pluck('public_id')->unique())->toHaveCount(2);
    expect($passes->first()->business->is($business))->toBeTrue();
    $this->assertDatabaseCount('customer_passes', 2);
});

it('preserves pass identity while provider facts change and hides the credential verifier', function () {
    $pass = CustomerPass::factory()->create();
    $publicId = $pass->public_id;
    $verifier = hash('sha256', 'synthetic-private-validation-token');

    $pass->wallet_object_id = 'synthetic-issuer.customer-pass';
    $pass->validation_token_hash = $verifier;
    $pass->manual_code = 'AB12CD';
    $pass->save();

    $persisted = $pass->fresh();
    expect($persisted->public_id)->toBe($publicId);
    expect($persisted->toArray())->not->toHaveKey('validation_token_hash');
    expect(json_decode($persisted->toJson(), true))->not->toHaveKey('validation_token_hash');
    $this->assertDatabaseHas('customer_passes', [
        'id' => $pass->id,
        'wallet_object_id' => 'synthetic-issuer.customer-pass',
        'validation_token_hash' => $verifier,
        'manual_code' => 'AB12CD',
    ]);
});

it('rejects globally duplicate identities across Businesses through direct writes', function (string $column, string $value) {
    CustomerPass::factory()->create([$column => $value]);
    $attributes = CustomerPass::factory()->raw([$column => $value]);
    $attributes['public_id'] ??= '019b76da-a800-7000-8000-000000000002';

    expect(fn () => DB::table('customer_passes')->insert($attributes))
        ->toThrow(UniqueConstraintViolationException::class);
})->with([
    'public identifier' => ['public_id', '019b76da-a800-7000-8000-000000000001'],
    'Wallet object' => ['wallet_object_id', 'synthetic-issuer.existing-pass'],
    'validation verifier' => ['validation_token_hash', hash('sha256', 'synthetic-token')],
]);

it('rejects duplicate manual codes within the owning Business through direct writes', function () {
    $pass = CustomerPass::factory()->create(['manual_code' => 'AB12CD']);
    $attributes = CustomerPass::factory()->for($pass->business)->raw([
        'manual_code' => 'AB12CD',
        'public_id' => '019b76da-a800-7000-8000-000000000003',
    ]);

    expect(fn () => DB::table('customer_passes')->insert($attributes))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows the same manual lookup code in different Businesses', function () {
    $first = CustomerPass::factory()->create(['manual_code' => 'AB12CD']);
    $second = CustomerPass::factory()->create(['manual_code' => 'AB12CD']);

    expect($first->business_id)->not->toBe($second->business_id);
    $this->assertModelExists($second);
});

it('rejects missing Business ownership through direct writes', function (?int $businessId, string $sqlState) {
    expect(fn () => DB::table('customer_passes')->insert([
        'public_id' => '019b76da-a800-7000-8000-000000000004',
        'business_id' => $businessId,
    ]))->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe($sqlState));
})->with(['null owner' => [null, '23502'], 'nonexistent owner' => [999999, '23503']]);

it('rejects a missing public identity through direct writes', function () {
    $business = Business::factory()->create();

    expect(fn () => DB::table('customer_passes')->insert(['business_id' => $business->id]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23502'));
});

it('rejects blank provisioned identifiers instead of treating them as absent', function (string $column, string $value) {
    $pass = CustomerPass::factory()->create();

    expect(fn () => DB::table('customer_passes')->where('id', $pass->id)->update([$column => $value]))
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23514'));
})->with(['wallet_object_id', 'validation_token_hash', 'manual_code'])
    ->with(['empty' => '', 'spaces' => '   ']);

it('preserves a pass when deleting its owning Business is attempted', function () {
    $pass = CustomerPass::factory()->create();

    expect(fn () => DB::table('businesses')->where('id', $pass->business_id)->delete())
        ->toThrow(fn (QueryException $exception) => expect($exception->getCode())->toBe('23503'));
});

it('does not let mass assignment move a pass to another Business', function () {
    $pass = CustomerPass::factory()->create();
    $originalBusinessId = $pass->business_id;
    $otherBusiness = Business::factory()->create();

    $pass->fill(['business_id' => $otherBusiness->id])->save();

    expect($pass->fresh()->business_id)->toBe($originalBusinessId);
});
