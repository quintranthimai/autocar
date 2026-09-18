<?php

namespace App\Http\Controllers\Api\V1\Web;

use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Notifications\BookingPaidNotification;
use App\Notifications\BookingPaidRenterNotification;

/**
 * ============================================================================
 * LỚP PAYMENT CONTROLLER (XỬ LÝ THANH TOÁN & TÍCH HỢP VNPAY)
 * ============================================================================
 * Controller chịu trách nhiệm xử lý quá trình thanh toán đơn đặt xe của Khách hàng,
 * hỗ trợ 2 phương thức chính: Trừ tiền Ví điện tử (Wallet) và Cổng thanh toán VNPay.
 * Quản lý toàn vẹn chu trình đối soát Return URL, Truy vấn Web API (QueryDR), và Webhook IPN.
 */
class PaymentController
{
    /**
     * ========================================================================
     * 1. HÀM XỬ LÝ THANH TOÁN: KHỞI TẠO GIAO DỊCH VÍ HOẶC TẠO URL VNPAY
     * ========================================================================
     * Thực hiện thanh toán tiền cọc/toàn phần sau khi Đơn đặt xe được Chủ xe duyệt.
     * Áp dụng ràng buộc bảo mật chặt chẽ về hạn mức 2 giờ thanh toán (chống ngâm đơn).
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu yêu cầu (phương thức thanh toán: wallet, vnpay).
     * @param  int                       $id       ID của đơn đặt xe.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON kết quả hoặc đường dẫn chuyển hướng VNPay.
     */
    public function processPayment(Request $request, $id)
    {
        // Validate cấu trúc yêu cầu thanh toán
        $request->validate([
            'payment_method' => 'required|in:wallet,vnpay'
        ]);

        $user = Auth::user();
        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Đơn hàng không tồn tại'], 404);
        }

        // BẢO MẬT: Chỉ người đặt xe thực tế mới được phép thanh toán đơn hàng này
        if ($booking->renter_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Bạn không có quyền thanh toán đơn này'], 403);
        }

        // KIỂM TRA TRẠNG THÁI: Bắt buộc đơn phải ở trạng thái chờ thanh toán (pending_payment)
        if ($booking->status !== 'pending_payment') {
            return response()->json(['success' => false, 'message' => 'Đơn hàng không ở trạng thái chờ thanh toán'], 400);
        }

        // ====================================================================
        // CHỐT CHẶN BẢO MẬT: KIỂM TRA QUÁ VI PHẠM THỜI GIAN CHỜ THANH TOÁN 2 GIỜ
        // (Thời gian tối đa được tính từ lúc Chủ xe phê duyệt - thuộc updated_at)
        // ====================================================================
        $expiresAt = $booking->updated_at->addHours(2);
        
        if (now()->greaterThan($expiresAt)) {
            // Tự động chuyển đơn sang trạng thái hủy do quá thời gian giữ lịch
            $booking->update([
                'status' => 'cancelled',
                'cancel_by' => 'system',
                'cancel_reason' => 'Hệ thống tự động hủy do quá hạn thanh toán (2 giờ).',
                'cancelled_at' => now()
            ]);

            // Hoàn lại lượt dùng mã khuyến mãi (Voucher) cho nền tảng nếu có sử dụng
            if (!empty($booking->promo_code)) {
                DB::table('vouchers')
                    ->where('code', $booking->promo_code)
                    ->where('used_count', '>', 0)
                    ->decrement('used_count');
            }
            
            return response()->json([
                'success' => false, 
                'message' => 'Rất tiếc! Đơn hàng đã tự động hủy do bạn không thanh toán trong vòng 2 giờ kể từ khi được duyệt.'
            ], 400);
        }

        // Khoản tiền cần thanh toán theo hợp đồng đơn đặt xe (tiền cọc)
        $amountToPay = $booking->deposit_amount;

        // --------------------------------------------------------------------
        // RẼ NHÁNH 1: THANH TOÁN TRỰC TIẾP QUA VÍ ĐIỆN TỬ NỘI BỘ (WALLET)
        // --------------------------------------------------------------------
        if ($request->payment_method === 'wallet') {
            $wallet = Wallet::where('user_id', $user->id)->first();
            
            // Kiểm tra điều kiện số dư ví hợp lệ
            if (!$wallet || $wallet->available_balance < $amountToPay) {
                return response()->json(['success' => false, 'message' => 'Số dư trong ví không đủ. Vui lòng nạp thêm.'], 400);
            }

            try {
                // Bắt đầu giao dịch cơ sở dữ liệu để bảo vệ số dư ví
                DB::beginTransaction();
                
                // Khấu trừ số dư ví khả dụng
                $wallet->available_balance -= $amountToPay;
                $wallet->save();

                // Chuyển sang trạng thái Đã xác nhận/Đã đặt cọc (Confirmed)
                $booking->status = 'confirmed';
                $booking->save();

                // Ghi nhận biên lai giao dịch lịch sử tiền ví
                Transaction::create([
                    'wallet_id'    => $wallet->id,
                    'booking_id'   => $booking->id,
                    'amount'       => $amountToPay,
                    'type'         => 'debit',
                    'balance_type' => 'available',
                    'description'  => 'Thanh toán tiền đặt xe cho mã đơn #' . $booking->id,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                // Hoàn tất chuỗi cập nhật tài chính
                DB::commit();

                // Gửi thông báo đến Chủ xe về việc khách đã hoàn tất thanh toán
                $booking->loadMissing('vehicle.owner', 'renter');
                if ($booking->vehicle && $booking->vehicle->owner) {
                    $booking->vehicle->owner->notify(new BookingPaidNotification($booking));
                }
                
                // Gửi thông báo đến Khách thuê xác nhận chuyến đi
                if ($booking->renter) {
                    $booking->renter->notify(new BookingPaidRenterNotification($booking));
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Thanh toán thành công! Chuyến đi của bạn đã được xác nhận.',
                    'data' => $booking
                ]);

            } catch (\Exception $e) {
                // Phục hồi số dư và trạng thái đơn nếu có lỗi bất ngờ
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
            }
        }

        // --------------------------------------------------------------------
        // RẼ NHÁNH 2: THANH TOÁN TỰ ĐỘNG LÊN CỔNG THANH TOÁN VNPAY
        // --------------------------------------------------------------------
        if ($request->payment_method === 'vnpay') {
            $vnp_TmnCode = config('vnpay.tmn_code');
            $vnp_HashSecret = config('vnpay.hash_secret');
            $vnp_Url = config('vnpay.url');
            $vnp_Returnurl = config('vnpay.return_url');
            
            $vnp_TxnRef = $booking->id; 
            $vnp_OrderInfo = "Thanh toan don dat xe " . $booking->id; 
            $vnp_OrderType = 'billpayment';
            // VNPay quy định số tiền thực tế phải nhân với 100
            $vnp_Amount = (int) ($amountToPay * 100); 
            $vnp_Locale = 'vn';
            
            // Xử lý IP Local khi test trên môi trường phát triển (localhost / IPv6)
            $vnp_IpAddr = $request->ip();
            if ($vnp_IpAddr === '::1' || $vnp_IpAddr === '127.0.0.1') {
                $vnp_IpAddr = '127.0.0.1'; 
            }

            // ĐỒNG BỘ THỜI GIAN VNPAY: Theo chuẩn định dạng YmdHis và múi giờ Asia/Ho_Chi_Minh
            $vnp_CreateDate = now()->timezone('Asia/Ho_Chi_Minh')->format('YmdHis');
            $vnp_ExpireDate = $expiresAt->timezone('Asia/Ho_Chi_Minh')->format('YmdHis');

            // Cấu trúc danh sách mảng tham số gửi sang cổng VNPay
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
                "vnp_ExpireDate" => $vnp_ExpireDate, // Yêu cầu VNPay tự động vô hiệu hóa URL sau hạn mốc 2 giờ
            );

            // Sắp xếp các tham số theo thứ tự chữ cái (Yêu cầu bắt buộc của VNPay trước khi tạo mã Hash)
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
            if (isset($vnp_HashSecret)) {
                // Mã hóa bảo mật chữ ký giao dịch theo chuẩn SHA512
                $vnpSecureHash = hash_hmac('sha512', $hashdata, $vnp_HashSecret);
                $vnp_Url .= 'vnp_SecureHash=' . $vnpSecureHash;
            }

            // Trả về URL thanh toán cho trình duyệt Frontend thực hiện Redirect
            return response()->json([
                'success' => true,
                'message' => 'Đã tạo link thanh toán VNPAY',
                'data' => [
                    'payment_url' => $vnp_Url
                ]
            ]);
        }
    }

    /**
     * ========================================================================
     * 2. HÀM NỘI BỘ: XÁC NHẬN TRẠNG THÁI ĐÃ THANH TOÁN CHO ĐƠN ĐẶT XE
     * ========================================================================
     * Hàm dùng chung được gọi khi thanh toán trực tuyến xác thực thành công.
     * Cập nhật trạng thái đơn, lưu giao dịch tài chính và phát tín hiệu thông báo.
     *
     * @param  \App\Models\Booking  $booking      Đối tượng đơn đặt xe.
     * @param  string|null          $description  Mô tả nguồn thanh toán (VNPay IPN/Return...).
     * @param  float|null           $amount       Số tiền thực tế thanh toán (nếu khác deposit mặc định).
     * @return void
     */
    public function markBookingPaid(Booking $booking, ?string $description = null, ?float $amount = null): void
    {
        if ($booking->status !== 'pending_payment') {
            return;
        }

        $booking->status = 'confirmed';
        $booking->save();

        // Ghi nhận giao dịch nộp cọc thanh toán vào hệ thống
        Transaction::create([
            'wallet_id'    => null,
            'booking_id'   => $booking->id,
            'amount'       => $amount ?? $booking->deposit_amount,
            'type'         => 'credit',
            'balance_type' => 'available',
            'description'  => $description ?? 'Thanh toán VNPAY thành công',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Gửi thông báo xác nhận đến Chủ xe
        $booking->loadMissing('vehicle.owner', 'renter');
        if ($booking->vehicle && $booking->vehicle->owner) {
            $booking->vehicle->owner->notify(new BookingPaidNotification($booking));
        }

        // Gửi thông báo xác nhận đến Khách thuê
        if ($booking->renter) {
            $booking->renter->notify(new BookingPaidRenterNotification($booking));
        }
    }

    /**
     * ========================================================================
     * 3. HÀM ĐỐI SOÁT: XỬ LÝ TRẢ VỀ TỪ TRÌNH DUYỆT (VNPAY RETURN URL)
     * ========================================================================
     * Điểm tiếp nhận khi cổng VNPay chuyển hướng người dùng trở lại sau khi thanh toán.
     * Phục vụ như một lớp Fallback tự động xử lý đơn/nạp ví khi IPN chưa thi hành kịp.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số URL trả về từ VNPay.
     * @return \Illuminate\Http\RedirectResponse  Chuyển hướng về Frontend theo kết quả.
     */
    public function vnpayReturn(Request $request)
    {
        $inputData = $request->all();
        $vnp_SecureHash = $inputData['vnp_SecureHash'] ?? '';
        unset($inputData['vnp_SecureHash']);
        unset($inputData['vnp_SecureHashType']);

        ksort($inputData);
        $i = 0;
        $hashData = "";
        foreach ($inputData as $key => $value) {
            if ($i == 1) {
                $hashData .= '&' . urlencode($key) . "=" . urlencode($value);
            } else {
                $hashData .= urlencode($key) . "=" . urlencode($value);
                $i = 1;
            }
        }

        // Khôi phục chữ ký bảo mật để đối soát tính toàn vẹn của dữ liệu VNPay
        $vnp_HashSecret = config('vnpay.hash_secret');
        $secureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);

        $vnp_ResponseCode = $request->vnp_ResponseCode ?? '';
        $txnRef = $request->vnp_TxnRef ?? '';
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');

        // --------------------------------------------------------------------
        // NHÁNH BỔ TRỢ: XỬ LÝ GIAO DỊCH NẠP TIỀN VÀO VÍ (Tiền tố WALLET_)
        // --------------------------------------------------------------------
        if (str_starts_with($txnRef, 'WALLET_')) {
            if ($secureHash === $vnp_SecureHash && $vnp_ResponseCode === "00") {
                // FALLBACK: Cập nhật số dư ví nếu IPN chưa kịp chạy hoặc bị chặn (test localhost)
                $parts = explode('_', $txnRef);
                $transactionId = $parts[1] ?? null;
                $vnpAmount = isset($inputData['vnp_Amount']) ? ((float)$inputData['vnp_Amount'] / 100) : 0;

                if ($transactionId && $vnpAmount > 0) {
                    \Illuminate\Support\Facades\DB::beginTransaction();
                    try {
                        // Áp dụng khóa bi quan trên giao dịch để tránh lặp cộng tiền với IPN
                        $transaction = Transaction::where('id', $transactionId)->lockForUpdate()->first();
                        if ($transaction && $transaction->status === 'pending') {
                            $wallet = Wallet::where('id', $transaction->wallet_id)->lockForUpdate()->first();
                            if ($wallet) {
                                $wallet->available_balance += round($vnpAmount, 2);
                                
                                // Mở khóa ví tự động nếu số dư hết nợ vi phạm (>= 0)
                                if ($wallet->status === 'locked' && $wallet->available_balance >= 0) {
                                    $wallet->status = 'active';
                                    $wallet->locked_reason = null;
                                    $wallet->locked_at = null;
                                    
                                    // Tự động tháo gỡ lệnh khóa niêm yết cho toàn bộ xe của Chủ xe
                                    \App\Models\Vehicle::where('owner_id', $wallet->user_id)
                                        ->where('status', 'locked')
                                        ->update(['status' => 'available']);
                                }
                                $wallet->save();
                            }
                            
                            $transaction->status = 'success';
                            $transaction->description = 'Nạp tiền VNPAY thành công (Qua Return URL)';
                            $transaction->save();
                        }
                        \Illuminate\Support\Facades\DB::commit();
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\DB::rollBack();
                        \Illuminate\Support\Facades\Log::error("Lỗi cập nhật ví Return URL: " . $e->getMessage());
                    }
                }
                
                return redirect()->to($frontendUrl . "/wallet?vnp_ResponseCode=00");
            } else {
                // Xử lý ghi nhận tình trạng giao dịch thất bại hoặc bị hủy bởi user
                $parts = explode('_', $txnRef);
                $transactionId = $parts[1] ?? null;
                if ($transactionId) {
                    \Illuminate\Support\Facades\DB::beginTransaction();
                    try {
                        $transaction = Transaction::where('id', $transactionId)->lockForUpdate()->first();
                        if ($transaction && $transaction->status === 'pending') {
                            $transaction->status = 'failed';
                            $transaction->description = 'Nạp tiền VNPAY thất bại';
                            $transaction->save();
                        }
                        \Illuminate\Support\Facades\DB::commit();
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\DB::rollBack();
                    }
                }
                return redirect()->to($frontendUrl . "/wallet?vnp_ResponseCode=99");
            }
        }

        // --------------------------------------------------------------------
        // NHÁNH TRUNG TÂM: XỬ LÝ LÝ THANH TOÁN ĐƠN ĐẶT XE MẶC ĐỊNH
        // --------------------------------------------------------------------
        if ($secureHash === $vnp_SecureHash && $vnp_ResponseCode === "00") {
            $booking = Booking::find($txnRef);
            if ($booking) {
                $this->markBookingPaid($booking, 'Thanh toán VNPAY (Cập nhật qua Return URL)');
            }
            return redirect()->to($frontendUrl . "/payment/success?booking_id=" . $txnRef);
        } else {
            return redirect()->to($frontendUrl . "/payment/failed?booking_id=" . $txnRef);
        }
    }
    
    /**
     * ========================================================================
     * 4. HÀM TÍCH HỢP: TRUY VẤN TRẠNG THÁI GIAO DỊCH (QUERYDR VNPAY API)
     * ========================================================================
     * Truy vấn trực tiếp đến máy chủ VNPay (thí dụ qua CRON hoặc Job đối soát)
     * để xác minh thực tế trạng thái đơn khi webhook IPN bị tắc nghen hoặc thất lạc.
     *
     * @param  \App\Models\Booking  $booking  Đối tượng Đơn đặt xe cần kiểm tra.
     * @return bool                 True nếu thanh toán hợp lệ, False nếu chưa trả tiền hoặc lỗi.
     */
    public function vnpayQueryTransaction($booking)
    {
        $vnp_TmnCode = config('vnpay.tmn_code');
        $vnp_HashSecret = config('vnpay.hash_secret');
        $vnp_apiUrl = "https://sandbox.vnpayment.vn/merchant_webapi/api/transaction";
        
        // Chuẩn bị các trường dữ liệu định nghĩa truy vấn QueryDR
        $vnp_RequestId = rand(100000, 999999) . time();
        $vnp_Command = "querydr";
        $vnp_TxnRef = $booking->id;
        $vnp_OrderInfo = "Truy van giao dich " . $booking->id;
        
        // Theo chuẩn VNPay: vnp_TransactionDate là thời điểm tạo giao dịch ban đầu.
        // Cần đảm bảo định dạng YmdHis chuẩn giờ Asia/Ho_Chi_Minh
        $vnp_TransactionDate = $booking->updated_at->timezone('Asia/Ho_Chi_Minh')->format('YmdHis');
        $vnp_CreateDate = now()->timezone('Asia/Ho_Chi_Minh')->format('YmdHis');
        $vnp_IpAddr = "127.0.0.1";
        
        $datarq = array(
            "vnp_RequestId" => $vnp_RequestId,
            "vnp_Version" => "2.1.0",
            "vnp_Command" => $vnp_Command,
            "vnp_TmnCode" => $vnp_TmnCode,
            "vnp_TxnRef" => $vnp_TxnRef,
            "vnp_OrderInfo" => $vnp_OrderInfo,
            "vnp_TransactionDate" => $vnp_TransactionDate,
            "vnp_CreateDate" => $vnp_CreateDate,
            "vnp_IpAddr" => $vnp_IpAddr
        );
        
        // Tạo chuỗi Hash ghép theo chuẩn cấu trúc QueryDR
        $format = '%s|%s|%s|%s|%s|%s|%s|%s|%s';
        $dataHash = sprintf(
            $format,
            $datarq['vnp_RequestId'], 
            $datarq['vnp_Version'], 
            $datarq['vnp_Command'], 
            $datarq['vnp_TmnCode'], 
            $datarq['vnp_TxnRef'], 
            $datarq['vnp_TransactionDate'], 
            $datarq['vnp_CreateDate'], 
            $datarq['vnp_IpAddr'], 
            $datarq['vnp_OrderInfo']
        );
        
        $secureHash = hash_hmac('sha512', $dataHash, $vnp_HashSecret);
        $datarq["vnp_SecureHash"] = $secureHash;
        
        try {
            // Gửi yêu cầu HTTP POST xác minh qua Merchant Web API
            $response = \Illuminate\Support\Facades\Http::post($vnp_apiUrl, $datarq);
            if ($response->successful()) {
                $responseData = $response->json();
                // vnp_ResponseCode = '00' nghĩa là giao dịch truy vấn thành công.
                // vnp_TransactionStatus = '00' nghĩa là khách ĐÃ THANH TOÁN THÀNH CÔNG.
                if (isset($responseData['vnp_ResponseCode']) && $responseData['vnp_ResponseCode'] === '00') {
                    if (isset($responseData['vnp_TransactionStatus']) && $responseData['vnp_TransactionStatus'] === '00') {
                        return true;
                    }
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Lỗi QueryDR VNPAY: " . $e->getMessage());
        }
        
        return false;
    }

    /**
     * ========================================================================
     * 5. HÀM ĐỐI SOÁT NGẦM: XỬ LÝ WEBHOOK IPN (INSTANT PAYMENT NOTIFICATION)
     * ========================================================================
     * API tiếp nhận các thông báo real-time được gọi tự động trực tiếp từ máy chủ VNPay.
     * Là cơ sở chính thống nhất để ghi nhận doanh thu và chuyển cọc thành công theo chuẩn kĩ thuật.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu Webhook được đẩy về từ VNPay.
     * @return \Illuminate\Http\JsonResponse       Mã code phản hồi xác minh theo tài liệu VNPay.
     */
    public function vnpayIpn(Request $request)
    {
        $inputData = $request->all();
        $vnp_SecureHash = $inputData['vnp_SecureHash'] ?? '';
        unset($inputData['vnp_SecureHash']);
        unset($inputData['vnp_SecureHashType']);

        ksort($inputData);
        $i = 0;
        $hashData = "";
        foreach ($inputData as $key => $value) {
            if ($i == 1) {
                $hashData .= '&' . urlencode($key) . "=" . urlencode($value);
            } else {
                $hashData .= urlencode($key) . "=" . urlencode($value);
                $i = 1;
            }
        }

        $vnp_HashSecret = config('vnpay.hash_secret');
        $secureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);

        // Đối chiếu chữ ký hash nhằm ngăn chặn tấn công giả mạo yêu cầu thanh toán
        if ($secureHash !== $vnp_SecureHash) {
            \Log::error('VNPAY IPN: Invalid signature');
            return response()->json(['RspCode' => '97', 'Message' => 'Invalid signature']);
        }

        $txnRef = $inputData['vnp_TxnRef'] ?? null;
        $vnpAmount = isset($inputData['vnp_Amount']) ? ((float)$inputData['vnp_Amount'] / 100) : null;

        if (!$txnRef || $vnpAmount === null) {
            return response()->json(['RspCode' => '99', 'Message' => 'Invalid request']);
        }

        // ====================================================================
        // NHÁNH 1: XỬ LÝ ĐỐI SOÁT IPN CHO GIAO DỊCH NẠP VÍ (PREFIX WALLET_)
        // ====================================================================
        if (str_starts_with($txnRef, 'WALLET_')) {
            $parts = explode('_', $txnRef);
            $transactionId = $parts[1] ?? null;

            if (!$transactionId) {
                return response()->json(['RspCode' => '01', 'Message' => 'Transaction not found']);
            }

            try {
                return DB::transaction(function () use ($inputData, $transactionId, $vnpAmount) {
                    // Mở khóa dòng giao dịch nạp tiền để bảo mật tính tuần tự
                    $transaction = Transaction::where('id', $transactionId)->lockForUpdate()->first();

                    if (!$transaction) {
                        return response()->json(['RspCode' => '01', 'Message' => 'Transaction not found']);
                    }

                    // Tránh xử lý đúp nếu Return URL hoặc tiến trình khác đã xác thực thành công trước đó
                    if ($transaction->description === 'Nạp tiền VNPAY thành công') {
                        return response()->json(['RspCode' => '02', 'Message' => 'Transaction already confirmed']);
                    }

                    $expected = round((float) $transaction->amount, 2);
                    $paid = round((float) $vnpAmount, 2);

                    // Kiểm tra sự trùng khớp chính xác về mệnh giá thực nộp
                    if ($expected !== $paid) {
                        return response()->json(['RspCode' => '04', 'Message' => 'Invalid amount']);
                    }

                    $success = ($inputData['vnp_ResponseCode'] ?? null) === '00'
                        && ($inputData['vnp_TransactionStatus'] ?? null) === '00';

                    if ($success) {
                        // Cộng tiền nạp vào số dư khả dụng của ví
                        $wallet = Wallet::where('id', $transaction->wallet_id)->lockForUpdate()->first();
                        if ($wallet) {
                            $wallet->available_balance += $paid;

                            // Tự động mở khóa ví nếu sau khi nạp đã trả đủ hết các khoản nợ hệ thống
                            if ($wallet->status === 'locked' && $wallet->available_balance >= 0) {
                                $wallet->status = 'active';
                                $wallet->locked_reason = null;
                                $wallet->locked_at = null;

                                // Tự động tháo gỡ lệnh khóa niêm yết cho toàn bộ xe của Chủ xe
                                \App\Models\Vehicle::where('owner_id', $wallet->user_id)
                                    ->where('status', 'locked')
                                    ->update(['status' => 'available']);
                            }

                            $wallet->save();
                        }
                        
                        $transaction->status = 'success';
                        $transaction->description = 'Nạp tiền VNPAY thành công';
                        $transaction->save();

                        return response()->json(['RspCode' => '00', 'Message' => 'Confirm Success']);
                    }

                    // Ghi nhận thanh toán thất bại nếu vnp_ResponseCode khác '00'
                    $transaction->status = 'failed';
                    $transaction->description = 'Nạp tiền VNPAY thất bại';
                    $transaction->save();
                    return response()->json(['RspCode' => '00', 'Message' => 'Confirm Success']);
                });
            } catch (\Throwable $e) {
                \Log::error('VNPAY IPN Wallet Error: ' . $e->getMessage());
                return response()->json(['RspCode' => '99', 'Message' => 'System error']);
            }
        }

        // ====================================================================
        // NHÁNH 2: XỬ LÝ ĐỐI SOÁT IPN CHO GIAO DỊCH THANH TOÁN ĐƠN ĐẶT XE
        // ====================================================================
        try {
            return DB::transaction(function () use ($inputData, $txnRef, $vnpAmount) {
                // Mở khóa dòng Booking để xử lý chốt đơn
                $booking = Booking::where('id', $txnRef)->lockForUpdate()->first();

                if (!$booking) {
                    return response()->json(['RspCode' => '01', 'Message' => 'Order not found']);
                }

                $expected = round((float) $booking->deposit_amount, 2);
                $paid = round((float) $vnpAmount, 2);

                if ($expected !== $paid) {
                    return response()->json(['RspCode' => '04', 'Message' => 'Invalid amount']);
                }

                // Tránh ghi nhận trùng lặp nếu đơn đã chuyển sang Trạng thái Cọc / Hoàn thành
                if (in_array($booking->status, ['confirmed', 'completed'])) {
                    return response()->json(['RspCode' => '02', 'Message' => 'Order already confirmed']);
                }

                $success = ($inputData['vnp_ResponseCode'] ?? null) === '00'
                    && ($inputData['vnp_TransactionStatus'] ?? null) === '00';

                if ($success) {
                    // Thực hiện quy trình ghi cọc và phát tín hiệu thông báo chính thức
                    $this->markBookingPaid(
                        $booking,
                        'Thanh toán VNPAY #' . ($inputData['vnp_TransactionNo'] ?? 'N/A'),
                        $paid
                    );
                    return response()->json(['RspCode' => '00', 'Message' => 'Confirm Success']);
                }

                // Chuyển đơn sang trạng thái Hủy nếu quá trình thanh toán cổng VNPay thất bại
                $booking->status = 'cancelled';
                $booking->save();
                return response()->json(['RspCode' => '00', 'Message' => 'Confirm Success']);
            });
        } catch (\Throwable $e) {
            \Log::error('VNPAY IPN Error: ' . $e->getMessage());
            return response()->json(['RspCode' => '99', 'Message' => 'System error']);
        }
    }
}