<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH BẰNG CHỨNG HỒ SƠ GIAO NHẬN (BOOKING EVIDENCE MODEL)
 * ============================================================================
 * Quản lý kho tệp tin minh chứng kỹ thuật (Hình ảnh 360 độ hoặc Video toàn cảnh)
 * được nộp bởi Khách thuê hoặc Chủ xe tại chặng bàn giao xe (`pickup`) và thu
 * hồi trả xe (`dropoff`), nhằm giải quyết minh bạch mọi tranh chấp rủi ro hư hại.
 */
class BookingEvidence extends Model
{
    use HasFactory;

    /**
     * Tên bảng tương tác trong cơ sở dữ liệu
     * @var string
     */
    protected $table = 'booking_evidences';

    /**
     * Vô hiệu hóa tính năng tự động ghi nhận created_at và updated_at của Laravel
     * @var bool
     */
    public $timestamps = false; 

    /**
     * Các thuộc tính cho phép khai báo dữ liệu quy trình
     * @var array<string>
     */
    protected $fillable = [
        'booking_id',   // ID Đơn đặt xe liên hợp
        'uploaded_by',  // ID Tài khoản gửi minh chứng
        'phase',        // Giai đoạn thực hiện ('pickup' hoặc 'dropoff')
        'file_url',     // Đường dẫn tệp tin lưu tại hệ thống công cộng (Storage)
        'recorded_at',  // Mốc thời gian chính thức đóng dấu trên Máy chủ (Server)
    ];

    /**
     * Quy đổi thuộc tính mốc thời gian thành đối tượng Ngày tháng
     * @var array<string, string>
     */
    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    /**
     * ========================================================================
     * 1. QUAN HỆ CHI TRÌNH CHUYẾN ĐI (BELONGS TO BOOKING)
     * ========================================================================
     * Xác định minh chứng này thuộc về hợp đồng thuê phương tiện nào.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    /**
     * ========================================================================
     * 2. QUAN HỆ TÁC GIẢ GỬI MINH CHỨNG (BELONGS TO UPLOADER)
     * ========================================================================
     * Nhận diện chủ thể nộp bằng chứng (có thể là Chủ sở hữu xe hoặc Khách thuê).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}