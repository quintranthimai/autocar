<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ============================================================================
 * LỚP THÔNG BÁO HỦY ĐƠN ĐẶT XE (BOOKING CANCELLED NOTIFICATION)
 * ============================================================================
 * Thông điệp cảnh báo tức thời gửi tới các bên liên quan (Khách thuê hoặc Chủ xe)
 * ngay tại thời điểm chặng hành trình bị hủy ngang bởi đối phương hoặc tự động
 * thu hồi bởi Bộ máy giám sát quá hạn của hệ thống.
 */
class BookingCancelledNotification extends Notification
{
    use Queueable;

    /**
     * Nội dung lời giải thích nguyên nhân hủy hoặc thông điệp hủy
     * @var string
     */
    protected $message;

    /**
     * Mã định danh đơn thuê xe bị hủy
     * @var int|string
     */
    protected $bookingId;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): GẮN CHI V V THÔNG TIÊU THỤ HỦY
     * ========================================================================
     * Khởi tạo bản tin hủy kèm nội dung lời nhắn và ID Đơn hàng bị đình chỉ.
     *
     * @param string     $message    Nội dung chi tiết thông báo hủy.
     * @param int|string $bookingId  ID Đơn đặt xe liên đới.
     */
    public function __construct($message, $bookingId)
    {
        $this->message = $message;
        $this->bookingId = $bookingId;
    }

    /**
     * ========================================================================
     * 2. HÀM ĐỊNH TUYẾN KÊNH TRUYỀN (VIA): CẤU HÌNH TRUY TRẠI CẢNH BÁO
     * ========================================================================
     * Thiết lập chỉ phát cảnh báo qua CSDL ứng dụng (tránh cồn cáo qua Email).
     *
     * @param  object  $notifiable  Tài khoản đối tác hoặc người dùng nhận thông điệp.
     * @return array<int, string>   Kênh phát tải ('database').
     */
    public function via(object $notifiable): array
    {
        return ['database']; // Chỉ lưu vào database, không gửi email
    }

    /**
     * ========================================================================
     * 3. HÀM CHUYỂN HOÁN DỮ LIỆU (TO ARRAY): ĐÓNG GÓI BẢN V BIỂU TRÌNH JSON
     * ========================================================================
     * Định danh chuỗi dữ liệu đầu ra cho Giao diện quả chuông trên Frontend.
     *
     * @param  object  $notifiable  Người nhận cảnh báo.
     * @return array<string, mixed> Mảng giá trị chi tiết đơn và thông điệp.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->bookingId,
            'message'    => $this->message,
        ];
    }
}
