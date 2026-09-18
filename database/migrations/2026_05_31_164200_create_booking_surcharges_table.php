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
    Schema::create('booking_surcharges', function (Blueprint $table) {
        $table->id(); // Khóa chính tự động tăng[cite: 2]
        $table->unsignedBigInteger('booking_id'); // Trỏ đến bookings.id[cite: 2]
        
        $table->string('surcharge_type', 50); // Phân loại phụ phí (over_limit, late_return...)[cite: 2]
        $table->decimal('amount', 12, 2); // Số tiền phụ phí phát sinh thực tế[cite: 2]
        $table->string('note')->nullable(); // Ghi chú của chủ xe[cite: 2]
        $table->string('evidence_image_url')->nullable(); // Link ảnh chụp bằng chứng[cite: 2]
        
        $table->timestamps(); // Thời gian báo cáo và cập nhật[cite: 2]

        // Ràng buộc khóa ngoại
        $table->foreign('booking_id')->references('id')->on('bookings')->onDelete('cascade');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_surcharges');
    }
};
