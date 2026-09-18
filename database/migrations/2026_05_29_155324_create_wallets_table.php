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
    Schema::create('wallets', function (Blueprint $table) {
        $table->bigIncrements('id'); // Primary Key [cite: 451]
        $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade'); // 1 User có 1 Ví [cite: 451]
        $table->decimal('available_balance', 15, 2)->default(0.00); // Available balance [cite: 451]
        $table->decimal('deposit_balance', 15, 2)->default(0.00); // Deposit balance [cite: 451]
        $table->string('status')->default('active'); // active, locked [cite: 451]
        $table->timestamps(); // VD: Created at, Updated at [cite: 505]
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
