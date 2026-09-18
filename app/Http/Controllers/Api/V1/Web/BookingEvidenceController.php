<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & CÁC LỚP DỊCH VỤ (IMPORTS & SERVICES)
// ============================================================================
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Http\Requests\StoreEvidenceRequest;
use App\Services\Booking\EvidenceService;
use Illuminate\Http\JsonResponse;

/**
 * BỘ ĐIỀU KHIỂN QUẢN LÝ BẰNG CHỨNG GIAO TÁC (BOOKING EVIDENCE CONTROLLER)
 * Chuyên trách tiếp nhận, kiểm duyệt và lưu trữ hình ảnh/video bằng chứng (hiện trạng xe 360 độ) 
 * trong các giai đoạn bàn giao và thu hồi xe nhằm bảo vệ quyền lợi đôi bên.
 */
class BookingEvidenceController extends Controller
{
    /**
     * Dịch vụ xử lý logic lưu trữ và xác nhận bằng chứng
     * @var EvidenceService
     */
    protected $evidenceService;

    /**
     * Khởi tạo Bộ điều khiển và tiêm trích Dịch vụ Bằng chứng (Dependency Injection)
     *
     * @param EvidenceService $evidenceService
     */
    public function __construct(EvidenceService $evidenceService)
    {
        $this->evidenceService = $evidenceService;
    }

    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC TIẾP NHẬN & XỬ LÝ BẰNG CHỨNG (EVIDENCE ACTIONS)
    // ============================================================================

    /**
     * Tiếp nhận và xử lý tệp tin tải lên làm bằng chứng hiện trạng xe
     * 
     * - Thẩm định thông tin đơn đặt xe (Booking ID) và đối tượng tải lên (Renter / Owner).
     * - Delegate (ủy thác) toàn bộ logic xử lý tệp cho EvidenceService.
     * 
     * @param StoreEvidenceRequest $request Bộ thẩm định yêu cầu đầu vào (file ảnh, giai đoạn phase)
     * @param int|string $bookingId Mã định danh đơn đặt xe
     * @return JsonResponse Phản hồi JSON kết quả xử lý
     */
    public function store(StoreEvidenceRequest $request, $bookingId): JsonResponse
    {
        try {
            // [Bước 1]: Truy xuất thông tin Đơn thuê xe kèm thông tin phương tiện để kiểm tra sự tồn tại
            $booking = Booking::with('vehicle')->findOrFail($bookingId);

            // [Bước 2]: Xác định định danh người dùng đang thao tác (chứng thực qua Sanctum)
            $user = auth('sanctum')->user();

            // [Bước 3]: Ký gửi tệp tin cho tầng Dịch vụ (Service Layer) xử lý tải lên và ghi nhận CSDL
            $evidence = $this->evidenceService->uploadEvidence(
                $booking,
                $user,
                $request->validated('phase'),
                $request->file('file')
            );

            // [Bước 4]: Trả về kết quả thành công cho Client với HTTP Status 201 (Created)
            return response()->json([
                'success' => true,
                'message' => 'Tải bằng chứng 360 độ thành công!',
                'data'    => $evidence
            ], 201);

        } catch (\Exception $e) {
            // Bắt giữ ngoại lệ và hoàn lời nhắn lỗi chi tiết kèm mã HTTP 400
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}