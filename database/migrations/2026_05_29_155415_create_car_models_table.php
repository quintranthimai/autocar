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
    Schema::create('car_models', function (Blueprint $table) {
        $table->bigIncrements('id'); // Primary Key [cite: 458]
        $table->foreignId('category_id')->constrained('categories'); // Foreign Key to categories [cite: 458]
        $table->foreignId('fuel_id')->constrained('fuels'); // Foreign Key to fuels [cite: 458]
        $table->foreignId('transmission_id')->constrained('transmissions'); // Foreign Key to transmissions [cite: 458]
        $table->string('brand_name'); // Toyota, VinFast [cite: 458]
        $table->string('model_name'); // Vios, VF8 [cite: 458]
        $table->integer('seat_count'); // Number of seats [cite: 458]
        $table->string('fuel_consumption', 50)->nullable(); // Mức tiêu hao nhiên liệu[cite: 2]
        $table->timestamps(); // VD: Created at, Updated at [cite: 505]
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('car_models');
    }
};
