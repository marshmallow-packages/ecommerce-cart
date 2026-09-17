<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->foreignId('customer_id')->nullable();
            $table->string('phone_number')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('price_cents')->default(0);
            $table->decimal('vat_percentage', 5, 2)->default(21);
            $table->integer('stock')->default(100);
            $table->json('category_ids')->nullable();
            $table->json('price_tiers')->nullable();
            $table->timestamps();
        });

        Schema::create('gift_cards', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->integer('value_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('products');
        Schema::dropIfExists('users');
    }
};
