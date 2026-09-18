<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH CẤU HÌNH HỆ THỐNG TOÀN CỤC (SYSTEM SETTING MODEL)
 * ============================================================================
 * Quản trị bộ tham số điều phối vận hành của toàn sàn giao dịch thuê xe (Ví dụ:
 * Tỷ lệ hoa hồng nền tảng 15%, Giờ chuẩn thanh toán chi phí, Phí hủy đơn gốc...).
 */
class SystemSetting extends Model
{
    /**
     * Danh sách các trường chìa khóa-giá trị (Key-Value) cho phép gán thông số
     * @var array<string>
     */
    protected $fillable = ['setting_key', 'setting_value', 'description', 'updated_by'];

    /**
     * ========================================================================
     * 1. QUAN HỆ ADMIN HIỆU CHỈNH CẤU HÌNH (BELONGS TO UPDATER)
     * ========================================================================
     * Nhận diện Quản trị viên hệ thống (Admin) thực hiện thao tác điều chỉnh.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}