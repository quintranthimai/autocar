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
        Schema::create('discount_campaigns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title');
            $table->string('applicable_tier', 20)->default('all');
            $table->string('type'); // percentage hoặc fixed_amount
            $table->decimal('value', 12, 1);
            $table->dateTime('start_date');
            $table->dateTime('end_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_campaigns');
    }
};
