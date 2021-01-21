<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShippingMethodConditionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('shipping_method_conditions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shipping_method_id');
            $table->bigInteger('minimum_amount')->default(0);
            $table->bigInteger('minimum_amount_excluding_vat')->default(0);
            $table->bigInteger('minimum_amount_including_vat')->default(0);
            $table->bigInteger('maximum_amount')->default(0);
            $table->bigInteger('maximum_amount_excluding_vat')->default(0);
            $table->bigInteger('maximum_amount_including_vat')->default(0);
            $table->timestamps();

            $table->foreign('shipping_method_id')->references('id')->on('shipping_methods');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('shipping_method_conditions');
    }
}
