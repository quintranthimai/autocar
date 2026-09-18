<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ============================================================================
 * LỚP THÔNG BÁO DUYỆT ĐƠN THÀNH CÔNG (BOOKING APPROVED NOTIFICATION)
 * ============================================================================
 * Thông điệp chúc mừng và đôn đốc Khách hàng sau khi Chủ xe chấp thuận yêu cầu
 * thuê phương tiện. Tuyên cáo hạn mức 2 giờ để thanh toán cọc giữ chỗ qua cả hai
 * kênh biểu tượng ứng dụng (Database) và hòm thư điện tử (Mail) nếu kích hoạt.
 */
class BookingApprovedNotification extends Notification
{
    use Queueable;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): GẮN KẾT ĐƠN THUÊ XE
     * ========================================================================
     * Ghi nhận hồ sơ chuyến đi được duyệt vào bộ nhớ tạm thời của thông điệp.
     *
     * @param  \App\Models\Booking  $booking  Đối tượng Đơn đặt xe đã được phê duyệt.
     */
    public function __construct(private readonly Booking $booking)
    {
    }

    /**
     * ========================================================================
     * 2. HÀM ĐỊNH TUYẾN KÊNH TRUYỀN (VIA): CẤU HÌNH PHÁT SÓNG NỘI TRÌNH
     * ========================================================================
     * Định tuyến thông điệp chuyển tiếp sang lưu trữ tại Database của ứng dụng.
     *
     * @param  object  $notifiable  Đối tượng Khách hàng thụ hưởng cảnh báo.
     * @return array<int, string>   Mảng chứa kênh thi hành ('database').
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * ========================================================================
     * 3. HÀM CHUYỂN HOÁN DỮ LIỆU CẢNH BÁO (TO ARRAY): ĐÓNG GÓI THỤ LIỆU APP
     * ========================================================================
     * Tổng hợp tên Hãng và Dòng xe, đóng gói thông thông báo thúc giục thanh toán
     * kèm đường link định hướng chuyển tới giao diện Trình chiếu Chuyến đi.
     *
     * @param  object  $notifiable  Khách thuê xe nhận thông điệp.
     * @return array<string, mixed> Mảng thuộc tính phát thải chuông thông báo.
     */
    public function toArray(object $notifiable): array
    {
        $vehicleName = trim(($this->booking->vehicle?->carModel?->brand_name ?? '') . ' ' . ($this->booking->vehicle?->carModel?->model_name ?? ''));
        $vehicleName = $vehicleName !== '' ? $vehicleName : 'xe bạn đã yêu cầu';

        return [
            'booking_id' => $this->booking->id,
            'title'      => 'Yêu cầu thuê xe đã được duyệt',
            'message'    => "Chủ xe đã đồng ý cho thuê {$vehicleName}. Vui lòng thanh toán trong vòng 2 giờ để giữ chỗ.",
            'to'         => '/trip-details/' . $this->booking->id,
            'type'       => 'success',
        ];
    }

    /**
     * ========================================================================
     * 4. HÀM CẤU KẾT EMAIL HIỂN THỊ (TO MAIL): SOẠN THẢO THƯ TRUY ĐẠT NỘI DUNG
     * ========================================================================
     * Dựng cấu trúc thư điện tử gửi đến hòm thư cá nhân Khách thuê với trỏ nút
     * điều hướng trực tiếp đến hệ sinh thái Sàn (Frontend URL) để thanh toán ngay.
     *
     * @param  object  $notifiable  Người nhận tin thư.
     * @return \Illuminate\Notifications\Messages\MailMessage Đơn vị Thư tín Laravel.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $vehicleName = trim(($this->booking->vehicle?->carModel?->brand_name ?? '') . ' ' . ($this->booking->vehicle?->carModel?->model_name ?? ''));
        $vehicleName = $vehicleName !== '' ? $vehicleName : 'xe ban da yeu cau';

        return (new MailMessage)
            ->subject('[AutoCar] Chu xe da duyet yeu cau dat xe')
            ->greeting('Xin chao ' . ($notifiable->name ?? 'ban') . ',')
            ->line('Yeu cau dat xe #' . $this->booking->id . ' da duoc chu xe duyet.')
            ->line('Xe: ' . $vehicleName)
            ->line('Trang thai hien tai: Cho thanh toan coc (pending_payment).')
            ->line('Vui long thanh toan trong vong 2 gio de giu cho thanh cong.')
            ->action('Xem chi tiet chuyen di', env('FRONTEND_URL', 'http://localhost:5173') . '/trip-details/' . $this->booking->id)
            ->line('Cam on ban da su dung AutoCar.');
    }
}
