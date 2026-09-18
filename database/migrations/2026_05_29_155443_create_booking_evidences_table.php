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
        Schema::create('booking_evidences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('phase', 20); // pickup hoặc dropoff
            $table->string('file_url');
            $table->timestamp('recorded_at'); // Thời gian thực của thiết bị
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_evidences');
    }
};
