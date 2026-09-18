<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH DỊCH VỤ GIA TĂNG THỦ GIAO (BOOKING SERVICE MODEL)
 * ============================================================================
 * Lưu trữ chi tiết các gói tiện ích bổ trợ kèm theo mà Khách hàng đã lựa chọn
 * khi chốt Đơn đặt xe (Ví dụ: Bảo hiểm chuyến đi 120,000 VNĐ, Phí giao xe 50,000 VNĐ).
 */
class BookingService extends Model
{
    /**
     * Danh sách các thông tin cho phép cấu hình dữ liệu đầu vào
     * @var array<string>
     */
    protected $fillable = ['booking_id', 'service_name', 'price'];

    /**
     * ========================================================================
     * 1. QUAN HỆ CHI TRÌNH CHUYẾN ĐI HỢP DÒNG (BELONGS TO BOOKING)
     * ========================================================================
     * Gắn kết gói dịch vụ mở rộng này vào hồ sơ thanh toán của Chuyến xe.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}