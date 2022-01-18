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
        Schema::create('discounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('discount_code');
            $table->string('discount_type');
            $table->unsignedBigInteger('fixed_amount')->nullable()->default(null);
            $table->integer('percentage_amount', false, true)->nullable()->default(null);
            $table->string('applies_to')->default('all');
            $table->json('applies_to_products')->nullable()->default(null);
            $table->json('applies_to_product_categories')->nullable()->default(null);
            $table->string('prerequisite_type')->default('none');
            $table->unsignedBigInteger('prerequisite_purchase_amount')->nullable()->default(null);
            $table->unsignedBigInteger('prerequisite_quantity')->nullable()->default(null);
            $table->string('eligible_for')->default('all');
            $table->json('eligible_for_customers')->nullable()->default(null);
            $table->json('eligible_for_emails')->nullable()->default(null);
            $table->unsignedBigInteger('total_usage_limit')->nullable()->default(null);
            $table->boolean('is_once_per_customer')->default(false);
            $table->datetime('starts_at')->nullable()->default(null);
            $table->datetime('ends_at')->nullable()->default(null);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('discounts');
    }
};
