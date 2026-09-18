<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & CÁC LỚP MÔ HÌNH (IMPORTS & MODELS)
// ============================================================================
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\BookingSurcharge;
use Illuminate\Support\Facades\Auth;

/**
 * BỘ ĐIỀU KHIỂN XỬ LÝ PHỤ PHÍ CHUYẾN ĐI (BOOKING SURCHARGE CONTROLLER)
 * Chuyên trách quản lý các yêu cầu khai báo phụ phí phát sinh từ Chủ xe khi kết thúc hợp đồng thuê
 * (Quá giờ, vượt giới hạn số km, vệ sinh xe, hoặc vi phạm giao thông/sửa chữa nhỏ).
 */
class BookingSurchargeController
{
    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC KHỞI TẠO VÀ KHÁCH NẠI PHỤ PHÍ (SURCHARGE MANAGEMENT)
    // ============================================================================

    /**
     * Ghi nhận yêu cầu thu phụ phí mới cho một chuyến đi cụ thể
     *
     * Quy tắc Kiểm duyệt:
     * 1. Thẩm định cú pháp: Loại phụ phí, số tiền hợp lệ và đường dẫn ảnh bằng chứng (nếu cần).
     * 2. Phân quyền thắt chặt: Chỉ Chủ sở hữu của phương tiện trong đơn thuê mới có quyền khởi tạo.
     * 3. Trạng thái thời gian thực: Chuyến đi chưa bị hoàn tát (completed) hoặc bị hủy (cancelled).
     *
     * @param Request $request Yêu cầu HTTP chứa payload dữ liệu (surcharge_type, amount, note, evidence_image_url)
     * @param int|string $bookingId ID của Đơn đặt xe cần gắn phụ phí
     * @return \Illuminate\Http\JsonResponse Phản hồi trạng thái xử lý
     */
    public function store(Request $request, $bookingId)
    {
        // [Bước 1]: Thẩm định và rà soát tính hợp lệ của dữ liệu đầu vào (Input Validation)
        $request->validate([
            'surcharge_type'     => 'required|string', // Danh mục cước phát sinh: over_limit (vượt km), cleaning (vệ sinh), late_return (quá giờ)
            'amount'             => 'required|numeric|min:0',
            'note'               => 'nullable|string',
            'evidence_image_url' => 'nullable|url', // Bắt buộc cung cấp đường dẫn ảnh chứng thực nếu là phí sửa chữa/vệ sinh
        ]);

        // [Bước 2]: Tìm kiếm hồ sơ chuyến đi tương ứng
        $booking = Booking::findOrFail($bookingId);

        // [Bước 3]: Thẩm định Quyền Hạn - Chỉ Chủ xe (owner_id) của chiếc xe này mới được quyền báo cáo phụ phí
        $userId = Auth::id();
        if ($booking->vehicle->owner_id !== $userId) {
            return response()->json(['success' => false, 'message' => 'Bạn không có quyền thao tác trên đơn này.'], 403);
        }

        // [Bước 4]: Rà soát trạng thái Chuyến đi - Chỉ được khai báo khi chuyến đi đang diễn ra hoặc chờ xác nhận trả xe
        if (in_array($booking->status, ['cancelled', 'completed'])) {
            return response()->json([
                'success' => false,
                'message' => 'Chuyến đi đã kết thúc hoặc bị hủy, không thể thêm phụ phí.'
            ], 400);
        }

        // [Bước 5]: Ghi nhận bản ghi Phụ phí vào cơ sở dữ liệu (Lưu lại cho khách kiểm chứng và đối chiếu thanh toán)
        $surcharge = BookingSurcharge::create([
            'booking_id'         => $booking->id,
            'surcharge_type'     => $request->surcharge_type,
            'amount'             => $request->amount,
            'note'               => $request->note,
            'evidence_image_url' => $request->evidence_image_url,
        ]);

        // [Bước 6]: Trả về kết quả xác thực thành công kèm dữ liệu đối tượng Phụ phí vừa tạo
        return response()->json([
            'success' => true,
            'message' => 'Đã ghi nhận phụ phí thành công!',
            'data'    => $surcharge
        ]);
    }
}