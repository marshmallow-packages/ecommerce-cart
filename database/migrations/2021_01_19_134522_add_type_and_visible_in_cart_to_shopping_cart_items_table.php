<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCartItem;

class AddTypeAndVisibleInCartToShoppingCartItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shopping_cart_items', function (Blueprint $table) {
            $table->string('type')->default(ShoppingCartItem::TYPE_PRODUCT)->after('quantity');
            $table->boolean('visible_in_cart')->default(true)->after('type');
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
            $table->dropColumn('type');
            $table->dropColumn('visible_in_cart');
        });
    }
}
