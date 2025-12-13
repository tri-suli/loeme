<?php

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
        Schema::table('trades', function (Blueprint $table) {
            // Persist per-trade fee accounting (USD quote currency by default)
            $table->decimal('fee_amount', 36, 18)->default(0)->after('amount');
            $table->string('fee_currency', 20)->nullable()->after('fee_amount');
            // Use string for SQLite compatibility in tests
            $table->string('fee_payer', 10)->nullable()->after('fee_currency');

            $table->index(['fee_currency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropIndex(['fee_currency']);
            $table->dropColumn(['fee_amount', 'fee_currency', 'fee_payer']);
        });
    }
};
