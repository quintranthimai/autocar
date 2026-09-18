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
    Schema::create('fuels', function (Blueprint $table) {
        $table->bigIncrements('id'); // Primary Key [cite: 456]
        $table->string('name')->unique(); // gasoline, electricity [cite: 456]
        $table->string('display_name'); // Xăng, Điện [cite: 456]
        $table->timestamps(); // VD: Created at, Updated at [cite: 505]
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fuels');
    }
};
