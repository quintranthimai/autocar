<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Booking;

/**
 * ============================================================================
 * LỚP THÔNG BÁO YÊU CẦU ĐẶT XE MỚI (NEW BOOKING REQUEST NOTIFICATION)
 * ============================================================================
 * Tín hiệu khẩn báo phát ngay đến hòm thông báo của Chủ sở hữu khi phương
 * tiện của họ nhận được một yêu cầu thuê từ Khách hàng. Đốc thúc Chủ xe phản hồi
 * thần tốc do quy định cam kết tự động hủy nếu không duyệt trong vòng 2 giờ.
 */
class NewBookingRequestNotification extends Notification
{
    use Queueable;

    /**
     * Hồ sơ đơn yêu cầu thuê xe mới tạo
     * @var Booking
     */
    protected $booking;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): GẮN DỮ LIỆU ĐƠN THUÊ XE
     * ========================================================================
     * Ghi nhận đối tượng Đơn thuê xe mang trạng thái `pending_approval`.
     *
     * @param Booking  $booking  Đơn đặt xe mới được khởi tạo thành công.
     */
    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    /**
     * ========================================================================
     * 2. HÀM ĐỊNH TUYẾN KÊNH TRUYỀN (VIA): CẤU HÌNH GIAO THỨC TẠI CHỖ
     * ========================================================================
     * Truyền tải và khắc ghi tín hiệu trực tiếp vào Bảng CSDL (Database).
     *
     * @param  object  $notifiable  Chủ xe đối tác thụ hưởng yêu cầu.
     * @return array<int, string>   Mảng kênh ghi nhận ('database').
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * ========================================================================
     * 3. HÀM CHUYỂN HOÁN DỮ LIỆU (TO ARRAY): CẤU HÌNH NỘI DUNG ĐỐC THÚC ĐỐI TÁC
     * ========================================================================
     * Cung cấp lời nhắc rõ ràng về thời hạn 2 giờ, đồng thời mở liên kết điều
     * hướng trực diện về tab Danh sách yêu cầu mới (`/partner/my-cars?tab=requests`)
     * giúp Đối tác xét duyệt nhanh chóng và hiệu quả.
     *
     * @param  object  $notifiable  Chủ xe.
     * @return array<string, mixed> Mảng giá trị cảnh báo thông báo ứng dụng.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'title'      => 'Có yêu cầu thuê xe mới',
            'message'    => "Xe của bạn vừa nhận được một yêu cầu thuê từ khách hàng. Vui lòng kiểm tra và phản hồi sớm (yêu cầu sẽ tự động hủy sau 2 giờ).",
            'to'         => '/partner/my-cars?tab=requests',
            'type'       => 'info',
        ];
    }
}
