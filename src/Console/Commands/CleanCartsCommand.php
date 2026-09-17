<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Marshmallow\Ecommerce\Cart\Events\CartAbandoned;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

/**
 * Housekeeping for stale carts: flags the ones a visitor walked away from and
 * prunes the ones old enough to forget entirely.
 */
class CleanCartsCommand extends Command
{
    protected $signature = 'ecommerce:clean-carts';

    protected $description = 'Flag abandoned shopping carts and prune long-expired ones.';

    public function handle(): int
    {
        $abandoned = $this->flagAbandoned();
        $deleted = $this->pruneExpired();

        $this->info("Flagged {$abandoned} abandoned cart(s) and pruned {$deleted} expired cart(s).");

        return self::SUCCESS;
    }

    protected function flagAbandoned(): int
    {
        // `fire_events` is the pre-6.1 name of this switch; a published config
        // that still turns it off keeps working.
        if (! config('cart.abandoned.flag_abandoned', true) || ! config('cart.abandoned.fire_events', true)) {
            return 0;
        }

        $threshold = now()->subDays((int) config('cart.abandoned.expires_after_days', 30));
        $count = 0;

        $this->openCarts()
            ->where('updated_at', '<', $threshold)
            ->whereNull('abandoned_at')
            ->chunkById(200, function ($carts) use (&$count): void {
                foreach ($carts as $cart) {
                    // Flagging is not activity: leave updated_at untouched so the
                    // prune step still sees the cart as stale.
                    $cart->timestamps = false;
                    $cart->forceFill(['abandoned_at' => now()])->saveQuietly();
                    event(new CartAbandoned($cart));
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Permanently remove carts (and their lines) that have been quiet for the
     * deletion threshold, including carts that were soft-deleted earlier, e.g.
     * by a merge on login. A confirmed cart is never pruned: an order may
     * point at it.
     */
    protected function pruneExpired(): int
    {
        $threshold = now()->subDays((int) config('cart.abandoned.delete_after_days', 90));
        $itemModel = config('cart.models.shopping_cart_item');
        $count = 0;

        $this->openCarts()
            ->withTrashed()
            ->where('updated_at', '<', $threshold)
            ->chunkById(200, function ($carts) use ($itemModel, &$count): void {
                $itemModel::withTrashed()->whereIn('shopping_cart_id', $carts->modelKeys())->forceDelete();

                foreach ($carts as $cart) {
                    $cart->forceDelete();
                    $count++;
                }
            });

        $this->pruneOrphanedProspects($threshold);

        return $count;
    }

    /**
     * Prospects that were never converted and belong to no cart or customer
     * any more only exist because their cart did; they go along with it.
     */
    protected function pruneOrphanedProspects(Carbon $threshold): int
    {
        $prospectModel = config('cart.models.prospect');
        $cartModel = config('cart.models.shopping_cart');
        $customerModel = config('cart.models.customer');

        return $prospectModel::withTrashed()
            ->whereNull('converted_at')
            ->where('updated_at', '<', $threshold)
            ->whereNotIn('id', $cartModel::withTrashed()->whereNotNull('prospect_id')->select('prospect_id'))
            ->whereNotIn('id', $customerModel::withTrashed()->whereNotNull('prospect_id')->select('prospect_id'))
            ->forceDelete();
    }

    /**
     * Carts that never went to payment: a confirmed or converted cart belongs
     * to a payment or an order and is left alone.
     *
     * @return Builder<ShoppingCart>
     */
    protected function openCarts(): Builder
    {
        $cartModel = config('cart.models.shopping_cart');

        return $cartModel::query()->whereNull('confirmed_at')->whereNull('converted_at');
    }
}
