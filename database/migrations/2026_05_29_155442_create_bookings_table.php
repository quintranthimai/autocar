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
        Schema::create('bookings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('renter_id')->constrained('users');
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->string('pickup_location');
            $table->string('dropoff_location');
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->decimal('total_amount', 12, 2);
            $table->string('status')->default('pending');
            $table->boolean('is_contract_signed')->default(false);
            $table->string('contract_file_url')->nullable();
            $table->string('cancel_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->string('payment_option', 20)->default('full'); // Hình thức thanh toán: full / deposit[cite: 2]
            $table->decimal('deposit_amount', 12, 2)->default(0); // Số tiền cọc khách đã trả[cite: 2]
            $table->string('final_settlement_method', 50)->nullable(); // Cách trả phần tiền còn lại (cash/bank_transfer)[cite: 2]
            $table->unsignedBigInteger('payment_gateway_id')->nullable(); 
            $table->foreign('payment_gateway_id')->references('id')->on('payment_gateways');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
