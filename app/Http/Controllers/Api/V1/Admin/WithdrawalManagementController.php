<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Notifications\WithdrawalResultNotification;

/**
 * ============================================================================
 * LỚP WITHDRAWAL MANAGEMENT CONTROLLER (KIỂM SOÁT YÊU CẦU RÚT TIỀN ĐỐI TÁC)
 * ============================================================================
 * Controller phân hệ Quản trị tối cao (CMS Admin) chuyên phụ trách thanh khoản
 * tài chính: Quản lý danh bạ yêu cầu rút tiền về Ngân hàng từ Khách hàng/Chủ xe.
 * Ứng dụng triết lý lập trình tài chính ACID nghiêm ngặt (DB Transaction) kết hợp
 * Khóa quan trọng (lockForUpdate) phòng tranh chấp luồng, hoàn trả tiền lập tức
 * vào Ví nếu yêu cầu rút bị từ chối và phát tia thông báo đến người sử dụng.
 */
class WithdrawalManagementController
{
    /**
     * ========================================================================
     * 1. HÀM DANH SÁCH (INDEX): TRA CỨU HẢI DIỆN HỒ SƠ LỆNH RÚT TIỀN
     * ========================================================================
     * Cung cấp danh bạ các yêu cầu thanh khoản rút tiền với khả năng:
     * - Tải trước quan hệ Người yêu cầu (user) và Người kiểm duyệt (processor).
     * - Lọc sắc bén theo Trạng thái hồ sơ ('all', 'pending', 'approved', 'rejected').
     * - Tìm kiếm đa thông số: Nhận biết Mã yêu cầu (#RT1, RT1), thông tin Số tài khoản
     *   ngân hàng, Tên chủ tài khoản, Ngân hàng hoặc danh tính (Tên/SĐT) Khách hàng.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số tra cứu và tùy chọn phân trang.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON danh bạ phân trang.
     */
    public function index(Request $request)
    {
        $query = WithdrawalRequest::with(['user', 'processor'])
            ->orderByDesc('created_at');

        // Bộ lọc trạng thái đơn yêu cầu rút tiền
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Truy lục thông tin mở rộng theo cú pháp tra cứu linh hoạt
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                // Hỗ trợ truy tìm chính xác mã YC rút tiền quy chuẩn: #RT1, RT1, rt1
                if (preg_match('/^#?rt(\d+)$/i', trim($search), $matches)) {
                    $q->where('id', $matches[1]);
                } else if (is_numeric(trim($search))) {
                    $q->where('id', trim($search));
                } else {
                    $q->where('bank_account', 'ILIKE', "%{$search}%")
                        ->orWhere('bank_account_name', 'ILIKE', "%{$search}%")
                        ->orWhere('bank_name', 'ILIKE', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'ILIKE', "%{$search}%")
                                ->orWhere('phone', 'ILIKE', "%{$search}%");
                        });
                }
            });
        }

        $result = $query->paginate((int) $request->input('per_page', 10));

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    /**
     * ========================================================================
     * 2. HÀM PHÊ DUYỆT RÚT TIỀN (APPROVE): XÁC TRỪ GIẢI CHỈ V THỰC CÔNG
     * ========================================================================
     * Chốt hạ quyết định thanh khoản hợp lệ cho Đối tác/Chủ xe. QUY TRÌNH ACID AN TẤT:
     * - Dùng `lockForUpdate()` giam giữ bản ghi ngăn cấm mọi xung đột nhấp đúp (Race condition).
     * - Cập nhật trạng thái `approved`, lưu vết ID Quản trị viên xử lý cùng thời điểm.
     * - Gửi thông điệp `WithdrawalResultNotification` qua kênh thông tin thông báo.
     * (Lưu ý: Tiền đã được deducting trừ khỏi số dư Ví ngay từ giây phút Người dùng lập đơn).
     *
     * @param  int                       $id  ID Yêu cầu Rút tiền cần phê duyệt.
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON xác nhận hoàn tất hay từ chối.
     */
    public function approve($id)
    {
        $admin = Auth::user();

        try {
            DB::beginTransaction();

            // Khóa dòng dữ liệu an toàn để chặn mọi luồng đồng thời xâm phạm
            $request = WithdrawalRequest::lockForUpdate()->findOrFail($id);

            if ($request->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Yeu cau nay da duoc xu ly truoc do.',
                ], 422);
            }

            $request->status = 'approved';
            $request->processed_by = $admin?->id;
            $request->processed_at = now();
            $request->save();

            DB::commit();

            // Kích hoạt tín hiệu gửi thông điệp báo thành công cho Khách hàng
            if ($request->user) {
                $request->user->notify(new WithdrawalResultNotification($request, 'approved'));
            }

            return response()->json([
                'success' => true,
                'message' => 'Da duyet yeu cau rut tien.',
                'data'    => $request,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Loi he thong: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ========================================================================
     * 3. HÀM TỪ CHỐI & HOÀN TIỀN VÍ (REJECT): BÁC HẬU HỒ SƠ THANH KHOẢN
     * ========================================================================
     * Từ chối thanh toán một yêu cầu rút tiền có sự cố (Sai tài khoản, nghi vấn sai phạm).
     * ĐẢM BẢO TÍNH ACID VỆ TRÍ GIAO DỊCH TÀI CHÍNH TỐI CAO:
     * 1. Khóa bản ghi WithdrawalRequest và Ví (Wallet) bằng `lockForUpdate()`.
     * 2. Hoàn lại chính xác con số tiền rút ($amount) trả ngược về `available_balance` của Ví.
     * 3. Ghi chép ngay nhật ký giao dịch Transaction Thu (credit) minh bach cọc hoàn.
     * 4. Chuyển trạng thái đơn rút thành `rejected` và thông báo hòm thư cùng nguyên do.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số chứa lý do từ chối (reason).
     * @param  int                       $id       ID Yêu cầu Rút tiền bị bác bỏ.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON hoàn tiền hoặc cảnh báo rủi ro.
     */
    public function reject(Request $request, $id)
    {
        $admin = Auth::user();

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            DB::beginTransaction();

            // Khóa phong tỏa yêu cầu rút tiền để đảm bảo tính duy nhất khi thao tác
            $withdrawalRequest = WithdrawalRequest::lockForUpdate()->findOrFail($id);

            if ($withdrawalRequest->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Yeu cau nay da duoc xu ly truoc do.',
                ], 422);
            }

            // Khóa tiếp tài sản Ví nội bộ của Đối tác để chuẩn bị tác vụ hoàn tiền
            $wallet = Wallet::lockForUpdate()->findOrFail($withdrawalRequest->wallet_id);

            // Bồi hoàn lượng tiền đã tạm chiết khi khởi tạo đơn trở lại vào số dư thực tế
            $wallet->available_balance += $withdrawalRequest->amount;
            $wallet->save();

            // Khởi tạo chứng từ lịch sử biến động tài chính minh bạch cho khoản hoàn lại
            Transaction::create([
                'wallet_id'    => $wallet->id,
                'booking_id'   => null,
                'amount'       => $withdrawalRequest->amount,
                'type'         => 'credit',
                'balance_type' => 'available',
                'description'  => 'Hoan tien yeu cau rut tien bi tu choi #' . $withdrawalRequest->id,
            ]);

            $withdrawalRequest->status = 'rejected';
            $withdrawalRequest->rejection_reason = $request->reason;
            $withdrawalRequest->processed_by = $admin?->id;
            $withdrawalRequest->processed_at = now();
            $withdrawalRequest->save();

            DB::commit();

            // Phát thư báo qua hệ thống kèm thông điệp nguyên nhân không hỗ trợ giải ngân
            if ($withdrawalRequest->user) {
                $withdrawalRequest->user->notify(new WithdrawalResultNotification($withdrawalRequest, 'rejected', $request->reason));
            }

            return response()->json([
                'success' => true,
                'message' => 'Da tu choi yeu cau va hoan tien ve vi.',
                'data'    => $withdrawalRequest,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Loi he thong: ' . $e->getMessage(),
            ], 500);
        }
    }
}
