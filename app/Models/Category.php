<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH PHÂN LỚP KIỂU DÁNG Ô TÔ (VEHICLE CATEGORY MODEL)
 * ============================================================================
 * Quản trị phân khúc ngoại hình và cấu tạo khung gầm của các phương tiện được
 * tích hợp trên hệ thống (Ví dụ: Xe 4 chỗ Mini, Sedan 5 chỗ, Gầm cao SUV 7 chỗ...).
 */
class Category extends Model
{
    /**
     * Các trường định danh nhóm loại cho phép gán thông tin
     * @var array<string>
     */
    protected $fillable = ['name', 'display_name'];

    /**
     * ========================================================================
     * 1. QUAN HỆ TRỰC TRÌNH CÁC DÒNG XE (HAS MANY CAR MODELS)
     * ========================================================================
     * Trích xuất toàn bộ các dòng xe tiêu chuẩn (CarModel) mang thiết kế này.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function carModels()
    {
        return $this->hasMany(CarModel::class);
    }
}