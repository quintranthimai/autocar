<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & CÁC LỚP MÔ HÌNH (IMPORTS & MODELS)
// ============================================================================
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Support\Facades\Auth;

/**
 * BỘ ĐIỀU KHIỂN HỆ THỐNG ĐÁNH GIÁ & PHẢN HỒI (REVIEW CONTROLLER)
 * Chuyên trách quản lý quy trình đánh giá uy tín hai chiều (User-to-User):
 * Khách thuê đánh giá trải nghiệm xe/Chủ xe, và Chủ xe đánh giá văn hóa sử dụng xe của Khách.
 */
class ReviewController
{
    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC XỬ LÝ KHỞI TẠO ĐÁNH GIÁ (REVIEW CREATION)
    // ============================================================================

    /**
     * API: Gửi đánh giá cho chuyến đi (User-to-User)
     *
     * Quy tắc Nghiệp vụ & Bảo mật:
     * - Rà soát trạng thái Chuyến đi: Bắt buộc ở mức độ Hoàn thành toàn diệp ('completed').
     * - Cơ chế Tự động hóa Vai trò Đánh giá: Nếu người đăng nhập là Khách thuê -> Mục tiêu là Chủ xe và ngược lại.
     * - Kiểm soát An toàn Spam: Hạn chế duy nhất 01 Đánh giá cho mỗi Khách hàng tham dự trên từng Mã Đơn (Booking ID).
     *
     * @param Request $request Yêu cầu HTTP chứa các thông tin (`rating`, `comment`)
     * @param int|string $bookingId Định danh Đơn đặt xe cần đánh giá
     * @return \Illuminate\Http\JsonResponse Phản hồi JSON trạng thái tiếp nhận đánh giá
     */
    public function store(Request $request, $bookingId)
    {
        $user = Auth::user();

        // --------------------------------------------------------------------------
        // [Bước 1]: Kiểm tra tính hợp lệ của trường thông số Đánh giá
        // --------------------------------------------------------------------------
        $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        // --------------------------------------------------------------------------
        // [Bước 2]: Lấy thông tin chuyến đi kèm theo Xe để xác minh hai đầu cầu Đối tác
        // --------------------------------------------------------------------------
        $booking = Booking::with('vehicle')->findOrFail($bookingId);

        // --------------------------------------------------------------------------
        // [Bước 3]: Kiểm tra điều kiện tiên quyết - Buộc Đơn thuê xe đã tất toán Hoàn tất
        // --------------------------------------------------------------------------
        if ($booking->status !== 'completed') {
            return response()->json([
                'success' => false, 
                'message' => 'Chỉ có thể đánh giá những chuyến đi đã hoàn tất tất toán.'
            ], 400);
        }

        // --------------------------------------------------------------------------
        // [Bước 4]: Phân bổ danh tính Người thẩm định (Reviewer) và Người bị đánh giá (Reviewee)
        // --------------------------------------------------------------------------
        $reviewerId = $user->id;
        $revieweeId = null;

        if ($user->id === $booking->renter_id) {
            // Trường hợp 1: Người đang đăng nhập là KHÁCH THUÊ -> Đánh giá CHỦ XE
            $revieweeId = $booking->vehicle->owner_id;
        } elseif ($user->id === $booking->vehicle->owner_id) {
            // Trường hợp 2: Người đang đăng nhập là CHỦ XE -> Đánh giá KHÁCH THUÊ
            $revieweeId = $booking->renter_id;
        } else {
            // Ngăn chặn bên thứ 3 (Người lạ) can thiệp thao túng điểm tín nhiệm
            return response()->json([
                'success' => false, 
                'message' => 'Bạn không có quyền tham gia đánh giá chuyến đi này.'
            ], 403);
        }

        // --------------------------------------------------------------------------
        // [Bước 5]: Kiểm tra chống Spam (Mỗi bên tham dự chỉ được quyền đánh giá 1 lần duy nhất)
        // --------------------------------------------------------------------------
        $existingReview = Review::where('booking_id', $booking->id)
                                ->where('reviewer_id', $reviewerId)
                                ->first();
                                
        if ($existingReview) {
            return response()->json([
                'success' => false, 
                'message' => 'Bạn đã gửi đánh giá cho chuyến đi này rồi.'
            ], 400);
        }

        // --------------------------------------------------------------------------
        // [Bước 6]: Khởi tạo và ghi nhận kết quả Đánh giá vào Cơ sở dữ liệu
        // --------------------------------------------------------------------------
        $review = Review::create([
            'booking_id'  => $booking->id,
            'reviewer_id' => $reviewerId,
            'reviewee_id' => $revieweeId,
            'rating'      => $request->rating,
            'comment'     => $request->comment,
            // Cột 'reply_comment' tạm giữ giá trị null, chờ đối phương vào trả lời sau 
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cảm ơn bạn đã gửi đánh giá!',
            'data'    => $review
        ]);
    }
}