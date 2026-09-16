<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
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
        if (! config('cart.abandoned.fire_events', true)) {
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

    protected function pruneExpired(): int
    {
        $threshold = now()->subDays((int) config('cart.abandoned.delete_after_days', 90));

        return $this->openCarts()
            ->where('updated_at', '<', $threshold)
            ->delete();
    }

    /**
     * @return Builder<ShoppingCart>
     */
    protected function openCarts(): Builder
    {
        $cartModel = config('cart.models.shopping_cart');

        return $cartModel::query()->whereNull('confirmed_at');
    }
}
