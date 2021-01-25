<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddUserIdColumnToShoppingCartsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('shopping_carts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->after('id')->nullable()->default(null);
            $table->unsignedBigInteger('shipping_address_id')->after('prospect_id')->nullable()->default(null);
            $table->unsignedBigInteger('invoice_address_id')->after('shipping_address_id')->nullable()->default(null);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('shopping_carts', function (Blueprint $table) {
            $table->dropColumn('user_id');
            $table->dropColumn('shipping_address_id');
            $table->dropColumn('invoice_address_id');
        });
    }
}
