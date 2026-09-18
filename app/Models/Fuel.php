<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH NHIÊN LIỆU VẬN HÀNH (FUEL MODEL)
 * ============================================================================
 * Quản trị phân loại năng lượng tiêu thụ của phương tiện (Ví dụ: Xăng sinh học,
 * Dầu Diesel, Động cơ Hybrid, Động cơ Thuần điện EV).
 */
class Fuel extends Model
{
    /**
     * Các thuộc tính định nghĩa loại nhiên liệu
     * @var array<string>
     */
    protected $fillable = ['name', 'display_name'];

    /**
     * ========================================================================
     * 1. QUAN HỆ TRỰC BẢN DÒNG XE THAM CHIẾU (HAS MANY CAR MODELS)
     * ========================================================================
     * Trích xuất toàn bộ danh mạc các dòng phương tiện (CarModel) vận hành trên
     * cơ sở cấu tạo tiêu thụ loại nhiên liệu này.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function carModels()
    {
        return $this->hasMany(CarModel::class);
    }
}