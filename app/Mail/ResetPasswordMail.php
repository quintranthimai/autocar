<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ============================================================================
 * LỚP THỰC HOÁN EMAIL KHÔI PHỤC MẬT KHẨU (RESET PASSWORD MAILABLE)
 * ============================================================================
 * Đơn vị đóng gói và điều phối gửi thông điệp điện tử (E-mail) hướng dẫn khôi
 * phục lại mật khẩu tài khoản Người dùng Sàn Thuê Xe. Truyền tải an toàn Token
 * xác thực sang Giao diện HTML (Email View) và tích hợp tương thích Hàng đợi ngầm.
 */
class ResetPasswordMail extends Mailable
{
    // Tích hợp khả năng luân chuyển qua Queue ngầm & phục hồi đối tượng tuần tự
    use Queueable, SerializesModels;

    /**
     * Mã chuỗi Token bảo mật xác thực yêu cầu khôi phục mật khẩu
     * @var string
     */
    public $token;

    /**
     * ========================================================================
     * 1. HÀM KHỞI TẠO (CONSTRUCTOR): TIÊM TRÍCH DỮ LIỆU TOKEN GIAO TIẾP
     * ========================================================================
     * Gắn kết Token bảo mật từ hệ thống xác thực vào thuộc tính đại diện thư.
     *
     * @param string  $token  Mã Token khôi phục mật khẩu cá nhân hóa.
     */
    public function __construct($token)
    {
        $this->token = $token;
    }

    /**
     * ========================================================================
     * 2. HÀM THƯ NGỎ (ENVELOPE): CẤU HÌNH TIÊU ĐỀ V THÔNG TIN NHẬN DIỆN
     * ========================================================================
     * Thiết lập cấu hình lớp vỏ bao bì Email (tiêu đề hiển thị trong hòm thư).
     *
     * @return \Illuminate\Mail\Mailables\Envelope  Đối tượng tiêu đề mang thương hiệu AutoCar.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[AutoCar] Yêu cầu khôi phục mật khẩu',
        );
    }

    /**
     * ========================================================================
     * 3. HÀM NỘI DUNG (CONTENT): THAM ĐỊNH KHUNG HIỂN THỊ TRỊ TRỢ GIAO DIỆN
     * ========================================================================
     * Liên kết thông điệp với tệp bản mẫu hiển thị Blade HTML `emails.reset_password`.
     *
     * @return \Illuminate\Mail\Mailables\Content  Đối tượng chứa đường dẫn bản mẫu giao diện.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.reset_password', 
        );
    }
}