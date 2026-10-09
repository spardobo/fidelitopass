<?php

namespace App\Models;

use Database\Factories\CustomerPassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Persistent anonymous identity owned by one Business, independent of its Promotions. */
#[Fillable(['wallet_object_id', 'validation_token_hash', 'manual_code'])]
#[Hidden(['validation_token_hash'])]
class CustomerPass extends Model
{
    /** @use HasFactory<CustomerPassFactory> */
    use HasFactory;

    /** Assigns a public identity without issuing private credentials or provisioning Wallet. */
    protected static function booted(): void
    {
        static::creating(function (CustomerPass $pass): void {
            $pass->public_id ??= (string) Str::uuid7();
        });
    }

    /**
     * Defines the owning Business relationship.
     *
     * @return BelongsTo<Business, $this> Business that owns this anonymous pass.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
