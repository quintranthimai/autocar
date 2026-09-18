<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use App\Models\Booking;

/**
 * ============================================================================
 * LỚP THÔNG BÁO ĐƠN HOÀN TẤT THANH TOÁN (BOOKING PAID NOTIFICATION FOR OWNER)
 * ============================================================================
 * Thông điệp tin nhắn chúc mừng gửi thẳng đến Chủ sở hữu xe ngay lúc Khách
 * hàng thanh toán cọc hoặc trả phí hoàn tất qua VNPay/Ví điện tử. Đốc thúc Chủ xe
 * thực hiện bảo dưỡng và chu đáo chuẩn bị phương tiện bàn giao đúng cam kết.
 */
class BookingPaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Bản ghi Đơn đặt xe đã chuyển trạng thái thanh toán thành công
     * @var Booking
     */
    public $booking;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): GẮN CHI TRÌNH ĐƠN THANH LỢI
     * ========================================================================
     * Nhận và lưu lại thông tin Đơn hàng vừa có giao dịch tiền gửi hoàn tất.
     *
     * @param Booking  $booking  Đối tượng Đơn thuê xe đã thanh toán cọc/toàn phần.
     */
    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    /**
     * ========================================================================
     * 2. HÀM ĐỊNH TUYẾN KÊNH TRUYỀN (VIA): CẤU HÌNH TIẾP DẪN KÊNH TRẢ RA
     * ========================================================================
     * Chỉ định truyền dẫn thông tin qua Bảng cơ sở dữ liệu để hiển thị trên App.
     *
     * @param  object  $notifiable  Chủ xe thụ hưởng tin nhắn.
     * @return array<int, string>   Mảng cấu hình gởi qua CSDL.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * ========================================================================
     * 3. HÀM CHUYỂN HOÁN DỮ LIỆU (TO ARRAY): CẤU HÌNH NỘI TRÌNH CHÚC MỪNG CHỦ XE
     * ========================================================================
     * Định chuẩn tiêu đề tích cực ('success'), điều hướng trực tiếp sang trang
     * quản lý xe của Đối tác (`/partner/my-cars`) nhằm hỗ trợ kiểm tra và chu đáo
     * sắp xếp lịch ra xe.
     *
     * @param  object  $notifiable  Chủ sở hữu phương tiện.
     * @return array<string, mixed> Mảng giá trị cảnh báo.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'title'      => 'Khách đã thanh toán thành công',
            'message'    => "Khách hàng {$this->booking->renter->name} đã thanh toán cho chuyến đi #{$this->booking->id}. Bạn hãy chuẩn bị giao xe đúng hẹn nhé!",
            'to'         => '/partner/my-cars',
            'type'       => 'success',
        ];
    }
}
