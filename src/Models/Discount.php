<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Marshmallow\Ecommerce\Cart\Contracts\HasPurchasableCategories;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Enums\DiscountAppliesTo;
use Marshmallow\Ecommerce\Cart\Enums\DiscountEligibility;
use Marshmallow\Ecommerce\Cart\Enums\DiscountPrerequisite;
use Marshmallow\Ecommerce\Cart\Enums\DiscountType;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * A voucher/discount, applied to a cart as a negative line.
 *
 * All monetary fields are stored in cents; converting euros to cents is the
 * responsibility of the editing layer, not this model.
 *
 * @property string $discount_code
 * @property DiscountType $discount_type
 * @property DiscountAppliesTo $applies_to
 * @property array<int, int|string>|null $applies_to_products
 * @property array<int, int|string>|null $applies_to_product_categories
 * @property DiscountPrerequisite $prerequisite_type
 * @property int|null $prerequisite_purchase_amount
 * @property int|null $prerequisite_quantity
 * @property DiscountEligibility $eligible_for
 * @property array<int, string>|null $eligible_for_emails
 * @property array<int, int|string>|null $eligible_for_customers
 * @property bool $is_active
 * @property bool $is_once_per_customer
 * @property int|null $total_usage_limit
 * @property int|null $fixed_amount
 * @property float|null $percentage_amount
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
class Discount extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'applies_to' => DiscountAppliesTo::class,
            'applies_to_products' => 'array',
            'applies_to_product_categories' => 'array',
            'prerequisite_type' => DiscountPrerequisite::class,
            'prerequisite_purchase_amount' => 'integer',
            'prerequisite_quantity' => 'integer',
            'eligible_for' => DiscountEligibility::class,
            'eligible_for_emails' => 'array',
            'eligible_for_customers' => 'array',
            'is_active' => 'boolean',
            'is_once_per_customer' => 'boolean',
            'total_usage_limit' => 'integer',
            'fixed_amount' => 'integer',
            'percentage_amount' => 'float',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public static function byCode(string $code): ?self
    {
        return static::where('discount_code', $code)->first();
    }

    /**
     * Throw a DiscountException if this discount may not be used on the cart.
     */
    public function assertAllowedOn(ShoppingCart $cart): void
    {
        if (! $this->is_active) {
            throw new DiscountException(__('This voucher is not active yet. Please try again later.'));
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            throw new DiscountException(__('This voucher is not active yet. Please try again later.'));
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            throw new DiscountException(__('This voucher is not active anymore. It looks like you are a little too late.'));
        }

        $eligibleItems = $this->eligibleItems($cart);

        if ($eligibleItems->isEmpty()) {
            throw new DiscountException(__('This voucher cannot be used with the items in your shopping cart.'));
        }

        $this->assertPrerequisiteMet($eligibleItems);
        $this->assertCustomerEligible($cart);
        $this->assertUsageWithinLimits($cart);
    }

    public function calculateForCart(ShoppingCart $cart): Price
    {
        $amount = match ($this->discount_type) {
            DiscountType::FixedAmount => $this->fixedAmountDiscount($cart),
            DiscountType::Percentage => $this->percentageDiscount($cart),
            DiscountType::FreeShipping => $cart->getShippingAmount(),
        };

        $cartTotal = $cart->getSubtotal();
        $amount = min(abs($amount), $cartTotal);

        return Price::fromGross($amount, $this->vatPercentageFor($cart), $this->currencyFor($cart))->negate();
    }

    /**
     * @return Collection<int, ShoppingCartItem>
     */
    public function eligibleItems(ShoppingCart $cart): Collection
    {
        $items = $cart->productItems();

        return match ($this->applies_to) {
            DiscountAppliesTo::Products => $items->filter(
                fn (ShoppingCartItem $item): bool => in_array($item->purchasable_id, $this->applies_to_products ?? [], false),
            )->values(),
            DiscountAppliesTo::Categories => $items->filter(
                fn (ShoppingCartItem $item): bool => $this->itemInEligibleCategory($item),
            )->values(),
            DiscountAppliesTo::All => $items,
        };
    }

    /**
     * @param  Collection<int, ShoppingCartItem>  $eligibleItems
     */
    protected function assertPrerequisiteMet(Collection $eligibleItems): void
    {
        if ($this->prerequisite_type === DiscountPrerequisite::PurchaseAmount) {
            $total = (int) $eligibleItems->sum(fn (ShoppingCartItem $item): int => $item->getTotalAmount());

            if ($total < (int) $this->prerequisite_purchase_amount) {
                throw new DiscountException(__('This voucher can only be used with a minimum order value of :value.', [
                    'value' => Price::fromGross((int) $this->prerequisite_purchase_amount, 0)->format(),
                ]));
            }
        }

        if ($this->prerequisite_type === DiscountPrerequisite::Quantity) {
            $quantity = (int) $eligibleItems->sum('quantity');

            if ($quantity < (int) $this->prerequisite_quantity) {
                throw new DiscountException(__('This voucher can only be used if you order at least :amount of these products.', [
                    'amount' => $this->prerequisite_quantity,
                ]));
            }
        }
    }

    protected function assertCustomerEligible(ShoppingCart $cart): void
    {
        if ($this->eligible_for === DiscountEligibility::Customers) {
            $customer = $cart->getCustomer();

            if (! $customer instanceof Customer || ! in_array($customer->id, $this->eligible_for_customers ?? [], false)) {
                throw new DiscountException(__('This voucher can only be used by some customers. Please log in to your account and try again.'));
            }
        }

        if ($this->eligible_for === DiscountEligibility::Emails) {
            $email = $cart->getCustomerEmail();

            if (! $email || ! in_array($email, $this->eligible_for_emails ?? [], true)) {
                throw new DiscountException(__('This voucher can only be used by some customers. Sadly, you are not one of them.'));
            }
        }
    }

    protected function assertUsageWithinLimits(ShoppingCart $cart): void
    {
        $orderItemModel = config('cart.models.order_item');

        if ($this->total_usage_limit) {
            $used = $orderItemModel::query()
                ->where('type', CartItemType::Discount)
                ->where('description', $this->discount_code)
                ->count();

            if ($used >= $this->total_usage_limit) {
                throw new DiscountException(__('This voucher is at its full capacity. It looks like you are a little too late.'));
            }
        }

        if ($this->is_once_per_customer && ($email = $cart->getCustomerEmail())) {
            $orderTable = (new (config('cart.models.order'))())->getTable();
            $customerTable = (new Customer)->getTable();
            $userTable = (new (config('cart.models.user'))())->getTable();
            $orderItemTable = (new $orderItemModel)->getTable();

            $count = $orderItemModel::query()
                ->join($orderTable, "{$orderItemTable}.order_id", '=', "{$orderTable}.id")
                ->leftJoin($customerTable, "{$orderTable}.customer_id", '=', "{$customerTable}.id")
                ->leftJoin($userTable, "{$orderTable}.user_id", '=', "{$userTable}.id")
                ->where("{$orderItemTable}.type", CartItemType::Discount->value)
                ->where("{$orderItemTable}.description", $this->discount_code)
                ->where(fn ($query) => $query
                    ->where("{$customerTable}.email", $email)
                    ->orWhere("{$userTable}.email", $email))
                ->count();

            if ($count) {
                throw new DiscountException(__('It seems like you have already used this voucher. It is not allowed to use it twice.'));
            }
        }
    }

    protected function fixedAmountDiscount(ShoppingCart $cart): int
    {
        return min((int) $this->fixed_amount, $cart->getSubtotal());
    }

    protected function percentageDiscount(ShoppingCart $cart): int
    {
        $total = (int) $this->eligibleItems($cart)->sum(fn (ShoppingCartItem $item): int => $item->getTotalAmount());

        return (int) round($total * (float) $this->percentage_amount / 100);
    }

    protected function itemInEligibleCategory(ShoppingCartItem $item): bool
    {
        $purchasable = $item->resolvePurchasable();

        if (! $purchasable instanceof HasPurchasableCategories) {
            return false;
        }

        return (bool) array_intersect(
            $purchasable->getPurchasableCategoryKeys(),
            $this->applies_to_product_categories ?? [],
        );
    }

    protected function vatPercentageFor(ShoppingCart $cart): float
    {
        $rates = $cart->productItems()->pluck('vat_percentage')->unique();

        return $rates->count() === 1
            ? (float) $rates->first()
            : (float) config('cart.default_vat_percentage', 21.0);
    }

    protected function currencyFor(ShoppingCart $cart): string
    {
        $currency = $cart->productItems()->pluck('currency')->first();

        return is_string($currency) ? $currency : (string) config('cart.currency', 'EUR');
    }
}
