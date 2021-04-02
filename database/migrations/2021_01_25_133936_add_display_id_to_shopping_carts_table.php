<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Marshmallow\Ecommerce\Cart\Models\ShoppingCart;

class AddDisplayIdToShoppingCartsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shopping_carts', function (Blueprint $table) {
            $table->unsignedBigInteger('display_id')->nullable()->default(null)->after('id');
        });

        $display_id = 0;
        $carts = ShoppingCart::orderBy('created_at', 'asc')->get();
        if ($carts->count()) {
            foreach ($carts as $cart) {
                $display_id++;
                $cart->update([
                    'display_id' => $display_id,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('shopping_carts', function (Blueprint $table) {
            $table->dropColumn('display_id');
        });
    }
}
