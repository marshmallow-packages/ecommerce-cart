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
            $table->unique(['shopping_cart_id'], 'shopping_cart_unique');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unique(['shopping_cart_item_id'], 'shopping_cart_item_unique');
        });
    }
};
