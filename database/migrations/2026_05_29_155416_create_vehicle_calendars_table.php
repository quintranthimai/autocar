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
        Schema::create('vehicle_calendars', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('vehicle_id')->constrained('vehicles')->onDelete('cascade');
            $table->date('date');
            $table->decimal('custom_price', 10, 2)->nullable(); // Giá ngày Lễ/Tết
            $table->boolean('is_blocked')->default(false); // Chủ xe khóa lịch
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_calendars');
    }
};
