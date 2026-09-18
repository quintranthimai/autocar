<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH HỘP SỐ C TRUYỀN Đ Đ (TRANSMISSION MODEL)
 * ============================================================================
 * Phân lớp kỹ thuật hộp số dẫn động gắn liền với từng dòng phương tiện trên
 * hệ thống (Ví dụ: Số tự động AT, Số sàn MT, Hộp số vô cấp CVT...).
 */
class Transmission extends Model
{
    /**
     * Danh sách các thông tin định nghĩa loại hộp số
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'display_name',
    ];

    /**
     * ========================================================================
     * 1. QUAN HỆ TRỰC CHI MẪU PHƯƠNG TIỆN (HAS MANY CAR MODELS)
     * ========================================================================
     * 1 Hộp số có thể áp dụng cho nhiều Dòng xe (Trích xuất toàn bộ các mẫu xe
     * sở hữu thiết kế kết cấu hộp số tương ứng).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function carModels()
    {
        return $this->hasMany(CarModel::class);
    }
}