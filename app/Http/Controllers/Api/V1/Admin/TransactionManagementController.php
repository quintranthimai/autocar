<?php

namespace App\Http\Controllers\Api\V1\Admin;

use Illuminate\Http\Request;
use App\Models\Transaction;

/**
 * ============================================================================
 * LỚP TRANSACTION MANAGEMENT CONTROLLER (KIỂM SOÁT NHẬT KÝ VÀ TRỌNG TIỀN SÀN)
 * ============================================================================
 * Controller nghiệp vụ phụ trách phân hệ Giám sát Tài chính cho Quản trị viên
 * (Admin CMS). Cung cấp góc nhìn toàn vẹn về luồng tiền luân chuyển trên hệ thống
 * qua cơ chế tra cứu dòng tiền Thu (Credit), Chi (Debit), đối soát đơn đặt xe
 * tương ứng và xác định chính xác tài khoản chủ nhân Ví luân lưu tiền gửi.
 */
class TransactionManagementController
{
    /**
     * ========================================================================
     * 1. HÀM DANH SÁCH: TRA CỨU BỘ DIỄN BIẾN NHẬT KÝ TIỀN TỆ TOÀN CÔNG
     * ========================================================================
     * Trích xuất nhật ký biến động tài chính toàn bộ hệ thống với công năng ưu việt:
     * - Tải sẵn dữ liệu Chuyến đi (booking) và Chủ sở hữu Ví (wallet.user) tránh N+1.
     * - Bộ lọc Đa chiều: Theo hướng luồng tiền (Thu/Chi), hoặc trong Khung giờ tự chọn.
     * - Tìm kiếm theo cú pháp thông minh: Nhận diện Mã giao dịch (#GD1), Mã đơn,
     *   hoặc đối sánh từ khóa trong lời mô tả biến động (ILIKE).
     *
     * @param  \Illuminate\Http\Request  $request  Các tham số lọc tra cứu nhật ký.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON danh bạ phân trang (15 GD/trang).
     */
    public function index(Request $request)
    {
        // Kéo theo thông tin đơn đặt xe (để biết giao dịch cho đơn nào)
        // và thông tin ví (để biết của User nào)
        $query = Transaction::with(['booking.renter', 'wallet.user']);

        // Bộ lọc theo loại giao dịch (credit: Thu / debit: Chi)
        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        // Bộ lọc giới hạn kỳ hạn thời gian (Từ ngày - Đến ngày)
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Tìm kiếm linh hoạt theo mã giao dịch, mã đơn hoặc mô tả tường minh
        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                // Hỗ trợ truy vết theo mã giao dịch quy chuẩn (GD1, #GD1)
                if (preg_match('/^#?gd(\d+)$/i', trim($search), $matches)) {
                    $q->where('id', $matches[1]);
                } else if (is_numeric(trim($search))) {
                    $q->where('id', trim($search))
                      ->orWhere('booking_id', trim($search));
                } else {
                    $q->where('description', 'ILIKE', "%{$search}%");
                }
            });
        }

        $transactions = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $transactions
        ]);
    }
}