<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & HỆ S TIẾP DIỄN DÒNG TIỀN (IMPORTS & MODELS)
// ============================================================================
use Illuminate\Http\Request;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use App\Notifications\NewWithdrawalRequestNotification;

/**
 * BỘ ĐIỀU KHIỂN QUẢN LÝ VÍ NỘI BỘ & GIAO TÁC VNPAY (WALLET CONTROLLER)
 * Chuyên trách vận hành ví tín dụng Khách hàng, khởi tạo cổng nạp tiền tự động VNPAY Top-up,
 * gửi yêu cầu rút tiền về Tài khoản Ngân hàng và tra cứu lịch sử sao kê giao dịch (Transactions).
 */
class WalletController
{
    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC TRA CỨU SỐ DƯ & NẠP VÍ TỰ ĐỘNG VNPAY (TOPUP OPERATIONS)
    // ============================================================================

    /**
     * Lấy thông tin tài chính ví hiện tại (Nếu chưa có sẽ tự động tạo mới số dư 0)
     *
     * @param Request $request Yêu cầu HTTP
     * @return \Illuminate\Http\JsonResponse Thông tin số dư Khả dụng & Tiền cọc
     */
    public function show(Request $request)
    {
        $user = Auth::user();

        // Tác Vụ Thông Minh: Truy tìm Ví theo user_id, nếu không thấy lập tức khởi tạo hồ sơ active với tài khoản 0
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['available_balance' => 0, 'deposit_balance' => 0, 'status' => 'active']
        );

        return response()->json([
            'success' => true,
            'message' => 'Lấy thông tin ví thành công.',
            'data' => $wallet
        ]);
    }

    /**
     * Khởi tạo Giao dịch Nạp tiền vào Ví thông qua Cổng Thanh toán Quốc gia VNPAY
     *
     * Quy trình vận hành dòng tiền:
     * - Thẩm định khoản nạp (Tối thiểu 10.000 VNĐ).
     * - Tạo giao dịch nháp (Transaction status: `pending`) ghi nhận mong muốn nạp tiền.
     * - Khởi tạo mã hóa chữ ký HMAC SHA512 theo tài liệu chuẩn kỹ thuật VNPAY (Version 2.1.0).
     * - Thiết lập hạn mức chờ thanh toán là 15 phút, sau đó chuyển hướng Client sang Link cổng thanh toán.
     *
     * @param Request $request Yêu cầu HTTP chứa `amount` (Khoản nạp)
     * @return \Illuminate\Http\JsonResponse Link thanh toán VNPAY (`payment_url`)
     */
    public function deposit(Request $request)
    {
        // [Bước 1]: Giám sát mức nạp sàn - Nạp tối thiểu 10.000 VNĐ theo giới hạn Ngân hàng
        $request->validate([
            'amount' => 'required|numeric|min:10000',
        ]);

        $user = Auth::user();
        $amount = $request->amount;

        try {
            DB::beginTransaction();

            // [Bước 2]: Truy lục hoặc tạo mới tài khoản Ví người dùng
            $wallet = Wallet::firstOrCreate(
                ['user_id' => $user->id],
                ['available_balance' => 0, 'deposit_balance' => 0, 'status' => 'active']
            );

            // [Bước 3]: Ghi nhận lịch sử giao dịch chờ (Pending) trong CSDL
            $transaction = Transaction::create([
                'wallet_id'    => $wallet->id,
                'booking_id'   => null,
                'amount'       => $amount,
                'type'         => 'credit',
                'balance_type' => 'available',
                'description'  => 'Nạp tiền vào ví qua VNPAY',
                'status'       => 'pending',
            ]);
            
            // [Bước 4]: Xây dựng URL Cổng thanh toán VNPAY
            $vnp_TmnCode = config('vnpay.tmn_code');
            $vnp_HashSecret = config('vnpay.hash_secret');
            $vnp_Url = config('vnpay.url');
            $vnp_Returnurl = config('vnpay.return_url');
            
            // Dùng tiền tố WALLET_ kèm theo ID transaction nhằm giúp IPN phân biệt Nạp ví hay Thuê xe
            $vnp_TxnRef = 'WALLET_' . $transaction->id . '_' . time(); 
            $vnp_OrderInfo = "Nap tien vao vi " . $transaction->id; 
            $vnp_OrderType = 'topup';
            $vnp_Amount = (int) ($amount * 100); // Quy mô nhân 100 theo tiêu chuẩn tiền tệ VNPAY
            $vnp_Locale = 'vn';
            
            $vnp_IpAddr = $request->ip();
            if ($vnp_IpAddr === '::1' || $vnp_IpAddr === '127.0.0.1') {
                $vnp_IpAddr = '127.0.0.1'; 
            }

            $vnp_CreateDate = now()->timezone('Asia/Ho_Chi_Minh')->format('YmdHis');
            // Hạn thanh toán: 15 phút kể từ lúc tạo lệnh
            $vnp_ExpireDate = now()->addMinutes(15)->timezone('Asia/Ho_Chi_Minh')->format('YmdHis');

            $inputData = array(
                "vnp_Version" => "2.1.0",
                "vnp_TmnCode" => $vnp_TmnCode,
                "vnp_Amount" => $vnp_Amount,
                "vnp_Command" => "pay",
                "vnp_CreateDate" => $vnp_CreateDate,
                "vnp_CurrCode" => "VND",
                "vnp_IpAddr" => $vnp_IpAddr,
                "vnp_Locale" => $vnp_Locale,
                "vnp_OrderInfo" => $vnp_OrderInfo,
                "vnp_OrderType" => $vnp_OrderType,
                "vnp_ReturnUrl" => $vnp_Returnurl,
                "vnp_TxnRef" => $vnp_TxnRef,
                "vnp_ExpireDate" => $vnp_ExpireDate,
            );

            // Sắp xếp mã tham số theo Bảng chữ cái trước khi mã hóa (Yêu cầu kỹ thuật bắt buộc của VNPAY)
            ksort($inputData);
            
            $query = "";
            $i = 0;
            $hashdata = "";
            foreach ($inputData as $key => $value) {
                if ($i == 1) {
                    $hashdata .= '&' . urlencode($key) . "=" . urlencode($value);
                } else {
                    $hashdata .= urlencode($key) . "=" . urlencode($value);
                    $i = 1;
                }
                $query .= urlencode($key) . "=" . urlencode($value) . '&';
            }

            $vnp_Url = $vnp_Url . "?" . $query;
            // Ký số bảo mật bằng SHA512
            if (isset($vnp_HashSecret)) {
                $vnpSecureHash = hash_hmac('sha512', $hashdata, $vnp_HashSecret);
                $vnp_Url .= 'vnp_SecureHash=' . $vnpSecureHash;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Đang chuyển hướng sang VNPAY',
                'data'    => [
                    'payment_url' => $vnp_Url
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    // ============================================================================
    // 3. NHÓM PHƯƠNG THỨC YÊU CẦU RÚT TIỀN VỀ TRÌNH KÝ NGÂN HÀNG (WITHDRAWAL)
    // ============================================================================

    /**
     * Thực thi Yêu cầu Rút tiền từ Ví điện tử về Tài khoản Ngân hàng (Bank Payout)
     *
     * Quy chuẩn Tài chính An toàn:
     * - Hạn mức tối thiểu cho mỗi lệnh rút là 50.000 VNĐ.
     * - Sử dụng khóa dòng độc quyền `lockForUpdate()` nhằm tránh Race Condition (rút quá số dư trong cùng 1 Mili-giây).
     * - Trừ trực tiếp trên Số dư Khả dụng (`available_balance`) ngay lúc nộp đơn.
     * - Đồng thời bắn Thông báo toàn diện (Notification) đến đội ngũ Quản trị viên (Admin).
     *
     * @param Request $request Yêu cầu HTTP chứa `amount`, `bank_account`, `bank_name`,...
     * @return \Illuminate\Http\JsonResponse Kết quả gửi lệnh rút
     */
    public function withdraw(Request $request)
    {
        // [Bước 1]: Validate ràng buộc thông tin thanh toán Tài khoản Ngân hàng
        $request->validate([
            'amount' => 'required|numeric|min:50000', // Định mức rút tiền sàn tối thiểu là 50.000đ
            'bank_account' => 'required|string', // Số tài khoản ngân hàng thụ hưởng
            'bank_name' => 'nullable|string|max:100',
            'bank_account_name' => 'nullable|string|max:120',
        ]);

        $user = Auth::user();
        $amount = $request->amount;

        try {
            DB::beginTransaction();

            // Áp dụng Lock For Update để ngăn block xung đột tiến trình rút tiền song song (Concurrency control)
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

            // [Bước 2]: Đối chiếu thực tế Số dư khả dụng
            if (!$wallet || $wallet->available_balance < $amount) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Số dư khả dụng không đủ để thực hiện giao dịch rút tiền.'
                ], 400);
            }

            // [Bước 3]: Trừ lập tức số tiền Khả dụng của khách trong thời gian chờ thẩm định
            $wallet->available_balance -= $amount;
            $wallet->save();

            // [Bước 4]: Lập hóa đơn yêu cầu cho Quản trị viên kiểm tra giải ngân chéo (Pending Approval)
            $withdrawalRequest = WithdrawalRequest::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'bank_name' => $request->bank_name,
                'bank_account' => $request->bank_account,
                'bank_account_name' => $request->bank_account_name,
                'status' => 'pending',
            ]);

            // [Bước 5]: Ghi nhận lịch sử giao dịch trừ Ví
            Transaction::create([
                'wallet_id'    => $wallet->id,
                'booking_id'   => null,
                'amount'       => $amount,
                'type'         => 'debit', // Rút tiền ra khỏi hệ sinh thái (Trừ)
                'balance_type' => 'available',
                'description'  => 'Tao yeu cau rut tien cho admin duyet #' . $withdrawalRequest->id,
            ]);

            DB::commit();

            // [Bước 6]: Báo cáo cảnh báo theo thời gian thực tới Bộ phận Admin Quản trị
            $admins = \App\Models\User::whereHas('roles', function($q) {
                $q->where('slug', 'admin');
            })->get();
            Notification::send($admins, new NewWithdrawalRequestNotification($user, $amount));

            return response()->json([
                'success' => true,
                'message' => 'Yeu cau rut tien da duoc ghi nhan va dang cho duyet.',
                'data'    => [
                    'wallet' => $wallet,
                    'withdrawal_request' => $withdrawalRequest,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    // ============================================================================
    // 4. NHÓM PHƯƠNG THỨC SAO KÊ VÀ TRUY TRÌNH LỊCH SỬ GIAO TÁC (STATEMENT & HISTORY)
    // ============================================================================

    /**
     * Tra cứu Lịch sử Các Yêu cầu Rút tiền của Khách hàng hiện tại (Có phân trang)
     *
     * @param Request $request Yêu cầu HTTP chứa bộ lọc trạng thái `status`
     * @return \Illuminate\Http\JsonResponse Danh sách yêu cầu rút tiền
     */
    public function withdrawalHistory(Request $request)
    {
        $user = Auth::user();

        $query = WithdrawalRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at');

        // Lọc linh hoạt theo trạng thái: pending, approved, rejected
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate((int) $request->input('per_page', 10)),
        ]);
    }

    /**
     * Tra cứu Sao kê toàn diện Biến động Số dư Ví (Nạp, Rút, Đặt xe, Nhận thù lao...)
     *
     * @param Request $request Yêu cầu HTTP chứa bộ lọc `type` và `status`
     * @return \Illuminate\Http\JsonResponse Danh sách các giao dịch (Transactions)
     */
    public function transactionHistory(Request $request)
    {
        $user = Auth::user();
        
        $wallet = Wallet::where('user_id', $user->id)->first();
        if (!$wallet) {
            return response()->json([
                'success' => true,
                'data' => []
            ]);
        }

        $query = Transaction::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('created_at');

        // Lọc theo loại Dòng tiền: credit (cộng), debit (trừ)
        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }
        
        // Lọc theo trạng thái thanh toán: pending, completed, failed
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate((int) $request->input('per_page', 10)),
        ]);
    }
}