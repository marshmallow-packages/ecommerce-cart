<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bring a v2 cart schema up to the v3 shape.
 *
 * v3 replaces the foreign keys into the priceable tables (vatrate_id,
 * currency_id) with self-contained snapshot columns, swaps the product foreign
 * key for a generic purchasable_id, and trades the hashed IP guard for a random
 * session token. Every step is guarded so the migration is safe to run against
 * a partially-upgraded database, and the backfill runs in chunks so it can cope
 * with large item tables.
 *
 * Run after publishing: `php artisan vendor:publish --tag=cart-upgrade-migrations`.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $itemTables = ['shopping_cart_items', 'order_items'];

    public function up(): void
    {
        $this->addSnapshotColumns();
        $this->backfillSnapshots();
        $this->upgradeCarts();
        $this->dropLegacyColumns();
    }

    private function addSnapshotColumns(): void
    {
        foreach ($this->itemTables as $table) {
            if (! Schema::hasColumn($table, 'vat_percentage')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->decimal('vat_percentage', 5, 2)->default(0);
                });
            }

            if (! Schema::hasColumn($table, 'currency')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->string('currency', 3)->default('EUR');
                });
            }

            if (! Schema::hasColumn($table, 'purchasable_id')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->string('purchasable_id')->nullable()->index();
                });
            }

            if (! Schema::hasColumn($table, 'meta')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->json('meta')->nullable();
                });
            }

            if ($table === 'shopping_cart_items' && ! Schema::hasColumn($table, 'signature')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->string('signature')->nullable()->index();
                });
            }
        }
    }

    private function backfillSnapshots(): void
    {
        foreach ($this->itemTables as $table) {
            if (! Schema::hasColumn($table, 'vatrate_id')) {
                continue;
            }

            DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([
                        'vat_percentage' => $this->rateFor($row->vatrate_id ?? null),
                        'currency' => $this->codeFor($row->currency_id ?? null),
                        'purchasable_id' => $row->product_id ?? null,
                    ]);
                }
            });
        }
    }

    private function upgradeCarts(): void
    {
        if (Schema::hasTable('shopping_carts') && ! Schema::hasColumn('shopping_carts', 'guard_token')) {
            Schema::table('shopping_carts', function (Blueprint $t): void {
                $t->string('guard_token', 64)->nullable();
                $t->foreignId('shipping_method_id')->nullable();
                $t->timestamp('abandoned_at')->nullable();
            });
        }
    }

    private function dropLegacyColumns(): void
    {
        foreach ($this->itemTables as $table) {
            $this->dropColumnIfExists($table, ['vatrate_id', 'currency_id', 'product_id', 'display_price']);
        }

        $this->dropColumnIfExists('shopping_carts', ['hashed_ip_address']);
    }

    private function rateFor(?int $vatRateId): float
    {
        if (! $vatRateId || ! Schema::hasTable('vat_rates')) {
            return (float) config('cart.default_vat_percentage', 21.0);
        }

        $rate = DB::table('vat_rates')->where('id', $vatRateId)->value('rate');

        return $rate !== null ? (float) $rate : (float) config('cart.default_vat_percentage', 21.0);
    }

    private function codeFor(?int $currencyId): string
    {
        if (! $currencyId || ! Schema::hasTable('currencies')) {
            return (string) config('cart.currency', 'EUR');
        }

        $code = DB::table('currencies')->where('id', $currencyId)->value('code');

        return is_string($code) ? $code : (string) config('cart.currency', 'EUR');
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function dropColumnIfExists(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $present = array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column));

        if ($present !== []) {
            Schema::table($table, function (Blueprint $t) use ($present): void {
                $t->dropColumn(array_values($present));
            });
        }
    }
};
