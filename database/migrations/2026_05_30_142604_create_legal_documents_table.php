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
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            
            // Khóa ngoại trỏ về bảng users
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            // Khóa ngoại trỏ về bảng vehicles (cho phép Null vì khách thuê không có xe)
            // Lưu ý: Nếu bạn chưa có bảng vehicles, hãy comment dòng này lại tạm thời, 
            // hoặc đảm bảo migration tạo bảng vehicles chạy TRƯỚC file này.
            // $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->onDelete('cascade');
            
            // Nếu bạn chưa tạo bảng vehicles, hãy dùng kiểu cột thông thường trước:
            $table->unsignedBigInteger('vehicle_id')->nullable(); 
            
            $table->string('document_type', 50); // id_card, driving_license, vehicle_registration
            $table->string('document_number', 100); // Số CCCD, Biển số xe...
            
            $table->string('front_image_url'); // Ảnh mặt trước
            $table->string('back_image_url'); // Ảnh mặt sau
            
            $table->json('ocr_extracted_data')->nullable(); // Dữ liệu AI đọc ra
            
            $table->string('status', 20)->default('pending'); // pending, approved, rejected

            $table->timestamps(); // Tự động có created_at và updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};