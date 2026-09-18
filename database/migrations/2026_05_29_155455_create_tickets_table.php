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
        Schema::create('tickets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('ticket_category_id')->constrained('ticket_categories');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('booking_id')->nullable()->constrained('bookings');
            $table->foreignId('assigned_staff_id')->nullable()->constrained('users');
            $table->string('subject');
            $table->text('content');
            $table->json('attachments')->nullable(); // Lưu trữ đường dẫn file đính kèm dưới dạng JSON
            $table->string('status', 20)->default('new'); // Trạng thái Kanban kéo thả
            $table->string('priority', 20)->default('medium');
            $table->integer('rating')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
