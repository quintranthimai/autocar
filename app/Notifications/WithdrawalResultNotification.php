<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\WithdrawalRequest;

/**
 * ============================================================================
 * LỚP THÔNG BÁO KẾT QUẢ RÚT TIỀN VÍ (WITHDRAWAL RESULT NOTIFICATION)
 * ============================================================================
 * Thông điệp phán quyết cuối cùng từ Ban Quản trị thông báo kết quả chi thâu
 * dòng tiền (Giải ngân thành công hoặc Từ chối kèm việc lập tức trả tiền về ví).
 * Định hướng rõ ràng Người dùng về Giao diện Quản trị Ví cá nhân để kiểm tra số dư.
 */
class WithdrawalResultNotification extends Notification
{
    use Queueable;

    /**
     * Hồ sơ yêu cầu rút tiền
     * @var WithdrawalRequest
     */
    protected $withdrawalRequest;

    /**
     * Quyết định của Quản trị viên ('approved' hoặc 'rejected')
     * @var string
     */
    protected $action;

    /**
     * Nguyên do bác bỏ lệnh rút (nếu thất bại)
     * @var string|null
     */
    protected $rejectReason;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): LƯU KÝ PHÁN QUYẾT T CHÍNH BỘ KIỀM
     * ========================================================================
     * Tiêm trích tài liệu Rút tiền, phán quyết và giải thích vào lõi thông điệp.
     *
     * @param WithdrawalRequest $withdrawalRequest  Hồ sơ chi tiêu rút tiền.
     * @param string            $action             Phán quyết xử lý ('approved'/'rejected').
     * @param string|null       $rejectReason       Lý do chi tiết khi từ chối yêu cầu.
     */
    public function __construct(WithdrawalRequest $withdrawalRequest, $action, $rejectReason = null)
    {
        $this->withdrawalRequest = $withdrawalRequest;
        $this->action = $action;
        $this->rejectReason = $rejectReason;
    }

    /**
     * ========================================================================
     * 2. HÀM ĐỊNH TUYẾN KÊNH TRUYỀN (VIA): CẤU HÌNH PHÁT SÓNG NỘI TRÌNH
     * ========================================================================
     * Lưu giữ chứng tích phản hồi tại CSDL thông báo nền tảng.
     *
     * @param  object  $notifiable  Khách hàng/Chủ xe rút tiền.
     * @return array<int, string>   Mảng kênh phát chuông ('database').
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * ========================================================================
     * 3. HÀM CHUYỂN HOÁN DỮ LIỆU (TO ARRAY): ĐÓNG GÓI BẢN V BIỂU PHẢN HOÁN
     * ========================================================================
     * - Nếu `approved`: Gửi tín hiệu hoan hỷ (`success`) chúc mừng chuyển khoản thành công.
     * - Nếu `rejected`: Báo trước nguyên nhân lỗi (`error`), đồng thời củng cố niềm
     *   tin bằng châm ngôn "Tiền đã được hoàn lại vào ví" để tránh lo âu lầm lỗi.
     *
     * @param  object  $notifiable  Tài khoản đối tác xin giải ngân.
     * @return array<string, mixed> Mảng giá trị cảnh báo thông báo ứng dụng.
     */
    public function toArray(object $notifiable): array
    {
        if ($this->action === 'approved') {
            return [
                'title'   => 'Rút tiền thành công',
                'message' => "Yêu cầu rút tiền của bạn đã được admin duyệt và chuyển khoản.",
                'amount'  => number_format($this->withdrawalRequest->amount, 0, ',', '.') . 'đ',
                'to'      => '/wallet', 
                'type'    => 'success',
            ];
        } else {
            return [
                'title'   => 'Rút tiền bị từ chối',
                'message' => "Yêu cầu rút tiền của bạn đã bị từ chối. Lý do: {$this->rejectReason}. Tiền đã được hoàn lại vào ví.",
                'amount'  => number_format($this->withdrawalRequest->amount, 0, ',', '.') . 'đ',
                'to'      => '/wallet', 
                'type'    => 'error',
            ];
        }
    }
}
