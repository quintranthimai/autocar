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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('car_model_id')->constrained('car_models');
            $table->string('license_plate')->unique();
            $table->string('vin_number')->unique();
            $table->string('engine_number')->unique();
            $table->integer('year');
            $table->decimal('base_price', 10, 2);
            $table->text('description')->nullable();
            $table->string('parking_address');
            $table->decimal('latitude', 10, 8)->nullable(); // PostgreSQL tối ưu hóa lưu tọa độ GPS
            $table->decimal('longitude', 11, 8)->nullable();
            $table->integer('current_fuel_level')->default(100);
            $table->string('status')->default('pending'); // pending, available, rented
            $table->json('required_documents')->nullable(); // Lưu yêu cầu giấy tờ[cite: 2]
            $table->boolean('is_mortgage_exempt')->default(false); // Xác định xe có Miễn thế chấp không[cite: 2]
            $table->boolean('is_delivery_supported')->default(false); // Tính năng Giao xe tận nơi[cite: 2]
            $table->integer('delivery_radius_km')->nullable(); // Bán kính giao xe tối đa[cite: 2]
            $table->decimal('delivery_fee_per_km', 10, 2)->nullable(); // Phí giao xe tính trên mỗi km[cite: 2]
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
