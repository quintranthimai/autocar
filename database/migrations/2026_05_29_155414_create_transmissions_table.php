<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transmissions', function (Blueprint $table) {
            $table->id(); // bigIncrements
            $table->string('name', 50)->unique(); // VD: automatic, manual
            $table->string('display_name'); // VD: "Số tự động", "Số sàn"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmissions');
    }
};