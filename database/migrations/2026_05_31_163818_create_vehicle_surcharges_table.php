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
    Schema::create('vehicle_surcharges', function (Blueprint $table) {
        $table->id(); // Khóa chính tự động tăng[cite: 2]
        $table->unsignedBigInteger('vehicle_id'); // Trỏ đến vehicles.id[cite: 2]
        
        $table->string('surcharge_type', 50); // Mã định danh loại phí (over_limit, late_return...)[cite: 2]
        $table->decimal('price', 12, 2); // Số tiền phạt/phụ phí[cite: 2]
        $table->string('unit', 20)->nullable(); // Đơn vị tính (km, giờ, chuyến)[cite: 2]
        $table->string('title'); // Tên phụ phí hiển thị ra màn hình[cite: 2]
        $table->text('description')->nullable(); // Đoạn văn bản mô tả chi tiết[cite: 2]
        
        $table->timestamps(); // Thời gian tạo, cập nhật[cite: 2]

        // Ràng buộc khóa ngoại
        $table->foreign('vehicle_id')->references('id')->on('vehicles')->onDelete('cascade');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_surcharges');
    }
};
