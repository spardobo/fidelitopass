<?php

namespace App\Models;

use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

#[Fillable(['name', 'timezone', 'pass_background_color'])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    /** Allocates acquisition identity on creation and rejects later reassignment. */
    protected static function booted(): void
    {
        static::creating(function (Business $business): void {
            $business->public_id = (string) Str::uuid7();
        });

        static::updating(function (Business $business): void {
            if ($business->isDirty('public_id')) {
                throw new LogicException('A Business public identity cannot be changed.');
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Defines this Business's owned promotion records.
     *
     * @return HasMany<Promotion, $this> Promotions belonging to this Business.
     */
    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class);
    }

    /**
     * Defines this Business's persistent anonymous Customer passes.
     *
     * @return HasMany<CustomerPass, $this> Anonymous pass identities owned by this Business.
     */
    public function customerPasses(): HasMany
    {
        return $this->hasMany(CustomerPass::class);
    }

    /**
     * Defines the Business-owned pass and Promotion associations.
     *
     * @return HasMany<PromotionParticipation, $this> Participation records owned by this Business.
     */
    public function promotionParticipations(): HasMany
    {
        return $this->hasMany(PromotionParticipation::class);
    }
}
