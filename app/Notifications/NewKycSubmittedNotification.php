<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\User;

/**
 * ============================================================================
 * LỚP THÔNG BÁO HỒ SƠ KYC MỚI TẢI LÊN (NEW KYC SUBMITTED NOTIFICATION)
 * ============================================================================
 * Tín hiệu khẩn cấp chuyển phát thẳng đến Hệ thống Quản trị (Admin) mỗi khi
 * Người dùng hoặc Đối tác tải lên minh chứng giấy tờ pháp lý mới (CCCD hoặc
 * GPLX). Mời gọi Admin mau chóng xem xét và phê duyệt hồ sơ KYC.
 */
class NewKycSubmittedNotification extends Notification
{
    use Queueable;

    /**
     * Tài liệu cá nhân Người dùng nộp hồ sơ KYC
     * @var User
     */
    protected $user;

    /**
     * Phân loại văn bản định danh ('id_card' hoặc 'driver_license')
     * @var string
     */
    protected $documentType;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): NHẬN BẢN TẢI THÔNG TÍN KHÁCH NỘP
     * ========================================================================
     * Ghi luyến thông tin tác giả và thể loại hồ sơ và bản tin khẩn báo Admin.
     *
     * @param User   $user          Đối tượng Tài khoản người nộp hồ sơ.
     * @param string $documentType  Loại giấy tờ định danh tương ứng.
     */
    public function __construct(User $user, $documentType)
    {
        $this->user = $user;
        $this->documentType = $documentType;
    }

    /**
     * ========================================================================
     * 2. HÀM ĐỊNH TUYẾN KÊNH TRUYỀN (VIA): THIẾT HOÁN NỔI GỌI HỖ TRỢ
     * ========================================================================
     * Lưu vào cơ sở dữ liệu chuông báo của Ban Quản trị.
     *
     * @param  object  $notifiable  Quản trị viên (Admin).
     * @return array<int, string>   Kênh truyền CSDL ('database').
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * ========================================================================
     * 3. HÀM CHUYỂN HOÁN DỮ LIỆU (TO ARRAY): TRÌNH HIỆN LỘ TRÌNH KIỂM DUYỆT
     * ========================================================================
     * Dịch danh xưng từ mã ('id_card' -> Căn cước công dân), thiết lập đường dẫn
     * chuyển trúng tâm vào Cổng thẩm định hồ sơ Admin (`/admin/kyc-approvals`).
     *
     * @param  object  $notifiable  Tài khoản Quản trị viên thụ lý.
     * @return array<string, mixed> Mảng giá trị cảnh báo thông báo ứng dụng Admin.
     */
    public function toArray(object $notifiable): array
    {
        $docName = $this->documentType === 'id_card' ? 'Căn cước công dân' : 'Giấy phép lái xe';

        return [
            'title'   => 'Yêu cầu xác thực mới',
            'message' => "Người dùng {$this->user->name} vừa tải lên {$docName} mới. Vui lòng kiểm tra và phê duyệt.",
            'to'      => '/admin/kyc-approval', 
            'type'    => 'info',
        ];
    }
}
