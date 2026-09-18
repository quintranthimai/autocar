<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH BỘ SẢN PHẨM HÌNH ẢNH XE (VEHICLE IMAGE MODEL)
 * ============================================================================
 * Quản lý kho tệp tin hình ảnh trưng bày nội thất, ngoại thất và góc chiếu của
 * từng chiếc ô tô, cho phép sắp xếp trình tự hiển thị và lựa chọn ảnh đại diện.
 */
class VehicleImage extends Model
{
    use HasFactory;

    /**
     * Các trường thông tin tệp tin cho phép khai báo theo chuỗi
     * @var array<string>
     */
    protected $fillable = [
        'vehicle_id',     // Mã phương tiện sở hữu hình ảnh
        'image_url',      // Đường dẫn tệp tin trên hệ lưu trữ đám mây
        'is_thumbnail',   // Cờ đánh dấu ảnh đại diện chính (Thumbnail)
        'display_order',  // Thứ tự hiển thị trên bục trình diễn (Gallery)
    ];

    /**
     * ========================================================================
     * 1. QUAN HỆ PHƯƠNG TIỆN SỞ HỮU (BELONGS TO VEHICLE)
     * ========================================================================
     * 1 Ảnh thuộc về 1 Xe (Xác định bức hình này mô tả cho chiếc ô tô nào).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}