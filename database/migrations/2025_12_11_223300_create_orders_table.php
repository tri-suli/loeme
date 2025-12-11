<?php

use App\Enums\Crypto;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('symbol', Crypto::values());
            $table->enum('side', OrderSide::values());
            $table->decimal('price', 36, 18);
            $table->decimal('amount', 36, 18);
            $table->decimal('remaining', 36, 18);
            $table->enum('status', OrderStatus::values())->default(1); // 1=open,2=filled,3=cancelled
            $table->timestamps();

            $table->index(['symbol', 'status']);
            // Separate composite indexes for buy and sell priority queries
            $table->index(['symbol', 'side', 'price', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
