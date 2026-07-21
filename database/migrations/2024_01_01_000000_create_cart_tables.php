<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('payable_external_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shopping_carts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('display_id')->unique();
            $table->string('guard_token', 64);
            $table->foreignId('user_id')->nullable();
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('prospect_id')->nullable();
            $table->unsignedBigInteger('shipping_address_id')->nullable();
            $table->unsignedBigInteger('invoice_address_id')->nullable();
            $table->foreignId('shipping_method_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shopping_cart_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('shopping_cart_id');
            $table->string('purchasable_id')->nullable()->index();
            $table->string('description');
            $table->string('type')->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('price_excluding_vat')->default(0);
            $table->integer('price_including_vat')->default(0);
            $table->integer('vat_amount')->default(0);
            $table->decimal('vat_percentage', 5, 2)->default(0);
            $table->string('currency', 3);
            $table->json('meta')->nullable();
            $table->string('signature')->index();
            $table->boolean('visible_in_cart')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('shopping_cart_id')->references('id')->on('shopping_carts')->cascadeOnDelete();
        });

        Schema::create('shipping_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable();
            $table->integer('price_including_vat')->default(0);
            $table->integer('price_excluding_vat')->default(0);
            $table->integer('vat_amount')->default(0);
            $table->decimal('vat_percentage', 5, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->integer('free_from_amount')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_till')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shipping_method_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained()->cascadeOnDelete();
            $table->integer('minimum_amount_including_vat')->default(0);
            $table->integer('maximum_amount_including_vat')->nullable();
            $table->timestamps();
        });

        Schema::create('discounts', function (Blueprint $table): void {
            $table->id();
            $table->string('discount_code')->unique();
            $table->string('discount_type');
            $table->string('applies_to')->default('all');
            $table->json('applies_to_products')->nullable();
            $table->json('applies_to_product_categories')->nullable();
            $table->string('prerequisite_type')->default('none');
            $table->integer('prerequisite_purchase_amount')->nullable();
            $table->unsignedInteger('prerequisite_quantity')->nullable();
            $table->string('eligible_for')->default('all');
            $table->json('eligible_for_emails')->nullable();
            $table->json('eligible_for_customers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_once_per_customer')->default(false);
            $table->unsignedInteger('total_usage_limit')->nullable();
            $table->integer('fixed_amount')->nullable();
            $table->decimal('percentage_amount', 5, 2)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('shopping_cart_id')->unique();
            $table->unsignedBigInteger('shopping_cart_display_id');
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->unsignedBigInteger('shipping_address_id')->nullable();
            $table->unsignedBigInteger('invoice_address_id')->nullable();
            $table->foreignId('shipping_method_id')->nullable();
            $table->text('note')->nullable();
            $table->string('currency', 3);
            $table->string('status')->default('PENDING')->index();
            $table->integer('subtotal_excluding_vat')->default(0);
            $table->integer('subtotal_including_vat')->default(0);
            $table->integer('subtotal_vat_amount')->default(0);
            $table->integer('shipping_excluding_vat')->default(0);
            $table->integer('shipping_including_vat')->default(0);
            $table->integer('shipping_vat_amount')->default(0);
            $table->integer('discount_excluding_vat')->default(0);
            $table->integer('discount_including_vat')->default(0);
            $table->integer('discount_vat_amount')->default(0);
            $table->integer('total_excluding_vat')->default(0);
            $table->integer('total_including_vat')->default(0);
            $table->integer('total_vat_amount')->default(0);
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopping_cart_item_id')->nullable();
            $table->string('purchasable_id')->nullable()->index();
            $table->string('description');
            $table->string('type')->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('price_excluding_vat')->default(0);
            $table->integer('price_including_vat')->default(0);
            $table->integer('vat_amount')->default(0);
            $table->decimal('vat_percentage', 5, 2)->default(0);
            $table->string('currency', 3);
            $table->json('meta')->nullable();
            $table->boolean('visible_in_cart')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('shipping_method_conditions');
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('shopping_cart_items');
        Schema::dropIfExists('shopping_carts');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('prospects');
    }
};
