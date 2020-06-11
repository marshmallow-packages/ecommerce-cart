<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCartsTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('prospects')) {
            Schema::create('prospects', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('first_name')->nullable()->default(null);
                $table->string('last_name')->nullable()->default(null);
                $table->string('company_name')->nullable()->default(null);
                $table->string('address')->nullable()->default(null);
                $table->string('country_id')->nullable()->default(null);
                $table->string('email')->nullable()->default(null);
                $table->string('phone_number')->nullable()->default(null);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('first_name')->nullable()->default(null);
                $table->string('last_name')->nullable()->default(null);
                $table->string('company_name')->nullable()->default(null);
                $table->string('address')->nullable()->default(null);
                $table->string('country_id')->nullable()->default(null);
                $table->string('email')->nullable()->default(null);
                $table->string('phone_number')->nullable()->default(null);
                $table->unsignedBigInteger('prospect_id')->nullable()->default(null);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('prospect_id')->references('id')->on('prospects');
            });
        }

        if (!Schema::hasTable('shopping_carts')) {
            Schema::create('shopping_carts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('customer_id')->nullable()->default(null);
                $table->unsignedBigInteger('prospect_id')->nullable()->default(null);
                $table->text('note')->nullable()->default(null);
                $table->string('hashed_ip_address');
                $table->timestamp('confirmed_at')->nullable()->default(null);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('customer_id')->references('id')->on('customers');
                $table->foreign('prospect_id')->references('id')->on('prospects');
            });
        }

        if (!Schema::hasTable('shopping_cart_items')) {
            Schema::create('shopping_cart_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('shopping_cart_id');
                $table->unsignedBigInteger('product_id')->nullable()->default(null);
                $table->integer('quantity')->default(1);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('shopping_cart_id')->references('id')->on('shopping_carts');
                if (Schema::hasTable('products')) {
                    $table->foreign('product_id')->references('id')->on('products');
                }
            });
        }

        if (!Schema::hasTable('inquiries')) {
            Schema::create('inquiries', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('shopping_cart_id');
                $table->unsignedBigInteger('customer_id')->nullable()->default(null);
                $table->unsignedBigInteger('prospect_id')->nullable()->default(null);
                $table->text('note')->nullable()->default(null);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('shopping_cart_id')->references('id')->on('shopping_carts');
                $table->foreign('customer_id')->references('id')->on('customers');
                $table->foreign('prospect_id')->references('id')->on('prospects');
            });
        }

        if (!Schema::hasTable('inquiry_items')) {
            Schema::create('inquiry_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('inquiry_id')->nullable()->default(null);
                $table->unsignedBigInteger('product_id')->nullable()->default(null);
                $table->integer('quantity')->default(1);
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('inquiry_id')->references('id')->on('inquiries');
                if (Schema::hasTable('products')) {
                    $table->foreign('product_id')->references('id')->on('products');
                }
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
        if (Schema::hasTable('prospects')) {
            Schema::dropIfExists('prospects');
        }

        if (Schema::hasTable('customers')) {
            Schema::dropIfExists('customers');
        }

        if (Schema::hasTable('shopping_carts')) {
            Schema::dropIfExists('shopping_carts');
        }

        if (Schema::hasTable('shopping_cart_items')) {
            Schema::dropIfExists('shopping_cart_items');
        }

        if (Schema::hasTable('inquiries')) {
            Schema::dropIfExists('inquiries');
        }

        if (Schema::hasTable('inquiry_items')) {
            Schema::dropIfExists('inquiry_items');
        }
    }
}
