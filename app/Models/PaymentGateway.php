<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH CỔNG THANH TOÁN TÀI CHÍNH (PAYMENT GATEWAY MODEL)
 * ============================================================================
 * Quản trị danh sách các đối tác, nhà cung cấp giải pháp giao dịch ngoại Vi
 * (Ví dụ: VNPay, MoMo, ZaloPay, Thẻ quốc tế Visa/MasterCard hoặc Ví Nội Bộ) kèm
 * theo cấu hình mã bảo mật của từng trung gian thanh toán.
 */
class PaymentGateway extends Model
{
    use HasFactory;

    /**
     * Các trường định nghĩa cổng kết nối cho phép cập nhật hàng loạt
     * @var array<string>
     */
    protected $fillable = [
        'code',           // Mã viết tắt cổng (Ví dụ: VNPAY, WALLET)
        'name',           // Tên thương hiệu hiển thị
        'logo_url',       // Biểu trưng logo thương mại
        'config_data',    // Chuỗi JSON cấu hình khóa bảo mật (API Key/Secret)
        'is_active',      // Trạng thái vận hành của kênh thanh toán (Bật/Tắt)
        'display_order',  // Thứ tự hiển thị trên màn hình thanh toán Checkout
    ];

    /**
     * Quy đổi thuộc tính cấu hình JSON sang mảng và cờ trạng thái sang Boolean
     * @var array<string, string>
     */
    protected $casts = [
        'config_data' => 'array',
        'is_active'   => 'boolean',
    ];

    /**
     * ========================================================================
     * 1. QUAN HỆ LỊCH SỬ THU THI ĐƠN HÀNG (HAS MANY BOOKINGS)
     * ========================================================================
     * 1 Cổng thanh toán có thể thụ lý và xử lý giao dịch cho nhiều Booking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}