<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH CẤU HÌNH PHỤ THU (VEHICLE SURCHARGE MODEL)
 * ============================================================================
 * Quản lý danh mục cấu hình bảng giá phụ thu và chế tài phạt vi phạm theo thiết
 * lập độc lập của từng Chủ sở hữu đối với phương tiện của họ (Ví dụ: Phí dọn
 * dẹp vệ sinh nội thất 150.000đ/lần, Phí quá giờ 50.000đ/giờ...).
 */
class VehicleSurcharge extends Model
{
    use HasFactory;

    /**
     * Các trường cấu hình phụ thu cho phép gán thông số đầu vào
     * @var array<string>
     */
    protected $fillable = [
        'vehicle_id',      // Mã ô tô thụ hưởng cấu hình này
        'surcharge_type',  // Mã phân loại chi phí (CLEANING, OVERTIME...)
        'price',           // Đơn giá phụ phí (VNĐ)
        'unit',            // Đơn vị tính (Ví dụ: /giờ, /lần, /km)
        'title',           // Tên khoản phụ phí hiển thị với khách
        'description',     // Lời giải thích điều kiện phát sinh phụ phí
    ];

    /**
     * ========================================================================
     * 1. QUAN HỆ TRỰC TRÌNH PHƯƠNG TIỆN (BELONGS TO VEHICLE)
     * ========================================================================
     * Thuộc về 1 chiếc xe cụ thể (Xác định cấu hình phụ thu này áp dụng cho xe nào).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}