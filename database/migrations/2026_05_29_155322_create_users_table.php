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
    Schema::create('users', function (Blueprint $table) {
        $table->bigIncrements('id'); // Primary Key [cite: 448]
        $table->string('name'); // VD: John Doe [cite: 448]
        $table->string('email')->unique(); // VD: john.doe@example.com [cite: 448]
        $table->string('password'); // VD: hashed password [cite: 448]
        $table->string('phone')->unique()->nullable(); // VD: 0901234567 [cite: 448]
        $table->string('address')->nullable(); // VD: 123 Main St, City, Country [cite: 448]
        $table->string('avatar')->nullable(); // VD: URL to user's avatar image [cite: 448]
        $table->string('kyc_status')->default('pending'); // pending, approved, rejected [cite: 448]
        $table->decimal('credit_score', 2, 1)->default(5.0); // VD: Credit score (e.g., 5.0) [cite: 448]
        $table->boolean('is_blacklisted')->default(false); // VD: Whether the user is blacklisted [cite: 449]
        $table->decimal('avg_rental_spend', 12, 2)->default(0); // VD: Average rental spending [cite: 449]
        $table->decimal('avg_partner_revenue', 12, 2)->default(0); // VD: Average revenue from partnerships [cite: 449]
        $table->string('membership_tier', 20)->default('bronze'); // VD: User's membership tier [cite: 449]
        $table->string('membership_tier_name', 50)->default('Đồng'); // VD: Name of the membership tier [cite: 449]
        $table->integer('response_rate')->default(100); // Tỉ lệ % trả lời tin nhắn
        $table->integer('response_time_minutes')->default(5); // Thời gian phản hồi trung bình (phút)
        $table->timestamps(); // VD: Created at, Updated at [cite: 505]
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
