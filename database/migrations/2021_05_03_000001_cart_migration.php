<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('shopping_cart_id');
                $table->unsignedBigInteger('shopping_cart_display_id');
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('user_id')->nullable()->default(null);
                $table->unsignedBigInteger('shipping_address_id')->nullable()->default(null);
                $table->unsignedBigInteger('invoice_address_id')->nullable()->default(null);
                $table->unsignedBigInteger('shipping_method_id')->nullable()->default(null);
                $table->text('note')->nullable()->default(null);
                $table->unsignedBigInteger('currency_id')->nullable()->default(null);
                $table->unsignedBigInteger('display_price')->nullable()->default(null);
                $table->unsignedBigInteger('price_excluding_vat')->nullable()->default(null);
                $table->unsignedBigInteger('price_including_vat')->nullable()->default(null);
                $table->unsignedBigInteger('vat_amount')->nullable()->default(null);
                $table->unsignedBigInteger('display_discount')->nullable()->default(null);
                $table->unsignedBigInteger('discount_excluding_vat')->nullable()->default(null);
                $table->unsignedBigInteger('discount_including_vat')->nullable()->default(null);
                $table->unsignedBigInteger('discount_vat_amount')->nullable()->default(null);
                $table->unsignedBigInteger('display_shipping')->nullable()->default(null);
                $table->unsignedBigInteger('shipping_excluding_vat')->nullable()->default(null);
                $table->unsignedBigInteger('shipping_including_vat')->nullable()->default(null);
                $table->unsignedBigInteger('shipping_vat_amount')->nullable()->default(null);
                $table->string('shipping_status')->nullable()->default(null);
                $table->unsignedBigInteger('shipping_type_id')->nullable()->default(null);
                $table->datetime('shipped_at')->nullable()->default(null);
                $table->string('track_and_trace')->nullable()->default(null);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('shopping_cart_item_id');
                $table->unsignedBigInteger('product_id')->nullable()->default(null);
                $table->unsignedBigInteger('vatrate_id')->nullable()->default(null);
                $table->unsignedBigInteger('currency_id')->nullable()->default(null);
                $table->string('description')->nullable()->default(null);
                $table->integer('quantity')->nullable()->default(null);
                $table->string('type')->nullable()->default(null);
                $table->unsignedBigInteger('display_price')->nullable()->default(null);
                $table->unsignedBigInteger('price_excluding_vat')->nullable()->default(null);
                $table->unsignedBigInteger('price_including_vat')->nullable()->default(null);
                $table->unsignedBigInteger('vat_amount')->nullable()->default(null);
                $table->unsignedBigInteger('display_discount')->nullable()->default(null);
                $table->unsignedBigInteger('discount_excluding_vat')->nullable()->default(null);
                $table->unsignedBigInteger('discount_including_vat')->nullable()->default(null);
                $table->unsignedBigInteger('discount_vat_amount')->nullable()->default(null);
                $table->boolean('visible_in_cart')->nullable()->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('orders')) {
            Schema::dropIfExists('orders');
        }

        if (Schema::hasTable('order_items')) {
            Schema::dropIfExists('order_items');
        }
    }
};
