<?php

use App\Enums\Crypto;
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
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            // Idempotency key to avoid duplicate rows on retries
            $table->string('trade_uid', 64)->unique();

            $table->foreignId('buy_order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('sell_order_id')->constrained('orders')->cascadeOnDelete();

            $table->enum('symbol', Crypto::values());
            $table->decimal('price', 36, 18);
            $table->decimal('amount', 36, 18);
            $table->timestamp('executed_at');

            $table->timestamps();

            // Indexes for query performance
            $table->index(['symbol', 'executed_at']);
            $table->index('buy_order_id');
            $table->index('sell_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
