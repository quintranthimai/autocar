<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ============================================================================
 * LỚP THÔNG BÁO TỪ CHỐI ĐƠN THUÊ XE (BOOKING REJECTED NOTIFICATION)
 * ============================================================================
 * Thông điệp phản hồi khẩn cấp gửi tới Khách hàng trong trường hợp Chủ xe từ
 * chối tiếp nhận chuyến đi. Trình bày minh bạch lý do từ chối (nếu có) và tích
 * cực điều hướng Khách hàng trở lại kho xe để tham quan và đặt chiếc khác.
 */
class BookingRejectedNotification extends Notification
{
    use Queueable;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): NHẬN TRUY CHI ĐƠN THỰC THI BẤT THÀNH
     * ========================================================================
     * Khởi tạo thông điệp từ chối kèm tham chiếu tới Đơn đặt xe liên hợp.
     *
     * @param  \App\Models\Booking  $booking  Đối tượng Đơn đặt xe mang trạng thái bị từ chối.
     */
    public function __construct(private readonly Booking $booking)
    {
    }

    /**
     * ========================================================================
     * 2. HÀM ĐỊNH TUYẾN KÊNH TRUYỀN (VIA): THI KẾ KIỂM KIỆM KÊNH TRẢ RA
     * ========================================================================
     * Chỉ định luồng phát thông báo qua Hệ quản trị Cơ sở dữ liệu nội bộ.
     *
     * @param  object  $notifiable  Khách thuê thụ hưởng lời thoái trả.
     * @return array<int, string>   Kênh tín hiệu ('database').
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * ========================================================================
     * 3. HÀM CHUYỂN HOÁN DỮ LIỆU CẢNH BÁO (TO ARRAY): ĐÓNG GÓI THỤ CHI TIN TỨC
     * ========================================================================
     * Gắn thẻ mức nguy hại ('danger') kèm lý do cụ thể, chuyển hướng Khách quay
     * về trang Danh mục xe tiêu biểu (`/vehicles`) nhằm tiếp diễn nỗ lực mua sắm.
     *
     * @param  object  $notifiable  Người dùng Khách hàng.
     * @return array<string, mixed> Mảng nội trình chuông báo cáo.
     */
    public function toArray(object $notifiable): array
    {
        $vehicleName = trim(($this->booking->vehicle?->carModel?->brand_name ?? '') . ' ' . ($this->booking->vehicle?->carModel?->model_name ?? ''));
        $vehicleName = $vehicleName !== '' ? $vehicleName : 'xe bạn đã yêu cầu';

        return [
            'booking_id' => $this->booking->id,
            'title'      => 'Yêu cầu thuê xe bị từ chối',
            'message'    => "Rất tiếc, chủ xe đã từ chối yêu cầu thuê {$vehicleName}. Lý do: " . ($this->booking->cancel_reason ?? 'Không có lý do cụ thể.'),
            'to'         => '/vehicles',
            'type'       => 'danger',
        ];
    }

    /**
     * ========================================================================
     * 4. HÀM CẤU KẾT EMAIL HIỂN THỊ (TO MAIL): BẢN MẪU THƯ CHIA SẺ NGUYÊN DO
     * ========================================================================
     * Dựng phác thảo văn bản E-mail an ủi Người dùng và đưa ra nút kêu gọi
     * "Tìm xe khác" kết nối thẳng tới đường dẫn tổng đài phương tiện.
     *
     * @param  object  $notifiable  Khách thuê xe nhận thư.
     * @return \Illuminate\Notifications\Messages\MailMessage Gói tin thư tín Laravel.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $vehicleName = trim(($this->booking->vehicle?->carModel?->brand_name ?? '') . ' ' . ($this->booking->vehicle?->carModel?->model_name ?? ''));
        $vehicleName = $vehicleName !== '' ? $vehicleName : 'xe ban da yeu cau';

        return (new MailMessage)
            ->subject('[AutoCar] Yeu cau dat xe da bi tu choi')
            ->greeting('Xin chao ' . ($notifiable->name ?? 'ban') . ',')
            ->line('Yeu cau dat xe #' . $this->booking->id . ' da bi chu xe tu choi.')
            ->line('Xe: ' . $vehicleName)
            ->line('Ly do tu choi: ' . ($this->booking->cancel_reason ?? 'Khong co ly do cu the.'))
            ->action('Tim xe khac', env('FRONTEND_URL', 'http://localhost:5173') . '/vehicles')
            ->line('Ban co the tao yeu cau moi voi xe khac trong danh sach.');
    }
}
