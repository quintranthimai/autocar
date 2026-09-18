<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH PHỤ PHÍ PHÁT SINH THỰC TẾ (BOOKING SURCHARGE MODEL)
 * ============================================================================
 * Ghi nhận các khoản phụ thu phát sinh hoặc phí trừng phạt vi phạm cam kết khi
 * bàn giao hoàn xe (Quá số kilomet quy định, quá giờ hoàn xe, xe bám bẩn hoặc hư
 * hại nhẹ) do Chủ xe kê khai có kèm theo ảnh bằng chứng xác thực.
 */
class BookingSurcharge extends Model
{
    use HasFactory;

    /**
     * Danh sách các thông tin phụ thu hợp lệ cho phép tạo bản ghi
     * @var array<string>
     */
    protected $fillable = [
        'booking_id',          // ID Đơn đặt xe
        'surcharge_type',      // Loại phụ thu (Ví dụ: OVER_TIME, DIRTY_VEHICLE)
        'amount',              // Số tiền phạt quy theo đơn vị VNĐ
        'note',                // Ghi chú mô tả cụ thể tình trạng vi phạm
        'evidence_image_url',  // Ảnh chụp minh chứng hỏng hóc hoặc đồng hồ kilomet
    ];

    /**
     * ========================================================================
     * 1. QUAN HỆ TRỰC THƯỢC CHUYẾN ĐI (BELONGS TO BOOKING)
     * ========================================================================
     * Khoản phụ phí phát sinh này được tính kết toán vào Đơn đặt xe nào.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}