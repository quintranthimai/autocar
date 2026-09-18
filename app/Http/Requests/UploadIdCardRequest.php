<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ============================================================================
 * LỚP XÁC THỰC BIỂU MẪU TẢI GIẤY TỜ TÙY THÂN (UPLOAD ID CARD REQUEST)
 * ============================================================================
 * Lớp rào chắn kiểm duyệt dữ liệu HTTP chuyên sâu phục vụ công tác nộp tệp ảnh
 * định danh Căn cước công dân (CCCD) hoặc Giấy phép lái xe cho Động cơ OCR AI.
 * Bảo đảm hai tệp ảnh mặt trước và mặt sau hoàn toàn tuân thủ thể thức chuẩn hóa
 * và nằm trong mức cho phép giới hạn dưới 10MB.
 */
class UploadIdCardRequest extends FormRequest
{
    /**
     * ========================================================================
     * 1. HÀM XÁC TRỪ THẨM QUYỀN (AUTHORIZE): THI VỊ DIỆN QUYỀN GIAO THỨC
     * ========================================================================
     * Xác định xem user có quyền gọi request này không.
     * Vì tuyến API đã được bảo vệ bằng middleware `auth:sanctum` ở `routes/api.php`
     * nên hệ thống cho phép thi hành kiểm tra biểu mẫu (return true).
     *
     * @return bool  Luôn trả về true (thông quan qua lớp Middleware Sanctum).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * ========================================================================
     * 2. HÀM LUẬT KIỂM DUYỆT (RULES): BỘ QUY TẮC RÀ SOÁT TỆP TRÌNH ẢNH
     * ========================================================================
     * Luật kiểm tra dữ liệu đầu vào:
     * - `front_image` & `back_image`: Bắt buộc đi kèm, thuộc nhóm file ảnh hợp pháp
     *   định dạng (JPG, JPEG, PNG), dung lượng trần tối đa không vượt quá 10240 KB (10MB).
     *
     * @return array  Mảng định chuẩn quy định xác thực dữ liệu.
     */
    public function rules(): array
    {
        return [
            'front_image' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'], // Tối đa 10MB
            'back_image'  => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ];
    }

    /**
     * ========================================================================
     * 3. HÀM THÔNG MẠI LỖI (MESSAGES): BẢNG DỊCH THUẬT LỖI NGHIỆP VỤ TIẾNG VIỆT
     * ========================================================================
     * Dịch câu thông báo lỗi sang Tiếng Việt để Frontend hiển thị cho đẹp và rõ
     * ràng, hướng dẫn giải quyết chính xác sự cố cho Khách hàng khi nộp hồ sơ.
     *
     * @return array  Mảng thông điệp chi tiết theo ký tự vi phạm.
     */
    public function messages(): array
    {
        return [
            'front_image.required' => 'Vui lòng tải lên ảnh mặt trước CCCD.',
            'back_image.required'  => 'Vui lòng tải lên ảnh mặt sau CCCD.',
            '*.image'              => 'File tải lên phải là định dạng hình ảnh.',
            '*.mimes'              => 'Chỉ chấp nhận ảnh định dạng JPG, JPEG hoặc PNG.',
            '*.max'                => 'Dung lượng ảnh không được vượt quá 10MB.',
        ];
    }
}