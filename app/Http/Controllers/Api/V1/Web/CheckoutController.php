<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & CÁC LỚP MÔ HÌNH HỆ THỐNG (IMPORTS & MODELS)
// ============================================================================
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

/**
 * BỘ ĐIỀU KHIỂN HIỆU KỲ & TẤT TOÁN GIAO DỊCH (CHECKOUT CONTROLLER)
 * Chuyên trách giám sát, tính toán chênh lệch, tự động trừ số dư trong Ví Khách thuê
 * và chính thức kết thúc chặng đường thuê xe (Hoàn tất chu trình đơn hàng).
 */
class CheckoutController
{
    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC XỬ LÝ THANH TOÁN KẾT THÚC CHUYẾN (CHECKOUT PROCESSING)
    // ============================================================================

    /**
     * API: Tất toán và kết thúc chuyến đi
     *
     * Logic nghiệp vụ cốt lõi:
     * - Đối chiếu danh tính Renter khởi tạo lệnh.
     * - Kiểm tra tính hợp lệ của trạng thái Đơn đặt xe ('confirmed').
     * - Tính toán khoản tiền còn thiếu: (Tổng chi phí đã cộng Phụ phí) - (Số tiền đặt cọc).
     * - Khóa và xử lý dòng tiền an toàn thông qua cơ chế Database Transaction & Lock For Update.
     * - Đồng bộ sang trạng thái hoàn tất ('completed'), qua đó tự động giải phóng Lịch trình cho Phương tiện.
     *
     * @param Request $request Đối tượng yêu cầu HTTP
     * @param int|string $id Mã định danh đơn hàng đặt xe (Booking ID)
     * @return \Illuminate\Http\JsonResponse Phản hồi JSON kết quả giao tiếp
     */
    public function processCheckout(Request $request, $id)
    {
        $user = Auth::user();
        
        // Lấy thông tin chuyến đi kèm theo hồ sơ xe tương ứng
        $booking = Booking::with('vehicle')->findOrFail($id);

        // --------------------------------------------------------------------------
        // [Bước 1]: Kiểm soát đặc quyền truy cập và tính hợp lệ của Trạng thái
        // --------------------------------------------------------------------------
        if ($booking->renter_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Bạn không có quyền tất toán chuyến đi này.'], 403);
        }

        if ($booking->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Chuyến đi này đã được tất toán trước đó.'], 400);
        }

        if ($booking->status !== 'confirmed') {
            return response()->json(['success' => false, 'message' => 'Chuyến đi chưa được xác nhận hoặc đã bị hủy.'], 400);
        }

        // --------------------------------------------------------------------------
        // [Bước 2]: Tính toán chính xác khoản chi phí phải tất toán bổ sung
        // Lưu ý: trường total_amount đã được cộng dồn các Phụ phí phát sinh (nếu có) từ trước đó
        // --------------------------------------------------------------------------
        $soTienConThieu = max(0, $booking->total_amount - $booking->deposit_amount);

        try {
            // Bắt đầu khóa Giao dịch (Transaction) trong PostgreSQL để ngăn trượt giá hoặc xử lý kép
            DB::beginTransaction();

            // --------------------------------------------------------------------------
            // [Bước 3]: Xử lý trừ tiền trực tiếp trong Ví Nội bộ (Nếu khoản thanh toán lớn hơn 0)
            // --------------------------------------------------------------------------
            if ($soTienConThieu > 0) {
                // Áp dụng cơ chế khóa hàng (lockForUpdate) ngăn xung đột thao tác đồng thời (Concurrency Control)
                $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

                if (!$wallet || $wallet->available_balance < $soTienConThieu) {
                    // Kích hoạt ngoại lệ (Exception) để tự động kích hoạt tiến trình Rollback toàn bộ giao dịch
                    throw new \Exception('Số dư trong ví nội bộ không đủ. Vui lòng nạp thêm ' . number_format($soTienConThieu) . 'đ để tất toán.');
                }

                // Thực thi trừ số dư khả dụng
                $wallet->available_balance -= $soTienConThieu;
                $wallet->save();

                // Ghi nhận tường trình Biến động Dòng tiền vào sổ lệnh hệ thống
                Transaction::create([
                    'wallet_id'    => $wallet->id,
                    'booking_id'   => $booking->id,
                    'amount'       => $soTienConThieu,
                    'type'         => 'debit', // Kiểu giao dịch: Trừ tiền khỏi ví
                    'balance_type' => 'available',
                    'description'  => 'Thanh toán phần tiền còn thiếu cho chuyến đi #' . $booking->id,
                ]);
            }

            // --------------------------------------------------------------------------
            // [Bước 4]: Cập nhật trạng thái Đơn hàng về chính thức Hoàn thành (completed)
            // --------------------------------------------------------------------------
            $booking->status = 'completed';
            $booking->save();

            // --------------------------------------------------------------------------
            // [Bước 5]: Giải phóng tài nguyên và Trạng thái Xe
            // Đã lược bỏ logic can thiệp thủ công vào vehicle->status nhằm tôn trọng thiết lập của Chủ xe
            // (Chỉ cần status của Booking thay đổi thành 'completed' thì lịch xe ở khung giờ đó tự động trống)
            // --------------------------------------------------------------------------

            // Xác nhận thành công và ghi vĩnh viễn dữ liệu vào CSDL (Commit Transaction)
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tất toán và kết thúc chuyến đi thành công!',
                'data'    => [
                    'booking_id'     => $booking->id,
                    'total_amount'   => $booking->total_amount,
                    'paid_amount'    => $soTienConThieu,
                    'booking_status' => $booking->status,
                ]
            ]);

        } catch (\Exception $e) {
            // Phát sinh sự cố -> Thu hồi trọn vẹn thay đổi CSDL (Tiền trong ví giữ nguyên, Đơn xe giữ nguyên trạng thái cũ)
            DB::rollBack();
            
            // Phân loại mã lỗi trả về: lỗi thiếu số dư Ví (400 Bad Request) hay Lỗi máy chủ không xác định (500)
            $statusCode = str_contains($e->getMessage(), 'Số dư trong ví') ? 400 : 500;
            return response()->json(['success' => false, 'message' => $e->getMessage()], $statusCode);
        }
    }
}