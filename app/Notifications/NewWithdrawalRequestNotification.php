<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\User;

/**
 * ============================================================================
 * LỚP THÔNG BÁO YÊU CẦU RÚT TIỀN MỚI (NEW WITHDRAWAL REQUEST NOTIFICATION)
 * ============================================================================
 * Tín hiệu chuông cảnh báo tới Bộ phận Tài chính (Admin) tại thời điểm Chủ
 * xe hoặc Khách hàng phát lệnh tất toán và rút thu nhập khỏi Ví nội bộ.
 * Cung cấp đầy đủ thông số con số tiền và đường dẫn chuyển tiếp nhanh tới giao diện.
 */
class NewWithdrawalRequestNotification extends Notification
{
    use Queueable;

    /**
     * Tài liệu cá nhân Người sử dụng yêu cầu rút tiền
     * @var User
     */
    protected $user;

    /**
     * Số tiền yêu cầu chuyển khoản
     * @var float|int
     */
    protected $amount;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): TIÊM NH CHỦ KẾ V KẾ MỤC RÚT
     * ========================================================================
     * Gắn thông số Người dùng và Số tiền giao dịch vào trường dữ liệu thông báo.
     *
     * @param User      $user    Tài khoản khởi tạo chỉ lệnh rút tiền.
     * @param float|int $amount  Giá trị số dư xin giải ngân.
     */
    public function __construct(User $user, $amount)
    {
        $this->user = $user;
        $this->amount = $amount;
    }

    /**
     * ========================================================================
     * 2. HÀM ĐỊNH TUYẾN KÊNH TRUYỀN (VIA): CẤU HÌNH KIỆM SOÁT HỢP TRẠI
     * ========================================================================
     * Lưu ký vào Cơ sở dữ liệu ứng dụng phục vụ thông báo khu Quản trị.
     *
     * @param  object  $notifiable  Tài khoản Quản trị viên (Admin).
     * @return array<int, string>   Mảng kênh dẫn thông cáo ('database').
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * ========================================================================
     * 3. HÀM CHUYỂN HOÁN DỮ LIỆU (TO ARRAY): TẠO HÌNH GÓI TIN ĐỊNH HƯỚNG ADMIN
     * ========================================================================
     * Định dạng tiền tệ chuẩn VNĐ (`amount`), dẫn thẳng nút bấm điều hướng
     * chuyển hướng tới trang xử lý giao dịch Quản trị viên (`/admin/withdrawal-management`).
     *
     * @param  object  $notifiable  Nhân viên quản trị hệ thống.
     * @return array<string, mixed> Mảng chuỗi cấu trúc chuông báo.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title'   => 'Yêu cầu rút tiền mới',
            'message' => "Người dùng {$this->user->name} vừa tạo yêu cầu rút tiền.",
            'amount'  => number_format($this->amount, 0, ',', '.') . 'đ',
            'to'      => '/admin/withdrawal-management', 
            'type'    => 'info',
        ];
    }
}
