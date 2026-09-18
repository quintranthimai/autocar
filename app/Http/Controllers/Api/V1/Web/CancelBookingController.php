<?php

namespace App\Http\Controllers\Api\V1\Web;

use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\Vehicle;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Notifications\BookingCancelledNotification;
use App\Models\User;

/**
 * ============================================================================
 * LỚP CANCEL BOOKING CONTROLLER (HỆ THỐNG XỬ LÝ HỦY CHUYẾN & ĐỀN BÙ TÀI CHÍNH)
 * ============================================================================
 * Controller nghiệp vụ phụ trách xử lý việc hủy đơn đặt xe từ 2 phía: Khách thuê
 * (Renter) và Chủ xe (Owner/Partner), tích hợp các quy tắc tính toán phí phạt, 
 * hoàn cọc, đền bù từ Quỹ bảo hiểm và xử lý trạng thái bảo trì phương tiện.
 */
class CancelBookingController
{
    /**
     * ========================================================================
     * 1. HÀM HỖ TRỢ: TRUY VẤN HOẶC KHỞI TẠO VÍ ĐIỆN TỬ VỚI CƠ CHẾ KHÓA DÒNG
     * ========================================================================
     * Tìm ví của người dùng theo User ID và áp dụng khóa bi quan (Pessimistic Lock)
     * nhằm bảo vệ dữ liệu tài chính trong môi trường xung đột song song (Race Condition).
     *
     * @param  int  $userId  ID của người dùng cần truy xuất ví.
     * @return \App\Models\Wallet Đối tượng Ví đã được khóa dòng trong DB Transaction.
     */
    private function getOrCreateWalletLocked($userId) {
        // Áp dụng lockForUpdate() để ngăn các giao dịch khác tác động cùng lúc
        $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
        if (!$wallet) {
            // Trường hợp người dùng chưa có ví, tiến hành khởi tạo ví mới với số dư 0
            $wallet = Wallet::create([
                'user_id' => $userId, 
                'available_balance' => 0, 
                'deposit_balance' => 0, 
                'status' => 'active'
            ]);
            // Khóa dòng ví vừa tạo để đảm bảo đồng bộ cho các xử lý tiếp theo
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
        }
        return $wallet;
    }

    /**
     * ========================================================================
     * 2. LUỒNG XỬ LÝ: KHÁCH THUÊ (RENTER) CHỦ ĐỘNG HỦY CHUYẾN
     * ========================================================================
     * Xử lý yêu cầu hủy chuyến đi từ phía khách thuê với các điều kiện tài chính:
     * - Hủy SỚM (>= 24 giờ trước thời điểm nhận xe): Hoàn 100% tiền thanh toán cho khách.
     * - Hủy MUỘN (< 24 giờ): Khách chịu phạt mất tiền cọc quy định (30% giá trị chuyến đi)
     *   làm phí nền tảng, hoàn lại phần chênh lệch (nếu khách đã thanh toán full 100%).
     *   Đồng thời sàn trích từ Quỹ bảo hiểm bồi thường khoản cọc 30% này cho Chủ xe.
     *
     * @param  \Illuminate\Http\Request  $request    Dữ liệu yêu cầu gửi lên (lý do hủy).
     * @param  int                       $bookingId  ID của đơn đặt xe cần hủy.
     * @return \Illuminate\Http\JsonResponse         Phản hồi JSON về kết quả thực hiện.
     */
    public function cancelByRenter(Request $request, $bookingId)
    {
        $user = Auth::user();
        
        // Validate lý do hủy (tùy chọn nhưng bị giới hạn tối đa 500 ký tự)
        $request->validate([
            'reason' => 'nullable|string|max:500'
        ]);

        try {
            // Bắt đầu giao dịch cơ sở dữ liệu (Database Transaction)
            DB::beginTransaction();

            // Khóa dòng Đơn đặt xe (Booking) để xử lý tuần tự, chống lặp giao dịch
            $booking = Booking::with('vehicle')->where('id', $bookingId)->lockForUpdate()->firstOrFail();

            // BẢO MẬT: Kiểm tra tính hợp lệ của người hủy (Phải chính là khách thuê của đơn này)
            if ($booking->renter_id !== $user->id) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Không có quyền hủy chuyến đi này.'], 403);
            }

            // Ràng buộc trạng thái cho phép hủy (Chưa thanh toán, Chờ duyệt, hoặc Đã xác nhận/Đã cọc)
            if (!in_array($booking->status, ['pending', 'pending_approval', 'pending_payment', 'confirmed'])) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Không thể hủy chuyến đi ở trạng thái hiện tại.'], 400);
            }

            // Tính toán chênh lệch thời gian giữa hiện tại và thời điểm nhận xe
            $now = Carbon::now();
            $startDatetime = Carbon::parse($booking->start_datetime);
            $hoursUntilStart = $now->diffInHours($startDatetime, false);

            // Các thông số tài chính của chuyến đi
            $deposit = $booking->deposit_amount;
            $total = $booking->total_amount;
            $amountPaidByCustomer = $deposit; 
            
            // Tiền cọc quy định theo chính sách nền tảng là 30% của tổng giá trị
            $tienCocQuyDinh = $total * 0.3;

            // NẾU ĐƠN HÀNG ĐÃ XÁC NHẬN VÀ KHÁCH ĐÃ THANH TOÁN TIỀN
            if ($booking->status === 'confirmed' && $amountPaidByCustomer > 0) {
                // Khóa cả hai ví của Khách thuê và Chủ xe để xử lý chuyển nhượng tài chính
                $renterWallet = $this->getOrCreateWalletLocked($user->id);
                $ownerWallet = $this->getOrCreateWalletLocked($booking->vehicle->owner_id);
                
                if ($hoursUntilStart >= 24) {
                    // TRƯỜNG HỢP 1: Hủy SỚM (>= 24h) -> Hoàn 100% tiền cho Khách
                    $renterWallet->available_balance += $amountPaidByCustomer;
                    $renterWallet->save();

                    // Lưu vết giao dịch hoàn tiền cho khách
                    Transaction::create([
                        'wallet_id' => $renterWallet->id,
                        'booking_id' => $booking->id,
                        'amount' => $amountPaidByCustomer,
                        'type' => 'credit',
                        'balance_type' => 'available',
                        'description' => 'Hoàn tiền do hủy chuyến sớm (trước 24 giờ).'
                    ]);
                } else {
                    // TRƯỜNG HỢP 2: Hủy MUỘN (< 24h)
                    // - Khách bị mất cọc 30% (Sàn thu làm phí vi phạm hợp đồng).
                    // - Khách chỉ nhận lại khoản dư nếu trước đó đã thanh toán > 30% (VD: 100%).
                    $refundAmount = $amountPaidByCustomer - $tienCocQuyDinh; 
                    
                    if ($refundAmount > 0) {
                        $renterWallet->available_balance += $refundAmount;
                        $renterWallet->save();
                        
                        Transaction::create([
                            'wallet_id' => $renterWallet->id,
                            'booking_id' => $booking->id,
                            'amount' => $refundAmount,
                            'type' => 'credit',
                            'balance_type' => 'available',
                            'description' => 'Hoàn phần dư do hủy chuyến trễ (Mất cọc 30% làm phí nền tảng).'
                        ]);
                    }
                    
                    // - Phí cọc 30% thu được của Khách hàng: Sàn giữ lại 30% làm Phí sàn hủy đơn, 70% bồi thường cho Chủ xe
                    $ownerCompensation = round($tienCocQuyDinh * 0.7, 2);
                    $platformFee = round($tienCocQuyDinh * 0.3, 2);

                    $ownerWallet->available_balance += $ownerCompensation;
                    $ownerWallet->save();

                    Transaction::create([
                        'wallet_id' => $ownerWallet->id,
                        'booking_id' => $booking->id,
                        'amount' => $ownerCompensation,
                        'type' => 'credit',
                        'balance_type' => 'available',
                        'description' => 'Bồi thường hủy chuyến trễ (70% tiền cọc thu từ khách, Sàn giữ 30% phí xử lý).'
                    ]);
                }
            }

            // Cập nhật trạng thái đơn hàng sang Đã hủy (cancelled) và ghi nhận nguyên nhân
            $booking->status = 'cancelled';
            $booking->cancel_by = 'renter';
            $booking->cancel_reason = $request->cancel_reason;
            $booking->cancelled_at = $now;
            $booking->save();

            // Hoàn trả lượt sử dụng Voucher giảm giá nếu khách từng áp dụng cho đơn này
            if (!empty($booking->promo_code)) {
                DB::table('vouchers')
                    ->where('code', $booking->promo_code)
                    ->where('used_count', '>', 0)
                    ->decrement('used_count');
            }

            // Xử lý gửi thông báo (Notification) đến Chủ xe về tình trạng chuyến đi
            $owner = User::find($booking->vehicle->owner_id);
            if ($owner) {
                $msg = $hoursUntilStart < 24 
                    ? "Khách thuê đã hủy chuyến #{$booking->id}. Bạn được bồi thường " . number_format($tienCocQuyDinh) . " VNĐ từ Quỹ Bảo Hiểm."
                    : "Khách thuê đã hủy chuyến #{$booking->id} sớm. Ngày thuê đã được trống để đón khách mới.";
                $owner->notify(new BookingCancelledNotification($msg, $booking->id));
            }

            // Xác nhận thành công và commit toàn bộ thay đổi dữ liệu
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Hủy chuyến thành công!',
                'penalty_applied' => $hoursUntilStart < 24
            ]);

        } catch (\Exception $e) {
            // Đảo ngược toàn bộ thay đổi dữ liệu nếu gặp lỗi hệ thống
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    /**
     * ========================================================================
     * 3. LUỒNG XỬ LÝ: CHỦ XE (OWNER) HỦY CHUYẾN DO SỰ CỐ KỸ THUẬT
     * ========================================================================
     * Xử lý yêu cầu hủy chuyến đi từ phía Chủ xe trong trường hợp xe gặp sự cố:
     * - Luôn hoàn trả 100% số tiền đã nhận cho Khách thuê (Renter).
     * - Yêu cầu Chủ xe nộp minh chứng hợp lệ (>= 2 hình ảnh hiện trường sự cố):
     *   + Nếu minh chứng KHÔNG hợp lệ (< 2 ảnh): Sàn bồi thường thêm cho khách 50%
     *     tổng giá trị chuyến đi (từ Quỹ bảo hiểm), đồng thời ghi nợ vào ví Chủ xe.
     *   + Nếu ví Chủ xe bị âm tiền do ghi nợ -> Tự động KHÓA VÍ tạm thời.
     * - Đưa phương tiện về trạng thái Bảo trì (maintenance) để rà soát an toàn.
     * - Khởi tạo Ticket cho Admin xử lý thắc mắc hoặc kiểm định sự cố thực tế.
     *
     * @param  \Illuminate\Http\Request  $request    Yêu cầu chứa lý do và ảnh minh chứng.
     * @param  int                       $bookingId  ID của đơn đặt xe cần hủy.
     * @return \Illuminate\Http\JsonResponse         Phản hồi JSON kết quả xử lý.
     */
    public function cancelByOwner(Request $request, $bookingId)
    {
        $user = Auth::user();

        // Validate lý do và danh sách ảnh minh chứng (tối đa 5MB/ảnh)
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
            'evidences'     => 'nullable|array', 
            'evidences.*'   => 'file|mimes:jpeg,png,jpg,webp,gif,bmp,svg,avif|max:10240'
        ], [
            'evidences.*.mimes' => 'Hình ảnh tải lên phải thuộc định dạng: jpeg, png, jpg, gif, webp, bmp, svg hoặc avif.',
            'evidences.*.max' => 'Kích thước mỗi ảnh không được vượt quá 10MB.',
            'cancel_reason.required' => 'Vui lòng nhập lý do hủy chuyến.'
        ]);

        // Xử lý lưu trữ hình ảnh minh chứng lên hệ thống file tĩnh (public disk)
        $evidences = [];
        if ($request->hasFile('evidences')) {
            foreach ($request->file('evidences') as $file) {
                $path = $file->store('evidences', 'public');
                $evidences[] = '/storage/' . $path;
            }
        }
        
        // Quy định: Minh chứng được coi là hợp lệ (bước đầu) nếu tải lên từ 2 ảnh trở lên
        $isValidEvidence = count($evidences) >= 2;

        try {
            // Bắt đầu giao dịch cơ sở dữ liệu
            DB::beginTransaction();

            // Khóa dòng Đơn đặt xe cùng quan hệ Vehicle
            $booking = Booking::with('vehicle')->where('id', $bookingId)->lockForUpdate()->firstOrFail();

            // BẢO MẬT: Kiểm tra quyền sở hữu đối với chiếc xe được thuê trong đơn
            if ($booking->vehicle->owner_id !== $user->id) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Không có quyền thao tác.'], 403);
            }

            // Kiểm tra tính hợp lệ của trạng thái đơn hàng hiện tại
            if (!in_array($booking->status, ['pending', 'pending_approval', 'pending_payment', 'confirmed'])) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Không thể hủy chuyến đi ở trạng thái hiện tại.'], 400);
            }

            $now = Carbon::now();
            $deposit = $booking->deposit_amount;
            $total = $booking->total_amount;
            $amountPaidByCustomer = $deposit; 
            
            $startDatetime = Carbon::parse($booking->start_datetime);
            $hoursUntilStart = $now->diffInHours($startDatetime, false);

            // XỬ LÝ TÀI CHÍNH NẾU ĐƠN HÀNG ĐÃ XÁC NHẬN VÀ KHÁCH ĐÃ TRẢ TIỀN
            if ($booking->status === 'confirmed' && $amountPaidByCustomer > 0) {
                $renterWallet = $this->getOrCreateWalletLocked($booking->renter_id);
                
                // 1. Luôn hoàn lại 100% số tiền thực tế khách đã thanh toán trước đó
                $renterWallet->available_balance += $amountPaidByCustomer;
                $renterWallet->save();

                Transaction::create([
                    'wallet_id' => $renterWallet->id,
                    'booking_id' => $booking->id,
                    'amount' => $amountPaidByCustomer,
                    'type' => 'credit',
                    'balance_type' => 'available',
                    'description' => 'Hoàn 100% do Chủ xe báo sự cố hủy chuyến.'
                ]);
                
                // 2. Kiểm tra chế tài bồi thường nếu thiếu minh chứng hợp lệ (< 2 ảnh)
                if (!$isValidEvidence) {
                    // Mức phạt bồi thường cho khách: 30% tổng giá trị chuyến đi (Đối xứng công bằng 1-1 với Khách hủy trễ)
                    $penaltyAmount = round($total * 0.3, 2); 
                    
                    // Khách nhận tiền bồi thường (ứng chi trước từ Quỹ Bảo Hiểm của sàn)
                    $renterWallet->available_balance += $penaltyAmount;
                    $renterWallet->save();
                    
                    Transaction::create([
                        'wallet_id' => $renterWallet->id,
                        'booking_id' => $booking->id,
                        'amount' => $penaltyAmount,
                        'type' => 'credit',
                        'balance_type' => 'available',
                        'description' => 'Sàn bồi thường từ Quỹ Bảo Hiểm (Chủ xe tự ý hủy thiếu minh chứng).'
                    ]);
                    
                    // Chủ xe bị ghi nợ tài chính vào Ví khả dụng (trừ trực tiếp tiền ví)
                    $ownerWallet = $this->getOrCreateWalletLocked($user->id);
                    $ownerWallet->available_balance -= $penaltyAmount;
                    
                    // Nếu sau khi trừ, số dư âm -> Thực hiện KHÓA VÍ & KHÓA TOÀN BỘ XE CHỦ XE để cưỡng chế thi hành
                    if ($ownerWallet->available_balance < 0) {
                        $ownerWallet->status = 'locked';
                        $ownerWallet->locked_reason = 'Nợ tiền đền bù Quỹ Bảo Hiểm do hủy chuyến thiếu minh chứng hợp lệ.';
                        $ownerWallet->locked_at = $now;

                        // Cưỡng chế: Khóa niêm yết toàn bộ xe Sẵn sàng đón khách của Chủ xe
                        \App\Models\Vehicle::where('owner_id', $user->id)
                            ->where('status', 'available')
                            ->update(['status' => 'locked']);
                    }
                    $ownerWallet->save();
                    
                    // Lưu vết giao dịch ghi nợ trên ví chủ xe
                    Transaction::create([
                        'wallet_id' => $ownerWallet->id,
                        'booking_id' => $booking->id,
                        'amount' => $penaltyAmount,
                        'type' => 'debit',
                        'balance_type' => 'available',
                        'description' => 'Ghi nợ/Phạt tiền do hủy chuyến thiếu minh chứng hợp lệ.'
                    ]);
                }
            }

            // Cập nhật trạng thái Booking sang Đã hủy và lưu thông tin chi tiết
            $booking->status = 'cancelled';
            $booking->cancel_by = 'owner';
            $booking->cancel_reason = $request->cancel_reason;
            $booking->cancelled_at = $now;
            $booking->save();

            // Hoàn lại lượt sử dụng mã khuyến mãi cho hệ thống
            if (!empty($booking->promo_code)) {
                DB::table('vouchers')
                    ->where('code', $booking->promo_code)
                    ->where('used_count', '>', 0)
                    ->decrement('used_count');
            }

            // Đưa phương tiện về trạng thái Bảo trì (Maintenance) để đảm bảo an toàn lộ trình sau
            $booking->vehicle->status = 'maintenance';
            $booking->vehicle->save();

            // Tự động khởi tạo Yêu cầu hỗ trợ (Ticket) để Quản trị viên (Admin) xem xét sự cố
            Ticket::create([
                'ticket_category_id' => 1,
                'user_id'            => $user->id,
                'booking_id'         => $booking->id,
                'subject'            => 'Chủ xe báo sự cố - Hủy chuyến #' . $booking->id,
                'content'            => $request->cancel_reason,
                'attachments'        => json_encode($evidences),
                'status'             => 'new'
            ]);

            // Gửi thông báo đến cho Khách thuê kèm theo số tiền được hoàn trả/bồi thường
            $renter = User::find($booking->renter_id);
            if ($renter) {
                $msg = !$isValidEvidence 
                    ? "Chủ xe báo sự cố hủy chuyến #{$booking->id}. Bạn được hoàn 100% tiền và nhận bồi thường " . number_format($penaltyAmount ?? 0) . " VNĐ."
                    : "Chủ xe báo sự cố hủy chuyến #{$booking->id}. Hệ thống đã hoàn lại 100% tiền cho bạn.";
                $renter->notify(new BookingCancelledNotification($msg, $booking->id));
            }

            // Cam kết thay đổi thành công
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Hủy chuyến thành công. Xe của bạn đã được đưa về trạng thái Bảo trì. Hệ thống đã tiếp nhận sự cố.'
            ]);

        } catch (\Exception $e) {
            // Đảo ngược toàn bộ thay đổi dữ liệu nếu phát sinh ngoại lệ
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
}