<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPriceColumnsToShoppingCartItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shopping_cart_items', function (Blueprint $table) {
            $table->unsignedBigInteger('vatrate_id')->nullable()->default(null)->after('product_id');
            $table->unsignedBigInteger('currency_id')->nullable()->default(null)->after('vatrate_id');
            $table->string('description')->nullable()->default(null)->after('currency_id');
            $table->bigInteger('display_price')->default(0)->nullable()->after('type');
            $table->bigInteger('price_excluding_vat')->default(0)->nullable()->after('display_price');
            $table->bigInteger('price_including_vat')->default(0)->nullable()->after('price_excluding_vat');
            $table->bigInteger('vat_amount')->default(0)->nullable()->after('price_including_vat');

            $table->foreign('vatrate_id')->references('id')->on('vat_rates');
            $table->foreign('currency_id')->references('id')->on('currencies');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('shopping_cart_items', function (Blueprint $table) {
            $table->dropForeign(['vatrate_id']);
            $table->dropForeign(['currency_id']);
            $table->dropColumn('description');
            $table->dropColumn('vatrate_id');
            $table->dropColumn('currency_id');
            $table->dropColumn('display_price');
            $table->dropColumn('price_excluding_vat');
            $table->dropColumn('price_including_vat');
            $table->dropColumn('vat_amount');
        });
    }
}
