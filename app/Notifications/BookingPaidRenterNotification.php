<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\Booking;

/**
 * ============================================================================
 * LỚP THÔNG BÁO HOÀN TẤT TRẢ PHÍ KHÁCH THUÊ (BOOKING PAID RENTER NOTIFICATION)
 * ============================================================================
 * Thông điệp chúc mừng và thông báo xác nhận tự động gửi về Khách thuê xe ngay
 * sau khi họ thanh toán thành công (cắt ví nội bộ hoặc qua cổng VNPay), khuyên
 * Khách chủ động liên lạc với Chủ xe để chốt địa điểm và nhận xe suôn sẻ.
 */
class BookingPaidRenterNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Hồ sơ đơn đặt xe đã hoàn thành nghĩa vụ tài chính ban đầu
     * @var Booking
     */
    public $booking;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): NHẬN BỘ THAM TIÊU HỘI TRÌNH
     * ========================================================================
     * Tiêm trích dữ liệu Đơn đặt xe thành công vào cấu trúc bản tin.
     *
     * @param Booking  $booking  Đối tượng Đơn thuê xe hiện hành.
     */
    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    /**
     * ========================================================================
     * 2. HÀM ĐỊNH TUYẾN KÊNH TRUYỀN (VIA): CHỈ TRỢ GIAO THỨC TRUY TRỢ CẢNH BÁO
     * ========================================================================
     * Cho phép bản tin chuyển sang lưu giữ trong Bảng cơ sở dữ liệu (`notifications`).
     *
     * @param  object  $notifiable  Khách hàng thụ hưởng tin báo.
     * @return array<int, string>   Mảng tham trị giao thức truyền tải ('database').
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * ========================================================================
     * 3. HÀM CHUYỂN HOÁN DỮ LIỆU (TO ARRAY): SOẠN V BIỂU Đ T PHẢN HIỆN
     * ========================================================================
     * Chuẩn hóa tên thương hiệu xe, gán liên kết chuyển đến Giao diện quản trị
     * Hành trình của Khách hàng (`/trip-details/ID`) để xem biên Lai và liên lạc.
     *
     * @param  object  $notifiable  Khách thuê phương tiện.
     * @return array<string, mixed> Mảng cấu trúc tin phát chuông bão.
     */
    public function toArray(object $notifiable): array
    {
        $vehicleName = trim(($this->booking->vehicle?->carModel?->brand_name ?? '') . ' ' . ($this->booking->vehicle?->carModel?->model_name ?? ''));

        return [
            'booking_id' => $this->booking->id,
            'title'      => 'Thanh toán thành công',
            'message'    => "Bạn đã thanh toán thành công cho yêu cầu thuê xe {$vehicleName}. Hãy liên hệ chủ xe để chuẩn bị nhận xe nhé!",
            'to'         => '/trip-details/' . $this->booking->id,
            'type'       => 'success',
        ];
    }
}
