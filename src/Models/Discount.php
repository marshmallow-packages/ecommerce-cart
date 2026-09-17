<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as PriceCollection;
use Illuminate\Support\Str;
use Marshmallow\Ecommerce\Cart\Contracts\HasPurchasableCategories;
use Marshmallow\Ecommerce\Cart\Database\Factories\DiscountFactory;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;
use Marshmallow\Ecommerce\Cart\Enums\DiscountAppliesTo;
use Marshmallow\Ecommerce\Cart\Enums\DiscountEligibility;
use Marshmallow\Ecommerce\Cart\Enums\DiscountPrerequisite;
use Marshmallow\Ecommerce\Cart\Enums\DiscountType;
use Marshmallow\Ecommerce\Cart\Enums\OrderStatus;
use Marshmallow\Ecommerce\Cart\Exceptions\DiscountException;
use Marshmallow\Ecommerce\Cart\Support\Price;

/**
 * A voucher/discount, applied to a cart as negative lines: one per VAT rate
 * the discount spans, so the VAT on the discount mirrors the VAT on what it
 * discounts.
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
 * @property bool $is_combinable
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
    use SoftDeletes;

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
            'is_combinable' => 'boolean',
            'is_once_per_customer' => 'boolean',
            'total_usage_limit' => 'integer',
            'fixed_amount' => 'integer',
            'percentage_amount' => 'float',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    protected static function newFactory(): Factory
    {
        return DiscountFactory::new();
    }

    public static function byCode(string $code): ?self
    {
        return static::where('discount_code', $code)->first();
    }

    /**
     * The discounts for a set of codes, keyed by code, in one query.
     *
     * @param  iterable<int, string>  $codes
     * @return Collection<string, static>
     */
    public static function byCodes(iterable $codes): Collection
    {
        /** @var Collection<string, static> $discounts */
        $discounts = static::whereIn('discount_code', collect($codes)->unique()->values()->all())
            ->get()
            ->keyBy('discount_code');

        return $discounts;
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

    /**
     * The negative lines this discount books on the cart: the discount amount
     * distributed over the VAT rates of the lines it applies to, pro rata to
     * what those lines are worth, rounded at the cents level so the lines sum
     * to the discount exactly. A free-shipping code follows the shipping
     * line's own rate.
     *
     * @return PriceCollection<int, Price>
     */
    public function calculateForCart(ShoppingCart $cart): PriceCollection
    {
        $currency = $this->currencyFor($cart);

        if ($this->discount_type === DiscountType::FreeShipping) {
            return $this->distribute($cart->getShippingAmount(), $cart->shippingItems(), $currency);
        }

        $amount = match ($this->discount_type) {
            DiscountType::FixedAmount => $this->fixedAmountDiscount($cart),
            DiscountType::Percentage => $this->percentageDiscount($cart),
        };

        // Stacked codes may never push the products below zero: the cap is the
        // subtotal minus what earlier codes already took off.
        $remaining = max(0, $cart->getSubtotal() + $cart->getDiscountAmount());
        $amount = min(abs($amount), $remaining);

        return $this->distribute($amount, $this->eligibleItems($cart), $currency);
    }

    /**
     * Split a gross amount over the VAT rates of the given lines, weighted by
     * the gross value per rate, using the largest-remainder method so no cent
     * is lost or invented.
     *
     * @param  Collection<int, ShoppingCartItem>  $lines
     * @return PriceCollection<int, Price>
     */
    protected function distribute(int $amount, Collection $lines, string $currency): PriceCollection
    {
        $weights = $lines
            ->groupBy(fn (ShoppingCartItem $line): string => (string) (float) $line->vat_percentage)
            ->map(fn (Collection $group): int => (int) $group->sum(fn (ShoppingCartItem $line): int => $line->getTotalAmount()))
            ->filter(fn (int $weight): bool => $weight > 0);

        if ($amount <= 0 || $weights->isEmpty()) {
            $first = $lines->first();
            $rate = $first ? (float) $first->vat_percentage : (float) config('cart.default_vat_percentage', 21.0);

            return new PriceCollection([Price::fromGross(0, $rate, $currency)]);
        }

        $total = (int) $weights->sum();
        $parts = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $rate => $weight) {
            $exact = $amount * $weight / $total;
            $parts[$rate] = (int) floor($exact);
            $remainders[$rate] = $exact - $parts[$rate];
            $allocated += $parts[$rate];
        }

        arsort($remainders);

        foreach (array_keys($remainders) as $rate) {
            if ($allocated >= $amount) {
                break;
            }

            $parts[$rate]++;
            $allocated++;
        }

        return (new PriceCollection($parts))
            ->filter(fn (int $part): bool => $part > 0)
            ->map(fn (int $part, int|string $rate): Price => Price::fromGross($part, (float) $rate, $currency)->negate())
            ->values();
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
            $email = Str::lower((string) $cart->getCustomerEmail());
            $allowed = array_map(fn (string $address): string => Str::lower($address), $this->eligible_for_emails ?? []);

            if ($email === '' || ! in_array($email, $allowed, true)) {
                throw new DiscountException(__('This voucher can only be used by some customers. Sadly, you are not one of them.'));
            }
        }
    }

    protected function assertUsageWithinLimits(ShoppingCart $cart): void
    {
        if ($this->total_usage_limit && $this->redemptions()->count() >= $this->total_usage_limit) {
            throw new DiscountException(__('This voucher is at its full capacity. It looks like you are a little too late.'));
        }

        if ($this->is_once_per_customer && ($email = $cart->getCustomerEmail())) {
            $orderTable = (new (config('cart.models.order'))())->getTable();
            $customerTable = (new (config('cart.models.customer'))())->getTable();
            $userTable = (new (config('cart.models.user'))())->getTable();

            $count = $this->redemptions()
                ->leftJoin($customerTable, "{$orderTable}.customer_id", '=', "{$customerTable}.id")
                ->leftJoin($userTable, "{$orderTable}.user_id", '=', "{$userTable}.id")
                ->where(fn ($query) => $query
                    ->where("{$customerTable}.email", $email)
                    ->orWhere("{$userTable}.email", $email))
                ->count();

            if ($count) {
                throw new DiscountException(__('It seems like you have already used this voucher. It is not allowed to use it twice.'));
            }
        }
    }

    /**
     * The order lines that redeemed this code on an order that still stands.
     * A canceled or refunded order hands its redemption back, so it neither
     * counts towards the usage limit nor blocks the customer from retrying.
     *
     * @return Builder<OrderItem>
     */
    protected function redemptions(): Builder
    {
        $orderItemModel = config('cart.models.order_item');
        $orderTable = (new (config('cart.models.order'))())->getTable();
        $orderItemTable = (new $orderItemModel)->getTable();

        return $orderItemModel::query()
            ->join($orderTable, "{$orderItemTable}.order_id", '=', "{$orderTable}.id")
            ->where("{$orderItemTable}.type", CartItemType::Discount->value)
            ->where("{$orderItemTable}.description", $this->discount_code)
            ->whereNull("{$orderTable}.deleted_at")
            ->whereNotIn("{$orderTable}.status", [OrderStatus::Canceled->value, OrderStatus::Refunded->value]);
    }

    /**
     * A fixed amount never takes more than the eligible lines are worth, so a
     * code scoped to one product cannot eat into the rest of the cart.
     */
    protected function fixedAmountDiscount(ShoppingCart $cart): int
    {
        $eligible = (int) $this->eligibleItems($cart)->sum(fn (ShoppingCartItem $item): int => $item->getTotalAmount());

        return min((int) $this->fixed_amount, $eligible);
    }

    /**
     * A percentage works on what the eligible items still cost after the codes
     * already on the cart, so stacked percentages compound in the order they
     * were added rather than each taking a slice of the original subtotal.
     */
    protected function percentageDiscount(ShoppingCart $cart): int
    {
        $total = (int) $this->eligibleItems($cart)->sum(fn (ShoppingCartItem $item): int => $item->getTotalAmount());
        $total = max(0, $total + $cart->getDiscountAmount());

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

    protected function currencyFor(ShoppingCart $cart): string
    {
        $currency = $cart->productItems()->pluck('currency')->first();

        return is_string($currency) ? $currency : (string) config('cart.currency', 'EUR');
    }
}
