<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Jobs\AutoCancelExpiredBookingsJob;
use Illuminate\Http\Request;
use App\Models\Vehicle;
use App\Models\VehicleCalendar;
use App\Models\SystemSetting;
use App\Models\Booking;
use App\Models\Wallet;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Notifications\BookingApprovedNotification;
use App\Notifications\BookingRejectedNotification;
use App\Notifications\NewBookingRequestNotification;

/**
 * ============================================================================
 * LỚP BOOKING CONTROLLER (XỬ LÝ ĐẶT XE & CHUYẾN ĐI)
 * ============================================================================
 * Controller hạt nhân của toàn bộ quy trình đặt xe (Booking Workflow), quản lý từ
 * lúc Khách hàng tính toán thử chi phí, gửi yêu cầu, Chủ xe xác nhận/từ chối cho đến
 * khi bàn giao phương tiện và chốt doanh thu với cơ chế giam tiền bảo đảm (Time-lock 7 ngày).
 */
class BookingController
{
    /**
     * ========================================================================
     * 1. HÀM TÍCH TRỊ (ESTIMATION): TÍNH TOÁN VÀ XEM TRƯỚC CHI PHÍ ĐẶT XE
     * ========================================================================
     * Kiểm tra tính hợp lệ của thời gian đặt, xác minh không trùng lịch (Overlap)
     * và tính toán chính xác tổng chi phí (thuê theo ngày/giờ, bảo hiểm, mã giảm giá)
     * trước khi người dùng thực hiện tạo Đơn đặt xe chính thức.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu yêu cầu (xe, thời gian, tùy chọn trả tiền).
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON chi tiết cấu thành chi phí.
     */
    public function calculatePrice(Request $request)
    {
        // Validate dữ liệu đầu vào cần thiết cho thuật toán
        $request->validate([
            'vehicle_id'     => 'required|exists:vehicles,id',
            'start_datetime' => 'required|date',
            'end_datetime'   => 'required|date|after:start_datetime',
            'payment_option' => 'required|in:full,deposit',
            'promo_code'     => 'nullable|string',
        ]);

        // Định dạng thời gian theo múi giờ chuẩn Việt Nam
        $startDatetime = Carbon::parse($request->start_datetime, 'Asia/Ho_Chi_Minh');
        $endDatetime = Carbon::parse($request->end_datetime, 'Asia/Ho_Chi_Minh');

        // LỖI 6 & 7: Phải đặt trước ít nhất 2 tiếng so với thời điểm hiện tại để Chủ xe kịp chuẩn bị
        if ($startDatetime->isBefore(Carbon::now('Asia/Ho_Chi_Minh')->addHours(2))) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi: Thời gian nhận xe phải cách thời điểm hiện tại ít nhất 2 tiếng để chủ xe chuẩn bị.'
            ], 400);
        }

        // LỖI 8: Quy định nền tảng thời gian thuê mỗi chuyến tối thiểu là 4 tiếng
        if ($startDatetime->diffInHours($endDatetime) < 4) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi: Thời gian thuê tối thiểu là 4 tiếng.'
            ], 400);
        }

        // ====================================================================
        // CHỐT CHẶN BẢO MẬT: KIỂM TRA TRÙNG LỊCH NGAY TẠI BƯỚC XEM TRƯỚC GIÁ
        // (Bổ sung 'pending_approval' để tránh khách chọn nhằm thời điểm đang có đơn khác chờ duyệt)
        // ====================================================================
        $isOverlapping = Booking::where('vehicle_id', $request->vehicle_id)
            ->whereIn('status', ['pending_approval', 'pending_payment', 'confirmed', 'in_progress'])
            ->where(function ($query) use ($request) {
                $query->where('start_datetime', '<', $request->end_datetime)
                      ->where('end_datetime', '>', $request->start_datetime);
            })
            ->exists();

        if ($isOverlapping) {
            return response()->json([
                'success' => false,
                'message' => 'Rất tiếc! Xe này đã có khách đặt trong khoảng thời gian bạn chọn. Vui lòng chọn ngày giờ khác.'
            ], 400);
        }
        // ====================================================================

        // Gọi thuật toán nội bộ để bóc tách chi tiết chi phí
        $costData = $this->calculateBookingCost(
            $request->vehicle_id, 
            $request->start_datetime, 
            $request->end_datetime, 
            $request->payment_option,
            $request->promo_code
        );

        if (!$costData['success']) {
            return response()->json(['success' => false, 'message' => $costData['message']], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tính toán chi phí thành công',
            'data' => $costData['data']
        ]);
    }

    /**
     * ========================================================================
     * 2. HÀM TẠO ĐƠN: GỬI YÊU CẦU ĐẶT XE (BƯỚC KHỞI TẠO CHỜ XÁC NHẬN)
     * ========================================================================
     * Tiếp nhận yêu cầu thuê xe từ Khách thuê, kiểm tra chặt chẽ điều kiện pháp lý
     * (eKYC, Bằng lái), áp dụng khóa cơ sở dữ liệu bi quan (Pessimistic Lock) để chống
     * xung đột đặt trùng (Double Booking), tạo đơn và thông báo tới Chủ xe.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu đơn đặt xe (địa điểm, thời gian, mã giảm giá).
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON chứa Đơn đặt xe vừa tạo (pending_approval).
     */
    public function store(Request $request)
    {
        // Validate dữ liệu trọn bộ yêu cầu
        $request->validate([
            'vehicle_id'       => 'required|exists:vehicles,id',
            'start_datetime'   => 'required|date',
            'end_datetime'     => 'required|date|after:start_datetime',
            'payment_option'   => 'required|in:full,deposit',
            'pickup_location'  => 'required|string',
            'dropoff_location' => 'required|string',
            'promo_code'       => 'nullable|string', 
            'delivery_fee'     => 'nullable|numeric|min:0',
        ]);

        $user = Auth::user();

        // LỖI 1: BẮT BUỘC KHÁCH HÀNG PHẢI HOÀN TẤT XÁC THỰC eKYC
        if ($user->kyc_status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Bạn cần phải hoàn tất xác thực giấy tờ (eKYC) trước khi tiến hành đặt xe.'
            ], 403);
        }

        // BẮT BUỘC KHÁCH PHẢI ĐƯỢC PHÊ DUYỆT BẰNG LÁI XE (CHO QUY TRÌNH TỰ LÁI)
        $hasDriverLicense = \App\Models\LegalDocument::where('user_id', $user->id)
            ->where('document_type', 'driver_license')
            ->where('status', 'approved')
            ->exists();
            
        if (!$hasDriverLicense) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn cần phải tải lên và được phê duyệt Bằng lái xe trước khi thuê xe tự lái.'
            ], 403);
        }

        // KIỂM TRA CHỐT CHẶN NỢ VÍ / KHIẾU NẠI: Từ chối đặt xe nếu Khách có số dư ví âm hoặc ví bị khóa do nợ vi phạm
        $userWallet = \App\Models\Wallet::where('user_id', $user->id)->first();
        if ($userWallet && ($userWallet->available_balance < 0 || $userWallet->status === 'locked')) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản của bạn đang bị cấm đặt xe do có dư nợ âm hoặc vi phạm khiếu nại trong ví chưa được tất toán. Vui lòng nạp tiền hoàn lại khoản nợ trước khi tiếp tục đặt xe.'
            ], 403);
        }

        $startDatetime = Carbon::parse($request->start_datetime, 'Asia/Ho_Chi_Minh');
        $endDatetime = Carbon::parse($request->end_datetime, 'Asia/Ho_Chi_Minh');

        // LỖI 6 & 7: Thời gian chuẩn bị ít nhất 2 tiếng
        if ($startDatetime->isBefore(Carbon::now('Asia/Ho_Chi_Minh')->addHours(2))) {
            return response()->json(['success' => false, 'message' => 'Lỗi: Phải đặt xe trước ít nhất 2 tiếng.'], 400);
        }

        // LỖI 8: Thuê tối thiểu 4 tiếng
        if ($startDatetime->diffInHours($endDatetime) < 4) {
            return response()->json(['success' => false, 'message' => 'Lỗi: Thời gian thuê tối thiểu là 4 tiếng.'], 400);
        }

        // 1. TÍNH TOÁN LẠI TOÀN BỘ GIÁ TIỀN TRÊN SERVER (Tránh gian lận chỉnh sửa từ Client)
        $costData = $this->calculateBookingCost(
            $request->vehicle_id, 
            $request->start_datetime, 
            $request->end_datetime, 
            $request->payment_option,
            $request->promo_code
        );

        if (!$costData['success']) {
            return response()->json(['success' => false, 'message' => $costData['message']], 400);
        }

        // Tổng hợp và chốt các tham số tài chính cho đơn
        $finalTotalAmount = $costData['data']['total_amount'];
        $finalDepositAmount = $costData['data']['deposit_amount'];
        $finalInsuranceFee = $costData['data']['total_insurance_fee'];
        $finalDiscountAmount = $costData['data']['discount_amount'] ?? 0;
        $appliedPromoCode = (!empty($request->promo_code) && $finalDiscountAmount > 0)
            ? $request->promo_code
            : null;
        
        // Bổ sung phí giao xe tận nơi (nếu có yêu cầu từ khách)
        $deliveryFee = $request->input('delivery_fee', 0);
        if ($deliveryFee > 0) {
            $finalTotalAmount += $deliveryFee;
            if ($request->payment_option == 'full') {
                $finalDepositAmount += $deliveryFee;
            } else {
                $finalDepositAmount = round($finalTotalAmount * 0.3);
            }
        }

        try {
            DB::beginTransaction();

            // ========================================================================
            // LỖI 5: GIẢI QUYẾT RACE CONDITION (DOUBLE BOOKING) BẰNG PESSIMISTIC LOCKING
            // Áp dụng lockForUpdate() để khóa dòng Vehicle trên DB đến khi kết thúc Transaction
            // ========================================================================
            $vehicle = Vehicle::where('id', $request->vehicle_id)->lockForUpdate()->first();

            // LỖI 2: KIỂM TRA TRẠNG THÁI SẴN SÀNG CỦA XE THỰC TẾ
            if (!$vehicle || $vehicle->status !== 'available') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Rất tiếc, xe này hiện không sẵn sàng để cho thuê (bị khóa hoặc đang bảo trì).'
                ], 400);
            }

            // LỖI 3: NGĂN CHỦ XE TỰ THUÊ XE CỦA CHÍNH MÌNH (SELF-BOOKING)
            if ($vehicle->owner_id === $user->id) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Bạn không thể tự thuê xe của chính mình.'
                ], 403);
            }

            // KIỂM TRA LẠI TRÙNG LỊCH BÊN TRONG TRANSACTION ĐÃ KHÓA DÒNG XE
            $isOverlapping = Booking::where('vehicle_id', $request->vehicle_id)
                ->whereIn('status', ['pending_approval', 'pending_payment', 'confirmed', 'in_progress'])
                ->where(function ($query) use ($request) {
                    $query->where('start_datetime', '<', $request->end_datetime)
                          ->where('end_datetime', '>', $request->start_datetime);
                })
                ->exists();

            if ($isOverlapping) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Xe đang có khách đặt hoặc chờ duyệt trong thời gian này. Vui lòng chọn ngày giờ khác!'
                ], 400);
            }

            // 2. KHỞI TẠO BOOKING VỚI TRẠNG THÁI CHỜ DUYỆT (pending_approval)
            $booking = Booking::create([
                'renter_id'        => $user->id,
                'vehicle_id'       => $request->vehicle_id,
                'pickup_location'  => $request->pickup_location,
                'dropoff_location' => $request->dropoff_location,
                'start_datetime'   => $request->start_datetime,
                'end_datetime'     => $request->end_datetime,
                'total_amount'     => $finalTotalAmount,  
                'payment_option'   => $request->payment_option,
                'promo_code'       => $appliedPromoCode,
                'discount_amount'  => $finalDiscountAmount,
                'deposit_amount'   => $finalDepositAmount, 
                'status'           => 'pending_approval', 
            ]);

            // 3. TĂNG LƯỢT SỬ DỤNG MÃ KHUYẾN MÃI (Dùng DB Query Builder để xử lý)
            if (!empty($appliedPromoCode)) {
                DB::table('vouchers')
                    ->where('code', $appliedPromoCode)
                    ->increment('used_count'); 
            }

            // 4. LƯU THÊM CÁC KHOẢN PHÍ (BẢO HIỂM / GIAO XE) VÀO CHI TIẾT DỊCH VỤ (BOOKING SERVICES)
            \App\Models\BookingService::create([
                'booking_id' => $booking->id,
                'service_name' => 'Bảo hiểm chuyến đi',
                'price' => $finalInsuranceFee
            ]);

            if ($deliveryFee > 0) {
                \App\Models\BookingService::create([
                    'booking_id' => $booking->id,
                    'service_name' => 'Phí giao nhận xe tận nơi',
                    'price' => $deliveryFee
                ]);
            }

            // Hoàn tất lưu dữ liệu
            DB::commit(); 

            // Phát tín hiệu thông báo có đơn đặt xe mới đến cho Chủ xe
            $booking->loadMissing('vehicle.owner');
            if ($booking->vehicle && $booking->vehicle->owner) {
                $booking->vehicle->owner->notify(new NewBookingRequestNotification($booking));
            }

            return response()->json([
                'success' => true,
                'message' => 'Đã gửi yêu cầu đặt xe thành công! Vui lòng chờ chủ xe xác nhận.',
                'data'    => $booking
            ]);

        } catch (\Exception $e) {
            DB::rollBack(); 
            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống khi tạo yêu cầu: ' . $e->getMessage()], 500);
        }
    }

    /**
     * ========================================================================
     * 3. HÀM THUẬT TOÁN NỘI BỘ: TÍNH TOÁN TOÀN DIỆN CHI PHÍ THUÊ XE
     * ========================================================================
     * Xử lý trọn bộ quy tắc tài chính của hệ thống, phân rã trường hợp:
     * - Thuê ngắn (<= 12h & cùng ngày): Áp dụng giá theo khung (4h = 40%, 8h = 70%, 12h = 90%).
     * - Thuê dài ngày: Khớp đơn giá của từng ngày theo lịch tùy biến (Custom Price).
     * - Phí bảo hiểm cố định 10% chi phí chuyến đi gốc trước giảm giá.
     * - Áp dụng khấu trừ mã khuyến mãi (theo phần trăm hoặc số tiền cố định).
     *
     * @param  int     $vehicleId      ID xe cho thuê.
     * @param  string  $startDatetime  Thời gian nhận xe.
     * @param  string  $endDatetime    Thời gian trả xe.
     * @param  string  $paymentOption  Tùy chọn trả ('full' hoặc 'deposit').
     * @param  string|null $promoCode  Mã giảm giá (nếu có).
     * @return array                   Mảng kết quả tính toán chi phí đầy đủ.
     */
    private function calculateBookingCost($vehicleId, $startDatetime, $endDatetime, $paymentOption, $promoCode = null)
    {
        $startDateObj = Carbon::parse($startDatetime);
        $endDateObj   = Carbon::parse($endDatetime);
        
        $diffHours = $startDateObj->diffInHours($endDateObj);
        // Nhận diện đơn thuê theo giờ (Trong vòng 12 tiếng và cùng 1 ngày lịch)
        $isHourlyRental = ($diffHours <= 12 && $startDateObj->isSameDay($endDateObj));

        $vehicle = Vehicle::find($vehicleId);
        if (!$vehicle) return ['success' => false, 'message' => 'Xe không tồn tại'];

        // Áp dụng giảm giá do chủ xe thiết lập (nếu có) vào giá thuê tiêu chuẩn
        if ($vehicle->is_discount_enabled && $vehicle->weekly_discount_percent > 0) {
            $vehicle->base_price = $vehicle->base_price * (1 - ($vehicle->weekly_discount_percent / 100));
        }

        $totalRentalFee = 0;

        // TRƯỜNG HỢP 1: THUÊ NGẮN THEO GIỜ
        if ($isHourlyRental) {
            $calendar = VehicleCalendar::where('vehicle_id', $vehicleId)
                ->where('date', $startDateObj->format('Y-m-d'))
                ->first();
                
            if ($calendar && $calendar->is_blocked) {
                return ['success' => false, 'message' => "Xe bận vào ngày " . $startDateObj->format('Y-m-d')];
            }
            
            // Ưu tiên lấy giá tùy chỉnh trong ngày nếu có thiết lập, nếu không dùng giá gốc
            $baseDailyPrice = $calendar->custom_price ?? $vehicle->base_price;
            
            if ($diffHours <= 4) {
                $totalRentalFee = $baseDailyPrice * 0.40;
            } elseif ($diffHours <= 8) {
                $totalRentalFee = $baseDailyPrice * 0.70;
            } else {
                $totalRentalFee = $baseDailyPrice * 0.90;
            }
            $rentalDays = 0; 
            
        } else {
            // TRƯỜNG HỢP 2: THUÊ THEO NGÀY (Trên 12 tiếng hoặc vắt sang ngày kế tiếp)
            $totalHours = $startDateObj->diffInHours($endDateObj);
            $rentalDays = (int) ceil($totalHours / 24);
            if ($rentalDays == 0) $rentalDays = 1;

            $startDate = $startDateObj->startOfDay();
            $endDate   = $endDateObj->startOfDay();

            // Truy xuất mảng lịch chứa cấu hình giá linh hoạt hoặc khóa biểu của chủ xe
            $calendars = VehicleCalendar::where('vehicle_id', $vehicleId)
                ->whereBetween('date', [$startDate->format('Y-m-d'), $endDate->copy()->subDay()->format('Y-m-d')])
                ->get()->keyBy('date');

            for ($i = 0; $i < $rentalDays; $i++) {
                $currentDate = $startDate->copy()->addDays($i)->format('Y-m-d');
                if ($calendars->has($currentDate)) {
                    if ($calendars->get($currentDate)->is_blocked) {
                        return ['success' => false, 'message' => "Xe bận vào ngày {$currentDate}."];
                    }
                    $totalRentalFee += $calendars->get($currentDate)->custom_price ?? $vehicle->base_price;
                } else {
                    $totalRentalFee += $vehicle->base_price;
                }
            }
        }

        // Quy định phí bảo hiểm: Bằng 10% của chi phí gốc chưa trừ ưu đãi
        $totalInsuranceFee = $totalRentalFee * 0.10;
        
        $totalAmount = $totalRentalFee + $totalInsuranceFee;
        $discountAmount = 0;

        // Bóc tách ưu đãi Mã giảm giá (Vouchers & Discount Campaigns)
        if (!empty($promoCode)) {
            $voucher = DB::table('vouchers')
                ->join('discount_campaigns', 'vouchers.campaign_id', '=', 'discount_campaigns.id')
                ->where('vouchers.code', $promoCode)
                ->select('vouchers.*', 'discount_campaigns.type', 'discount_campaigns.value as discount_value')
                ->first();

            if ($voucher) {
                if ($voucher->type === 'percentage') {
                    $discountAmount = $totalAmount * ($voucher->discount_value / 100);
                } elseif ($voucher->type === 'fixed_amount') {
                    $discountAmount = $voucher->discount_value;
                }
                
                // Đảm bảo số tiền giảm giá tối đa không vượt quá tổng chi phí
                $discountAmount = min($discountAmount, $totalAmount);
                $totalAmount -= $discountAmount;
            } else {
                return ['success' => false, 'message' => 'Mã giảm giá không hợp lệ hoặc đã hết hạn!'];
            }
        }

        // Khoản đặt cọc giữ xe: Nếu khách chọn cọc, nộp 30% giá trị thanh toán, ngược lại nộp 100%
        $depositAmount = ($paymentOption == 'deposit') ? ($totalAmount * 0.3) : $totalAmount;

        return [
            'success' => true,
            'data' => [
                'is_hourly'           => $isHourlyRental,
                'rental_duration'     => $isHourlyRental ? $diffHours : $rentalDays,
                'total_rental_fee'    => round($totalRentalFee),
                'total_insurance_fee' => round($totalInsuranceFee),
                'discount_amount'     => round($discountAmount), 
                'total_amount'        => round($totalAmount),
                'deposit_amount'      => round($depositAmount),
            ]
        ];
    }

    /**
     * ========================================================================
     * 4. HÀM TRUY VẤN: LẤY DANH SÁCH CHUYẾN ĐI CỦA KHÁCH THUÊ (MY BOOKINGS)
     * ========================================================================
     * Tự động khởi chạy công việc dọn rác (hủy đơn hết hạn 2 giờ) và trả về mảng
     * lịch sử chuyến đi phân theo trật tự thời gian mới nhất cho user.
     *
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON danh sách đặt xe.
     */
    public function myBookings()
    {
        $user = Auth::user();

        // Kích hoạt thủ công công việc hủy đơn ngâm quá thời gian chờ duyệt/thanh toán
        app(AutoCancelExpiredBookingsJob::class)->handle();

        // Tải kèm quan hệ xe, hình ảnh xe, model và chủ xe để hiển thị trọn vẹn chuyến đi trên Client
        $bookings = Booking::with(['vehicle.carModel', 'vehicle.images', 'vehicle.owner']) 
            ->where('renter_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $bookings
        ]);
    }

    /**
     * ========================================================================
     * 5. HÀM CHI TIẾT: XEM TOÀN TRỢ THỰC TRẠNG 1 ĐƠN ĐẶT XE CỤ THỂ
     * ========================================================================
     * Kiểm soát quyền rà soát của 2 đối tượng hợp lệ: Khách thuê chính chủ
     * hoặc Đối tác sở hữu phương tiện liên quan.
     *
     * @param  int  $id                           ID đơn đặt xe cần xem.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON toàn vẹn thuộc tính đơn.
     */
    public function show($id)
    {
        $user = Auth::user();

        // Cập nhật trạng thái đơn ngâm quá giờ theo thời gian thực
        app(AutoCancelExpiredBookingsJob::class)->handle();

        // Kéo toàn bô các quan hệ con như ảnh, phụ phí, đơn gia tăng, đánh giá
        $booking = Booking::with([
            'vehicle.images',
            'vehicle.owner',
            'vehicle.carModel.transmission',
            'renter',
            'additionalServices',
            'bookingSurcharges',
            'reviews'
        ])->find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn đặt xe.'
            ], 404);
        }

        // Kiểm tra phân quyền an ninh tài khoản
        $isRenter = $booking->renter_id === $user->id;
        $isOwner = (int) ($booking->vehicle->owner_id ?? 0) === (int) $user->id;

        if (!$isRenter && !$isOwner) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền xem chi tiết đơn này.'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $booking,
        ]);
    }

    /**
     * ========================================================================
     * 6. HÀM DUYỆT ĐƠN: CHỦ XE ĐỒNG Ý YÊU CẦU ĐẶT XE CỦA KHÁCH
     * ========================================================================
     * Chuyển trạng thái đơn từ Chờ duyệt (pending_approval) sang Chờ thanh toán
     * (pending_payment), mở chốt thời gian cho phép khách nộp tiền trong 2 tiếng tiếp theo.
     *
     * @param  int  $id                           ID của đơn đặt xe cần phê duyệt.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON kết quả xử lý duyệt.
     */
    public function approve($id)
    {
        // 1. Kiểm tra sự tồn tại của đơn hàng
        $booking = Booking::with('vehicle')->find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn đặt xe không tồn tại.'
            ], 404);
        }

        // 2. BẢO MẬT: Phân quyền duy nhất cho phép Chủ xe chính chủ được phê duyệt
        if ($booking->vehicle->owner_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền duyệt đơn hàng của xe này.'
            ], 403);
        }

        // 3. Ràng buộc trạng thái cho phép duyệt
        if ($booking->status !== 'pending_approval') {
            return response()->json([
                'success' => false,
                'message' => 'Đơn hàng này không ở trạng thái chờ duyệt hoặc đã được xử lý.'
            ], 400);
        }

        // ====================================================================
        // 3.5 CHỐT CHẶN BẢO MẬT: KIỂM TRA CHỦ XE ĐÃ NGÂM QUÁ THOI GIAN 2 GIỜ CHƯA?
        // ====================================================================
        $expiresAt = $booking->created_at->addHours(2);
        if (now()->greaterThan($expiresAt)) {
            // Trường hợp chủ xe không duyệt kịp trong 2 tiếng -> Tự động chuyển hủy
            $booking->status = 'cancelled';
            $booking->cancel_by = 'system';
            $booking->cancel_reason = 'Chủ xe không duyệt yêu cầu trong thời gian quy định (2 giờ).';
            $booking->cancelled_at = now();
            $booking->save();

            return response()->json([
                'success' => false,
                'message' => 'Rất tiếc! Đơn này đã quá 2 giờ chờ duyệt. Hệ thống đã tự động hủy đơn trả lại lịch trống cho xe.'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // 4. Cập nhật trạng thái đơn hàng sang Chờ thanh toán
            // Thời gian đếm ngược 2 tiếng tiếp theo cho khách thanh toán sẽ dựa trên updated_at
            $booking->status = 'pending_payment';
            $booking->save();

            DB::commit();

            // Phát tín hiệu thông báo đến Khách thuê để kịp thời vào thanh toán cọc
            $booking->loadMissing('renter', 'vehicle.carModel');
            if ($booking->renter) {
                $booking->renter->notify(new BookingApprovedNotification($booking));
            }

            return response()->json([
                'success' => true,
                'message' => 'Đã duyệt yêu cầu thành công! Đơn hàng chuyển sang trạng thái chờ khách thanh toán.',
                'data'    => $booking
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống khi duyệt đơn hàng: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ========================================================================
     * 7. HÀM TỪ CHỐI: CHỦ XE TỪ CHỐI YÊU CẦU THUÊ XE
     * ========================================================================
     * Cho phép Đối tác chủ động từ chối đơn thu mua (kèm theo lý do bắt buộc),
     * hoàn trả lại các ưu đãi (Voucher) và thông báo cho Khách hàng.
     *
     * @param  \Illuminate\Http\Request  $request  Yêu cầu HTTP kèm theo lý do (reject_reason).
     * @param  int                       $id       ID đơn đặt xe cần xử lý.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON thông báo kết quả.
     */
    public function reject(Request $request, $id)
    {
        // Ràng buộc bắt buộc cung cấp lý do từ chối để giải trình với khách
        $request->validate([
            'reject_reason' => 'required|string|max:500'
        ]);

        $booking = Booking::with('vehicle')->find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Đơn đặt xe không tồn tại.'
            ], 404);
        }

        // Xác minh chủ xe chính chủ
        if ($booking->vehicle->owner_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn không có quyền từ chối đơn hàng của xe này.'
            ], 403);
        }

        // Chỉ được phép thao tác khi đơn còn chờ phê duyệt
        if ($booking->status !== 'pending_approval') {
            return response()->json([
                'success' => false,
                'message' => 'Đơn hàng này không thể từ chối vì đã được xử lý trước đó.'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Chuyển sang trạng thái Hủy do Chủ xe khởi xướng
            $booking->status = 'cancelled';
            $booking->cancel_by = 'owner';
            $booking->cancelled_at = now();
            $booking->cancel_reason = $request->reject_reason;
            $booking->save();

            // Hoàn lại lượt sử dụng mã ưu đãi
            if (!empty($booking->promo_code)) {
                DB::table('vouchers')
                    ->where('code', $booking->promo_code)
                    ->where('used_count', '>', 0)
                    ->decrement('used_count');
            }

            // Phát tín hiệu từ chối đơn đặt đến Khách hàng
            $booking->loadMissing('renter', 'vehicle.carModel');
            if ($booking->renter) {
                $booking->renter->notify(new BookingRejectedNotification($booking));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Đã từ chối yêu cầu đặt xe thành công.',
                'data'    => $booking
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống khi từ chối đơn hàng: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ========================================================================
     * 8. HÀM DANH SÁCH YÊU CẦU: CÁC ĐƠN ĐANG CHỜ CHỦ XE XÁC NHẬN
     * ========================================================================
     * Trả về bộ danh sách lọc những đơn ở trạng thái 'pending_approval'
     * trực thuộc toàn bộ hệ sinh thái đội xe của Chủ tài khoản.
     *
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON danh sách yêu cầu chờ duyệt.
     */
    public function ownerRequests()
    {
        $user = Auth::user();

        // Thi hành rà soát đơn rác hết hạn trước khi xuất xướng
        app(\App\Jobs\AutoCancelExpiredBookingsJob::class)->handle();

        $requests = Booking::with(['vehicle.carModel', 'renter:id,name,phone'])
            ->whereHas('vehicle', function($q) use ($user) {
                $q->where('owner_id', $user->id); // Lộc danh sách xe của tài khoản
            })
            ->where('status', 'pending_approval') // Chỉ chọn lọc đơn đang chờ duyệt
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $requests
        ]);
    }

    /**
     * ========================================================================
     * 9. HÀM QUẢN LÝ CHUYẾN ĐI: DANH SÁCH ĐƠN HÀNG ĐANG HOẠT ĐỘNG CỦA CHỦ XE
     * ========================================================================
     * Tổng hợp các Đơn đặt xe đã bước qua giai đoạn duyệt và thanh toán cọc
     * (Đã đặt cọc, Khởi hành, Hoàn thành, Đã hủy) sắp xếp theo tiến độ thời gian.
     *
     * @param  \Illuminate\Http\Request  $request  Yêu cầu HTTP gửi lên.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON danh sách chuyến đi của Chủ xe.
     */
    public function ownerBookings(Request $request)
    {
        $user = Auth::user();

        $bookings = Booking::with([
            'vehicle.carModel', 
            'renter:id,name,phone,avatar',
            'reviews'
        ])
        ->whereHas('vehicle', function($q) use ($user) {
            $q->where('owner_id', $user->id);
        })
        ->whereIn('status', ['confirmed', 'in_progress', 'completed', 'cancelled', 'rejected']) 
        ->orderBy('start_datetime', 'asc') // Sắp xếp theo ưu tiên chuyến chuẩn bị khởi hành
        ->get();

        return response()->json([
            'success' => true,
            'data'    => $bookings
        ]);
    }

    /**
     * ========================================================================
     * 10. HÀM BÀN GIAO XE (HANDOVER): BẮT ĐẦU CHUYẾN ĐI VÀ GIAO TÀI SẢN
     * ========================================================================
     * Cập nhật trạng thái phương tiện sang Khởi hành (in_progress), xác lập thời điểm
     * Khách hàng chịu toàn vẹn trách nhiệm với phương tiện trong giai đoạn sử dụng.
     *
     * @param  \Illuminate\Http\Request  $request  Yêu cầu HTTP gửi lên.
     * @param  int                       $id       ID của Đơn đặt xe cần giao.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON xác nhận bắt đầu lộ trình.
     */
    public function handoverVehicle(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);
        
        // Ràng buộc duy nhất đơn Đã xác nhận tiền cọc (confirmed) mới được bàn giao
        if ($booking->status !== 'confirmed') {
            return response()->json(['success' => false, 'message' => 'Chỉ có thể bàn giao xe cho đơn hàng Đã đặt cọc.'], 400);
        }

        // Chuyển dịch trạng thái hành trình
        $booking->status = 'in_progress';
        $booking->save();

        return response()->json(['success' => true, 'message' => 'Bàn giao xe thành công! Chuyến đi đã bắt đầu.']);
    }

    /**
     * ========================================================================
     * 11. HÀM HỒI TRUNG & CHỐT CHI PHÍ: HOÀN TẤT CHUYẾN & GIAM DOANH THU 7 NGÀY
     * ========================================================================
     * Xác nhận Chủ xe nhận lại phương tiện an toàn, kết thúc chuyến đi (completed).
     * NẾU khách thanh toán toàn phần 100% qua Sàn: Thi hành cơ chế khóa thời gian
     * (Time-Lock) 7 ngày theo chuẩn báo cáo kỹ thuật luận văn, ngăn chặn xung đột
     * khiếu nại phát sinh sau chuyến trước khi thanh toán thực nhận vào ví khả dụng.
     *
     * @param  \Illuminate\Http\Request  $request  Yêu cầu HTTP gửi lên.
     * @param  int                       $id       ID đơn đặt xe cần thu hồi.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON thông tin tất toán hành trình.
     */
    public function completeTrip(Request $request, $id)
    {
        $booking = Booking::findOrFail($id);
        $user = Auth::user();
        
        // Xác minh an ninh tài khoản thao tác
        if ($booking->vehicle->owner_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'Bạn không có quyền thao tác đơn này.'], 403);
        }

        // Ràng buộc duy nhất đơn đang di chuyển (in_progress) mới được phép báo thu hồi
        if ($booking->status !== 'in_progress') {
            return response()->json(['success' => false, 'message' => 'Chỉ có thể kết thúc chuyến khi xe đang trong trạng thái Khởi hành.'], 400);
        }

        try {
            DB::beginTransaction();

            // Xác minh kết thúc thành công
            $booking->status = 'completed'; 
            $booking->save();

            // ====================================================================
            // XỬ LÝ LỢI NHUẬN TÀI CHÍNH CHỦ XE KHỚP TỈ LỆ V V CHƯƠNG TRÌNH TRẦN 70%
            // - Nếu khách cọc 30%, Sàn giữ cọc 30% làm phí nền tảng, Chủ xe đã tự thu trực tiếp 70%.
            // - Nếu khách thanh toán Full 100%, Sàn phải chuyển khoản 70% vào Ví cho Chủ xe.
            // ====================================================================
            if ($booking->payment_option === 'full') {
                $ownerWallet = Wallet::firstOrCreate(
                    ['user_id' => $booking->vehicle->owner_id],
                    ['available_balance' => 0, 'pending_balance' => 0]
                );

                // Lợi nhuận định kỳ quy định cho Đối tác chủ xe: 70% tổng hóa đơn
                $payoutAmount = round($booking->total_amount * 0.7); 
                
                // THEO ĐÚNG BÁO CÁO LUẬN VĂN: TIME-LOCK 7 NGÀY (CƠ CHẾ KHOÁ DUY TRÌ BẢO TRÌ/KHIẾU NẠI)
                // Tiền chưa vào ngay Ví khả dụng mà chuyển lưu dưới dạng giao dịch chờ mở khóa sau 7 ngày
                Transaction::create([
                    'wallet_id'    => $ownerWallet->id,
                    'booking_id'   => $booking->id,
                    'amount'       => $payoutAmount,
                    'type'         => 'credit',
                    'balance_type' => 'available',
                    'status'       => 'pending',
                    'release_at'   => now()->addDays(7), // Giam tài chính an toàn trong 7 ngày
                    'description'  => 'Nhận doanh thu từ chuyến đi #' . $booking->id . ' (Tạm giữ 7 ngày)',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }

            // Hoàn tất Transaction
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Tuyệt vời! Chuyến đi đã kết thúc thành công.']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
}

