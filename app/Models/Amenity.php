<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH THỰC THỂ TIỆN ÍCH XE (AMENITY MODEL)
 * ============================================================================
 * Biểu diễn danh mục trang bị tiện nghi và công nghệ phụ trợ tích hợp trên phương
 * tiện (Ví dụ: Bản đồ GPS, Camera hành trình, Khe cắm USB, Ghế trẻ em...).
 */
class Amenity extends Model
{
    /**
     * Các trường thông tin cho phép gán dữ liệu hàng loạt (Mass Assignment)
     * @var array<string>
     */
    protected $fillable = ['amenity_type_id', 'name', 'display_name', 'icon'];

    /**
     * ========================================================================
     * 1. QUAN HỆ NỐI KẾT NHÓM TIỆN ÍCH (BELONGS TO AMENITY TYPE)
     * ========================================================================
     * Xác định trang bị tiện ích này trực thuộc nhóm phân loại tiện ích nào
     * (Ví dụ: Nhóm An toàn, Nhóm Giải trí, Nhóm Ngoại thất).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function amenityType()
    {
        return $this->belongsTo(AmenityType::class);
    }

    /**
     * ========================================================================
     * 2. QUAN HỆ NỐI KẾT PHƯƠNG TIỆN (BELONGS TO MANY VEHICLES)
     * ========================================================================
     * Quan hệ nhiều-nhyêu (n-n) thông qua bảng trung gian `amenity_vehicle`,
     * trích xuất trọn bộ phương tiện hiện hành có trang bị tính năng này.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function vehicles()
    {
        return $this->belongsToMany(Vehicle::class, 'amenity_vehicle', 'amenity_id', 'vehicle_id');
    }
}