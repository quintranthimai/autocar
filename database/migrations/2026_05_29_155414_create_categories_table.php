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
    Schema::create('categories', function (Blueprint $table) {
        $table->bigIncrements('id'); // Primary Key [cite: 454]
        $table->string('name')->unique(); // suv, sedan [cite: 454]
        $table->string('display_name'); // Xe gầm cao đi tỉnh [cite: 454]
        $table->timestamps(); // VD: Created at, Updated at [cite: 505]
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
