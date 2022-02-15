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
        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('display_price')->default(0)->nullable()->change();
            $table->bigInteger('price_excluding_vat')->default(0)->nullable()->change();
            $table->bigInteger('price_including_vat')->default(0)->nullable()->change();
            $table->bigInteger('vat_amount')->default(0)->nullable()->change();
            $table->bigInteger('display_discount')->default(0)->nullable()->change();
            $table->bigInteger('discount_excluding_vat')->default(0)->nullable()->change();
            $table->bigInteger('discount_including_vat')->default(0)->nullable()->change();
            $table->bigInteger('discount_vat_amount')->default(0)->nullable()->change();
            $table->bigInteger('display_shipping')->default(0)->nullable()->change();
            $table->bigInteger('shipping_excluding_vat')->default(0)->nullable()->change();
            $table->bigInteger('shipping_including_vat')->default(0)->nullable()->change();
            $table->bigInteger('shipping_vat_amount')->default(0)->nullable()->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->bigInteger('display_price')->default(0)->nullable()->change();
            $table->bigInteger('price_excluding_vat')->default(0)->nullable()->change();
            $table->bigInteger('price_including_vat')->default(0)->nullable()->change();
            $table->bigInteger('vat_amount')->default(0)->nullable()->change();
            $table->bigInteger('display_discount')->default(0)->nullable()->change();
            $table->bigInteger('discount_excluding_vat')->default(0)->nullable()->change();
            $table->bigInteger('discount_including_vat')->default(0)->nullable()->change();
            $table->bigInteger('discount_vat_amount')->default(0)->nullable()->change();
        });
    }
};
