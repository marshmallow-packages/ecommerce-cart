<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateShippingMethodsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('manual');
            $table->unsignedBigInteger('vatrate_id');
            $table->unsignedBigInteger('currency_id');
            $table->bigInteger('display_price')->default(0);
            $table->bigInteger('price_excluding_vat')->default(0);
            $table->bigInteger('price_including_vat')->default(0);
            $table->bigInteger('vat_amount')->default(0);
            $table->timestamp('valid_from')->nullable()->default(null);
            $table->timestamp('valid_till')->nullable()->default(null);

            $table->foreign('vatrate_id')->references('id')->on('vat_rates');
            $table->foreign('currency_id')->references('id')->on('currencies');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('shipping_methods');
    }
}
