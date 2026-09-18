<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ============================================================================
 * LỚP XÁC THỰC BIỂU MẪU TẢI BẰNG CHỨNG (STORE EVIDENCE REQUEST)
 * ============================================================================
 * Lớp rào chắn kiểm duyệt tín hiệu HTTP cho tác vụ nộp ảnh/video bằng chứng lúc
 * giao nhận phương tiện. Bảo vệ máy chủ khỏi dữ liệu rác, kiểm soát quy cách
 * phân đoạn chuyến đi (`pickup`/`dropoff`) và khống chế dung lượng tệp tin dưới 20MB.
 */
class StoreEvidenceRequest extends FormRequest
{
    /**
     * ========================================================================
     * 1. HÀM XÁC TRỪ THẨM QUYỀN (AUTHORIZE): KIỂM DIỆN TÀI KHOẢN HỆ THỐNG
     * ========================================================================
     * Xác minh Người dùng gửi yêu cầu phải ở trạng thái đăng nhập hợp pháp trên
     * nền tảng (thông qua chứng thực Sanctum Token).
     *
     * @return bool  True nếu người dùng đã xác thực danh tính.
     */
    public function authorize(): bool
    {
        // Phân quyền: Đảm bảo chỉ User đã đăng nhập mới được upload
        return auth('sanctum')->check();
    }

    /**
     * ========================================================================
     * 2. HÀM LUẬT KIỂM DUYỆT (RULES): QUY CHUYỀN RÀ DIỆN THAM SỐ GIAO TRÌNH
     * ========================================================================
     * Thiết lập tập hợp bộ tiêu chuẩn xác thực đầu vào:
     * - `phase`: Buộc là một trong hai chặng giao nhận hợp lệ (`pickup` / `dropoff`).
     * - `file`: Bắt buộc là định dạng ảnh jpg, jpeg, png hoặc video mp4 (Max 20MB).
     *
     * @return array  Mảng quy tắc xác thực biểu mẫu Laravel.
     */
    public function rules(): array
    {
        return [
            'phase' => ['required', 'string', 'in:pickup,dropoff'],
            'file'  => ['required', 'file', 'mimes:jpg,jpeg,png,mp4', 'max:20480'], // Tối đa 20MB cho cả video/ảnh
        ];
    }

    /**
     * ========================================================================
     * 3. HÀM THÔNG MẠI LỖI (MESSAGES): TÙY CHUYỂN NGÔN TỪ PHẢN HỒI GIAO DIỆN
     * ========================================================================
     * Dịch thuật từ ngữ cảnh báo kỹ thuật sang câu cú Tiếng Việt rõ ràng, giúp
     * Frontend thi hành hiển thị thông báo lỗi thân thiện cho Người sử dụng.
     *
     * @return array  Mảng thông điệp tùy chỉnh tương ứng từng vi phạm luật.
     */
    public function messages(): array
    {
        return [
            'phase.in'   => 'Giai đoạn chụp ảnh chỉ được phép là lúc giao xe (pickup) hoặc trả xe (dropoff).',
            'file.mimes' => 'Hệ thống chỉ chấp nhận định dạng ảnh (JPG, PNG) hoặc video (MP4).',
            'file.max'   => 'Dung lượng file không được vượt quá 20MB.',
        ];
    }
}