<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marshmallow\Ecommerce\Cart\Enums\CartItemType;

/**
 * 6.1: orders carry a snapshot of everything they were sold with, lines
 * record the purchasable's morph type, carts know when they were converted,
 * and the columns the package queries on are indexed. Every step is guarded,
 * so the migration is safe on a fresh install and on a 6.0 database alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->upgradeCarts();
        $this->upgradeLines();
        $this->upgradeOrders();
        $this->indexLookups();
        $this->backfillPurchasableTypes();
        $this->resignCartLines();
    }

    public function down(): void
    {
        // Additive only; the snapshot columns hold facts about paid orders
        // and are deliberately not dropped.
    }

    private function upgradeCarts(): void
    {
        if (! Schema::hasColumn('shopping_carts', 'converted_at')) {
            Schema::table('shopping_carts', function (Blueprint $table): void {
                $table->timestamp('converted_at')->nullable()->after('confirmed_at');
            });
        }
    }

    private function upgradeLines(): void
    {
        foreach (['shopping_cart_items', 'order_items'] as $table) {
            if (! Schema::hasColumn($table, 'purchasable_type')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->string('purchasable_type')->nullable()->after('purchasable_id');
                });
            }
        }
    }

    private function upgradeOrders(): void
    {
        $columns = [
            'payment_id' => fn (Blueprint $t) => $t->string('payment_id', 36)->nullable()->after('shopping_cart_display_id'),
            'customer_snapshot' => fn (Blueprint $t) => $t->json('customer_snapshot')->nullable(),
            'shipping_address_snapshot' => fn (Blueprint $t) => $t->json('shipping_address_snapshot')->nullable(),
            'invoice_address_snapshot' => fn (Blueprint $t) => $t->json('invoice_address_snapshot')->nullable(),
            'shipping_method_snapshot' => fn (Blueprint $t) => $t->json('shipping_method_snapshot')->nullable(),
            'discounts_snapshot' => fn (Blueprint $t) => $t->json('discounts_snapshot')->nullable(),
            'snapshot_fingerprint' => fn (Blueprint $t) => $t->string('snapshot_fingerprint', 64)->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $t) use ($definition): void {
                    $definition($t);
                });
            }
        }
    }

    private function indexLookups(): void
    {
        $indexes = [
            'shopping_carts' => [
                'shopping_carts_user_id_index' => ['user_id'],
                'shopping_carts_prospect_id_index' => ['prospect_id'],
                'shopping_carts_customer_id_index' => ['customer_id'],
                'shopping_carts_confirmed_at_updated_at_index' => ['confirmed_at', 'updated_at'],
                'shopping_carts_converted_at_index' => ['converted_at'],
                'shopping_carts_abandoned_at_index' => ['abandoned_at'],
            ],
            'shopping_cart_items' => [
                'shopping_cart_items_shopping_cart_id_signature_index' => ['shopping_cart_id', 'signature'],
            ],
            'order_items' => [
                'order_items_type_description_index' => ['type', 'description'],
            ],
            'orders' => [
                'orders_customer_id_index' => ['customer_id'],
                'orders_user_id_index' => ['user_id'],
                'orders_payment_id_index' => ['payment_id'],
            ],
            'customers' => [
                'customers_prospect_id_index' => ['prospect_id'],
                'customers_email_index' => ['email'],
            ],
        ];

        foreach ($indexes as $table => $definitions) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($definitions as $name => $columns) {
                if (Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $t) use ($columns, $name): void {
                    $t->index($columns, $name);
                });
            }
        }
    }

    /**
     * Lines written before 6.1 point at the configured product model.
     */
    private function backfillPurchasableTypes(): void
    {
        $productModel = config('cart.models.product');

        if (! is_string($productModel) || ! class_exists($productModel)) {
            return;
        }

        $type = (new $productModel)->getMorphClass();

        foreach (['shopping_cart_items', 'order_items'] as $table) {
            DB::table($table)
                ->whereNull('purchasable_type')
                ->whereNotNull('purchasable_id')
                ->update(['purchasable_type' => $type]);
        }
    }

    /**
     * The signature now covers the purchasable type; re-sign every cart line
     * so repeat additions keep combining with what is already there.
     */
    private function resignCartLines(): void
    {
        $itemModel = config('cart.models.shopping_cart_item');

        DB::table('shopping_cart_items')->orderBy('id')->chunkById(500, function ($rows) use ($itemModel): void {
            foreach ($rows as $row) {
                $type = CartItemType::tryFrom((string) $row->type);

                if (! $type) {
                    continue;
                }

                $meta = is_string($row->meta ?? null) ? json_decode($row->meta, true) : null;
                $signature = $itemModel::signatureFor($row->purchasable_type ?? null, $row->purchasable_id ?? null, $type, is_array($meta) ? $meta : null);

                if ($signature !== $row->signature) {
                    DB::table('shopping_cart_items')->where('id', $row->id)->update(['signature' => $signature]);
                }
            }
        });
    }
};
