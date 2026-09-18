<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ============================================================================
 * LỚP THÔNG BÁO ĐỐC THÚC THANH TOÁN (BOOKING PAYMENT REMINDER NOTIFICATION)
 * ============================================================================
 * Bản tin nhắc nhở khẩn cấp (mức độ warning) phát đi cho Khách thuê khi thời
 * điểm duyệt đơn đã cận kề mốc giới hạn hủy 2 giờ nhưng hệ thống chưa ghi nhận
 * dòng tiền thanh toán cọc/toàn phần từ Người sử dụng.
 */
class BookingPaymentReminderNotification extends Notification
{
    use Queueable;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): GẮN ĐƠN HÀNG TR ONG DIỆN NGUY CẢNH HỦY
     * ========================================================================
     * Tiêm trích đối tượng Đơn đặt xe chậm thanh toán vào lõi thông báo.
     *
     * @param  \App\Models\Booking  $booking  Đơn đặt xe mang trạng thái `pending_payment`.
     */
    public function __construct(private readonly Booking $booking)
    {
    }

    /**
     * ========================================================================
     * 2. HÀM ĐỊNH TUYẾN KÊNH TRUYỀN (VIA): CẤU HÌNH LUỒNG CẢNH BÁO BỘ KIỀM
     * ========================================================================
     * Định tuyến lưu giữ tin báo trong CSDL để sáng chuông thông báo trên nền tảng.
     *
     * @param  object  $notifiable  Khách hàng chậm thanh toán.
     * @return array<int, string>   Mảng kênh phát sóng ('database').
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * ========================================================================
     * 3. HÀM CHUYỂN HOÁN DỮ LIỆU CẢNH BÁO (TO ARRAY): ĐÓNG GÓI THỤ CHI TÍN
     * ========================================================================
     * Gán cờ báo nguy (type = 'warning'), đôn đốc người dùng nhấn vào đường dẫn
     * về chi tiết Đơn hàng để tiếp tục lộ trình thanh toán giữ chỗ hợp lệ.
     *
     * @param  object  $notifiable  Người dùng Khách hàng.
     * @return array<string, mixed> Mảng giá trị cảnh báo quá trình thu hồi cọc.
     */
    public function toArray(object $notifiable): array
    {
        $vehicleName = trim(($this->booking->vehicle?->carModel?->brand_name ?? '') . ' ' . ($this->booking->vehicle?->carModel?->model_name ?? ''));
        $vehicleName = $vehicleName !== '' ? $vehicleName : 'xe bạn đã yêu cầu';

        return [
            'booking_id' => $this->booking->id,
            'title'      => 'Nhắc nhở thanh toán',
            'message'    => "Bạn còn chưa thanh toán cho yêu cầu thuê {$vehicleName}. Vui lòng thanh toán sớm để giữ chỗ.",
            'to'         => '/trip-details/' . $this->booking->id,
            'type'       => 'warning',
        ];
    }

    /**
     * ========================================================================
     * 4. HÀM CẤU KẾT EMAIL ĐỐC THÚC (TO MAIL): SOẠN THƯ TÍN CẢNH BÁO QUÁ HẠN
     * ========================================================================
     * Khai triển nội dung thư khẩn cấp gởi ra Hòm thư Người dùng cảnh báo rõ ràng
     * về quy tắc tự động hủy sau 2 giờ nếu họ tiếp tục phó mặc không thao tác.
     *
     * @param  object  $notifiable  Người dùng thụ hưởng lời nhắn.
     * @return \Illuminate\Notifications\Messages\MailMessage Đối tượng thư tín Laravel.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $vehicleName = trim(($this->booking->vehicle?->carModel?->brand_name ?? '') . ' ' . ($this->booking->vehicle?->carModel?->model_name ?? ''));
        $vehicleName = $vehicleName !== '' ? $vehicleName : 'xe ban da yeu cau';

        return (new MailMessage)
            ->subject('[AutoCar] Nhac thanh toan coc cho don dat xe')
            ->greeting('Xin chao ' . ($notifiable->name ?? 'ban') . ',')
            ->line('Don dat xe #' . $this->booking->id . ' cua ban van dang cho thanh toan coc.')
            ->line('Xe: ' . $vehicleName)
            ->line('Vui long hoan tat thanh toan trong 2 gio ke tu luc chu xe duyet de giu cho thanh cong.')
            ->action('Thanh toan ngay', env('FRONTEND_URL', 'http://localhost:5173') . '/trip-details/' . $this->booking->id)
            ->line('Neu qua han, he thong se tu dong huy don theo quy dinh.');
    }
}
