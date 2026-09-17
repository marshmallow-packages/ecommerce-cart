<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Marshmallow\Addressable\Traits\Addressable;
use Marshmallow\Ecommerce\Cart\Database\Factories\CustomerFactory;

/**
 * A known customer, promoted from a {@see Prospect} at checkout.
 *
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $company_name
 * @property string|null $email
 * @property string|null $phone_number
 * @property int|null $country_id
 * @property int|null $prospect_id
 * @property string|null $payable_external_id
 * @property-read ShoppingCart|null $cart
 */
class Customer extends Model
{
    use Addressable;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected static function newFactory(): Factory
    {
        return CustomerFactory::new();
    }

    public function getFullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * The customer's most recent cart.
     */
    public function cart(): HasOne
    {
        return $this->hasOne(config('cart.models.shopping_cart'), 'customer_id')->latestOfMany();
    }

    public function carts(): HasMany
    {
        return $this->hasMany(config('cart.models.shopping_cart'), 'customer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(config('cart.models.order'), 'customer_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.country'));
    }
}
