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
    Schema::create('vehicle_images', function (Blueprint $table) {
        $table->id(); // Khóa chính tự động tăng [cite: 34]
        
        // Trỏ đến vehicles.id (Xác định ảnh này thuộc về chiếc xe nào) [cite: 34]
        $table->unsignedBigInteger('vehicle_id'); 
        
        // Đường dẫn file ảnh thực tế lưu trên Cloud [cite: 34]
        $table->string('image_url'); 
        
        // Đánh dấu ảnh nào là Ảnh bìa [cite: 34]
        $table->boolean('is_thumbnail')->default(false); 
        
        // Thứ tự sắp xếp các ảnh khi khách vuốt xem [cite: 35]
        $table->integer('display_order')->default(0); 
        
        $table->timestamps(); // Laravel mặc định cần created_at và updated_at

        // Ràng buộc khóa ngoại
        $table->foreign('vehicle_id')
              ->references('id')
              ->on('vehicles')
              ->onDelete('cascade'); // Xóa xe thì tự động xóa ảnh
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_images');
    }
};
