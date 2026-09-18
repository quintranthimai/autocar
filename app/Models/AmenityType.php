<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH NHÓM TIỆN ÍCH (AMENITY TYPE MODEL)
 * ============================================================================
 * Quản lý danh mục phân lớp tiện nghi phương tiện trên hệ thống Sàn Thuê Xe,
 * giúp gom nhóm các tính năng con (An toàn, Công nghệ, Thiết bị nổi bật).
 */
class AmenityType extends Model
{
    /**
     * Các trường dữ liệu được phép gán mass assignment
     * @var array<string>
     */
    protected $fillable = ['name', 'display_name'];

    /**
     * ========================================================================
     * 1. QUAN HỆ DANH SÁCH TIỆN ÍCH CON (HAS MANY AMENITIES)
     * ========================================================================
     * Truy xuất trọn bộ trang bị tiện ích chi tiết nằm trong nhóm phân loại này.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function amenities()
    {
        return $this->hasMany(Amenity::class);
    }
}

