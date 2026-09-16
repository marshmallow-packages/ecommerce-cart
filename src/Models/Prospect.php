<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Marshmallow\Addressable\Traits\Addressable;
use Marshmallow\Ecommerce\Cart\Events\CustomerCreated;

/**
 * An anonymous, pre-checkout stand-in for a customer. Once an order is placed
 * the prospect is promoted to a {@see Customer} and stamped as converted; it is
 * never deleted, so the trail from cart to order stays intact.
 *
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $company_name
 * @property string|null $email
 * @property string|null $phone_number
 * @property int|null $country_id
 * @property Carbon|null $converted_at
 */
class Prospect extends Model
{
    use Addressable;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    public function getFullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Promote this prospect to a customer, reusing an existing one where the
     * prospect or e-mail already maps to it.
     */
    public function convertToCustomer(): Customer
    {
        if ($customer = $this->getCustomer()) {
            $this->markConverted();

            return $customer;
        }

        $customer = config('cart.models.customer')::create([
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company_name' => $this->company_name,
            'country_id' => $this->country_id,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'prospect_id' => $this->id,
        ]);

        $this->markConverted();

        event(new CustomerCreated($customer));

        return $customer;
    }

    public function getCustomer(): ?Customer
    {
        $customerModel = config('cart.models.customer');

        if ($customer = $customerModel::where('prospect_id', $this->id)->first()) {
            return $customer;
        }

        if (! $this->email) {
            return null;
        }

        return $customerModel::where('email', $this->email)->first();
    }

    protected function markConverted(): void
    {
        if (! $this->converted_at) {
            $this->forceFill(['converted_at' => now()])->saveQuietly();
        }
    }

    public function cart(): HasOne
    {
        return $this->hasOne(config('cart.models.shopping_cart'));
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(config('cart.models.country'));
    }
}
