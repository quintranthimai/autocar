<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\LegalDocument;

/**
 * ============================================================================
 * LỚP THÔNG BÁO KẾT QUẢ THẨM ĐỊNH KYC (KYC RESULT NOTIFICATION)
 * ============================================================================
 * Thông điệp thông báo phán quyết của Quản trị viên (Admin) về quy trình xác
 * minh danh tính (Căn cước công dân hoặc Giấy phép lái xe). Xử lý phân luồng lời
 * nhắn chúc mừng (approved) hoặc bác bỏ (rejected) kèm chú thích yêu cầu bổ sung.
 */
class KycResultNotification extends Notification
{
    use Queueable;

    /**
     * Tài liệu pháp lý vừa được xử lý kiểm duyệt
     * @var LegalDocument
     */
    protected $document;

    /**
     * Quyết định của Ban quản trị ('approved' hoặc 'rejected')
     * @var string
     */
    protected $action;

    /**
     * Lý do từ chối hồ sơ khi xác minh thất bại (nếu có)
     * @var string|null
     */
    protected $rejectReason;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): TIÊM TRÍCH DẪN V PHÁN QUYẾT ADMIN
     * ========================================================================
     * Lưu trữ hồ sơ định danh và nguyên do thẩm định vào bản tin.
     *
     * @param LegalDocument $document      Hồ sơ giấy tờ pháp lý KYC.
     * @param string        $action        Kết quả phán quyết ('approved'/'rejected').
     * @param string|null   $rejectReason  Lời giải thích lý do phủ quyết (khi bác bỏ).
     */
    public function __construct(LegalDocument $document, $action, $rejectReason = null)
    {
        $this->document = $document;
        $this->action = $action;
        $this->rejectReason = $rejectReason;
    }

    /**
     * ========================================================================
     * 2. HÀM ĐỊNH TUYẾN KÊNH TRUYỀN (VIA): CẤU HÌNH GIAO NỘI CẢNH BÁO
     * ========================================================================
     * Lưu trữ vĩnh cửu trạng thái thông điệp tại Hệ cơ sở dữ liệu.
     *
     * @param  object  $notifiable  Chủ tài khoản gửi thẩm định.
     * @return array<int, string>   Mảng kênh gửi tin ('database').
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * ========================================================================
     * 3. HÀM CHUYỂN HOÁN DỮ LIỆU (TO ARRAY): PHÂN TIỂU THÔNG TIN PHẢN HOÁN
     * ========================================================================
     * - Nếu `approved`: Định hướng người dùng tới trang thông tin cá nhân (`/profile`)
     *   kèm chúc mừng hợp pháp hóa tài khoản thành công (`success`).
     * - Nếu `rejected`: Nêu tường tận nguyên do sa lầy và mở hướng quay lại cổng
     *   xác minh (`/kyc/verification`) để chỉnh sửa và tái nộp (`error`).
     *
     * @param  object  $notifiable  Người sử dụng ứng dụng.
     * @return array<string, mixed> Mảng giá trị cảnh báo gửi Front-end.
     */
    public function toArray(object $notifiable): array
    {
        $docName = $this->document->document_type === 'id_card' ? 'Căn cước công dân' : 'Giấy phép lái xe';

        if ($this->action === 'approved') {
            return [
                'title'   => 'Xác thực thành công',
                'message' => "Hồ sơ {$docName} của bạn đã được phê duyệt thành công.",
                'to'      => '/profile', 
                'type'    => 'success',
            ];
        } else {
            return [
                'title'   => 'Xác thực thất bại',
                'message' => "Hồ sơ {$docName} của bạn đã bị từ chối. Lý do: {$this->rejectReason}. Vui lòng cập nhật lại.",
                'to'      => '/kyc/verification', 
                'type'    => 'error',
            ];
        }
    }
}
