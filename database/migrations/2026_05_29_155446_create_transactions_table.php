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
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('wallet_id')->nullable()->constrained('wallets');
            $table->foreignId('booking_id')->nullable()->constrained('bookings');
            $table->decimal('amount', 12, 2);
            $table->string('type'); // credit hoặc debit
            $table->string('balance_type'); // available hoặc deposit
            $table->text('description');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
