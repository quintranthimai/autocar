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
    Schema::create('payment_gateways', function (Blueprint $table) {
        $table->id(); // Khóa chính tự động tăng
        
        $table->string('code', 50)->unique(); // Mã định danh (vnpay, momo...)[cite: 2]
        $table->string('name'); // Tên hiển thị[cite: 2]
        $table->string('logo_url')->nullable(); // Link ảnh logo[cite: 2]
        $table->json('config_data')->nullable(); // Lưu trữ API Key, Secret Key...[cite: 2]
        $table->boolean('is_active')->default(true); // Công tắc hiển thị[cite: 2]
        $table->integer('display_order')->default(0); // Thứ tự ưu tiên hiển thị[cite: 2]
        
        $table->timestamps(); // Thời gian tạo, cập nhật[cite: 2]
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
