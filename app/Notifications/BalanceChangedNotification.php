<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Transaction;

/**
 * ============================================================================
 * LỚP THÔNG BÁO BIẾN ĐÔNG TÀI KHOẢN VÍ (BALANCE CHANGED NOTIFICATION)
 * ============================================================================
 * Thông điệp cảnh báo thời gian thực khi xảy ra giao dịch thay đổi số dư ví của
 * Người dùng (cộng tiền thành công hoặc bị trừ tiền chốt cọc/thanh toán), được
 * tự động lưu vào cơ sở dữ liệu để hiển thị trên quả chuông thông báo ứng dụng.
 */
class BalanceChangedNotification extends Notification
{
    // Tích hợp khả năng đẩy thông báo qua luồng hàng đợi ngầm (Queue)
    use Queueable;

    /**
     * Đối tượng Giao dịch tài chính mang nguyên do biến động
     * @var Transaction
     */
    protected $transaction;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): TIÊM TRÍCH CHI BẬN GIAO DỊCH
     * ========================================================================
     * Khởi tạo đối tượng thông điệp kèm bản ghi giao dịch biến động số dư.
     *
     * @param Transaction  $transaction  Đối tượng giao dịch tài chính vừa thi hành.
     */
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    /**
     * ========================================================================
     * 2. HÀM ĐỊNH TUYẾN KÊNH TRUYỀN (VIA): CẤU HÌNH GIAO THỨC PHÁT TRUYỆN
     * ========================================================================
     * Chỉ định thông báo được lưu trữ an toàn trực tiếp trong Bảng Cơ sở dữ liệu.
     *
     * @param  object  $notifiable  Đối tượng (User/Partner) nhận cảnh báo.
     * @return array<int, string>   Danh sách kênh truyền tải (mặc định 'database').
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * ========================================================================
     * 3. HÀM CHUYỂN HOÁN DỮ LIỆU (TO ARRAY): ĐÓNG GÓI THÔNG ĐIỆP JSON CẢNH BÁO
     * ========================================================================
     * Định danh tiêu đề (Nhận tiền / Trừ tiền) theo thuộc tính (`credit` / `debit`),
     * định dạng con số VNĐ chuẩn hóa và trả về cấu trúc mảng cho Frontend hiển thị.
     *
     * @param  object  $notifiable  Đối tượng thụ hưởng thông báo.
     * @return array<string, mixed> Mảng thông tin chi tiết phát lưu SQL.
     */
    public function toArray(object $notifiable): array
    {
        $title = $this->transaction->type === 'credit' ? 'Nhận tiền vào ví' : 'Trừ tiền từ ví';
        $amountStr = number_format($this->transaction->amount, 0, ',', '.') . ' VNĐ';

        return [
            'booking_id'     => $this->transaction->booking_id,
            'transaction_id' => $this->transaction->id,
            'title'          => $title,
            'message'        => $this->transaction->description,
            'amount'         => $amountStr,
            'type'           => $this->transaction->type,   // credit/debit
            'status'         => $this->transaction->status  // success/pending/failed
        ];
    }
}
