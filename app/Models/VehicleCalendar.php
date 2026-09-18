<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH LỊCH BIỂU & ĐỊNH GIÁ XE (VEHICLE CALENDAR MODEL)
 * ============================================================================
 * Quản trị lịch rãnh/bận và chính sách giá bán linh hoạt theo từng mốc thời gian
 * cụ thể (Ví dụ: Tăng giá vào cuối tuần hoặc ngày lễ tết, khóa lịch khi bảo trì).
 */
class VehicleCalendar extends Model
{
    /**
     * Danh sách các thông tin cho phép chỉnh sửa lịch trình
     * @var array<string>
     */
    protected $fillable = ['vehicle_id', 'date', 'custom_price', 'is_blocked'];

    /**
     * ========================================================================
     * 1. QUAN HỆ TRỰC TRÌNH PHƯƠNG TIỆN (BELONGS TO VEHICLE)
     * ========================================================================
     * Cấu hình lịch biểu này thuộc về phương tiện cụ thể nào trên hệ thống.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}