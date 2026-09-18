<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH DÒNG XE V TIÊU CHUẨN THÔNG SỐ (CAR MODEL)
 * ============================================================================
 * Quản trị danh mục thông số chuẩn hóa kỹ thuật của từng dòng ô tô (Hãng sản
 * xuất, Tên dòng, Loại nhiên liệu tiêu thụ, Loại hộp số dẫn động và dung tích).
 * Đóng vai trò là bản mẫu thiết kế cho các xe thực tế lưu hành trên hệ sinh thái.
 */
class CarModel extends Model
{
    /**
     * Các chỉ mục thông số cấu hình dòng phương tiện
     * @var array<string>
     */
    protected $fillable = ['category_id', 'fuel_id', 'transmission_id', 'brand_name', 'model_name', 'seat_count', 'fuel_consumption'];

    /**
     * ========================================================================
     * 1. QUAN HỆ KIỂU DÁNG PHÂN LỚP (BELONGS TO CATEGORY)
     * ========================================================================
     * Dòng phương tiện này mang kiểu dáng khung gầm gì (Sedan, SUV, Hatchback...).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * ========================================================================
     * 2. QUAN HỆ NHIÊN LIỆU VẬN HÀNH (BELONGS TO FUEL)
     * ========================================================================
     * Dòng xe sử dụng nguyên liệu tiêu thụ nào (Xăng, Dầu Diesel, Điện).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function fuel()
    {
        return $this->belongsTo(Fuel::class);
    }

    /**
     * ========================================================================
     * 3. QUAN HỆ DANH TRÌNH PHƯƠNG TIỆN (HAS MANY VEHICLES)
     * ========================================================================
     * Danh bạ tất cả các ô tô mang biển kiểm soát thực tế thuộc dòng thiết kế này.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    /**
     * ========================================================================
     * 4. QUAN HỆ CƠ CẤU TRUYỀN Đ Đ HỘP SỐ (BELONGS TO TRANSMISSION)
     * ========================================================================
     * Hộp số truyền động tích hợp theo dòng (Số Tự Động AT hoặc Số Sàn MT).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function transmission()
    {
        return $this->belongsTo(Transmission::class);
    }
}

