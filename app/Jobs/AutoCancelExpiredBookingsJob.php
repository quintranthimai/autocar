<?php

namespace App\Jobs;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ============================================================================
 * LỚP TÁC VỤ HUYỀN CHẾ HỦY ĐƠN QUÁ HẠN TỰ ĐỘNG (AUTO CANCEL EXPIRED BOOKINGS JOB)
 * ============================================================================
 * Tác vụ chạy ngầm định kỳ (Queue/Cron Job) bảo vệ hệ sinh thái Sàn khỏi tình
 * trạng ngâm đơn hoặc giam giữ lịch bận phương tiện trái phép:
 * - Tự động thu hồi các đơn chờ duyệt (`pending_approval`) quá 2 giờ.
 * - Tự động thu hồi đơn đã duyệt nhưng chậm thanh toán (`pending_payment`) quá
 *   2 giờ, đặc biệt tích hợp đối soát tự động qua cổng QueryDR của VNPay trước
 *   khi hủy nhằm triệt tiêu rủi ro rớt gói tin webhook IPN.
 */
class AutoCancelExpiredBookingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ========================================================================
     * 1. HÀM THỰC THI NGHIỆP VỤ JOB (HANDLE): QUÉT & THANH SỨC ĐƠN QUÁ HẠN
     * ========================================================================
     * Duyệt qua danh bạ dữ liệu Đơn đặt xe hiện hành và xử lý theo 2 trục logic:
     * - Quy tắc 1: Hủy lập tức nếu sau 2 giờ kể từ lúc tạo (`created_at`) mà Chủ
     *   xe phó mặc không xác nhận chi trả, đồng thời trả lại chỉ tiêu lượt dùng
     *   cho mã ưu đãi Voucher (nếu có applied).
     * - Quy tắc 2: Đối với đơn chờ thanh toán quá 2 giờ kể từ thời điểm Chủ xe
     *   duyệt (`updated_at`), hệ thống chủ động gọi truy vấn giao dịch VNPay
     *   (`vnpayQueryTransaction`). Nếu khách đã thanh toán thực tế, tự động chuyển
     *   trạng thái sang thành công (`markBookingPaid`), ngược lại tiến hành hủy bỏ.
     *
     * @return void
     */
    public function handle()
    {
        $now = Carbon::now();
        $cancelledCount = 0;

        // ==============================================================
        // QUY TẮC 1: KHÁCH ĐẶT NHƯNG CHỦ XE KHÔNG DUYỆT TRONG 2 GIỜ HOẶC QUÁ HẠN GIỜ KHỞI HÀNH
        // ==============================================================
        $expiredApprovals = Booking::where('status', 'pending_approval')
            ->where(function ($query) use ($now) {
                $query->where('created_at', '<=', $now->copy()->subHours(2))
                      ->orWhere('start_datetime', '<=', $now);
            })
            ->get();

        foreach ($expiredApprovals as $booking) {
            $booking->update([
                'status'        => 'cancelled',
                'cancel_by'     => 'system',
                'cancel_reason' => 'Chủ xe không duyệt yêu cầu trước giờ xuất phát hoặc trong thời gian quy định (2 giờ).',
                'cancelled_at'  => $now
            ]);
            
            // Khôi phục chỉ số lượt sử dụng của Mã ưu đãi (Voucher) cho Khách hàng
            if (!empty($booking->promo_code)) {
                \Illuminate\Support\Facades\DB::table('vouchers')
                    ->where('code', $booking->promo_code)
                    ->where('used_count', '>', 0)
                    ->decrement('used_count');
            }
            $cancelledCount++;
        }

        // ==============================================================
        // QUY TẮC 2: CHỦ XE ĐÃ DUYỆT NHƯNG KHÁCH KHÔNG THANH TOÁN TRONG 2 GIỜ
        // Mốc thời gian tính từ lúc Chủ xe bấm duyệt (Cột updated_at sẽ lưu thời điểm này)
        // ==============================================================
        $expiredPayments = Booking::where('status', 'pending_payment')
            ->where('updated_at', '<=', $now->copy()->subHours(2))
            ->get();

        foreach ($expiredPayments as $booking) {
            // TRƯỚC KHI HỦY: Gọi sang VNPay xem khách đã thanh toán chưa (QueryDR)
            $paymentController = new \App\Http\Controllers\Api\V1\Web\PaymentController();
            $isPaid = $paymentController->vnpayQueryTransaction($booking);

            if ($isPaid) {
                // Khách ĐÃ THANH TOÁN nhưng IPN bị rớt -> Không hủy, Cập nhật thành công!
                $paymentController->markBookingPaid($booking, 'Cập nhật qua QueryDR tự động đối soát');
            } else {
                // Thực sự CHƯA THANH TOÁN -> Hủy
                $booking->update([
                    'status'        => 'cancelled',
                    'cancel_by'     => 'system',
                    'cancel_reason' => 'Khách thuê không hoàn tất thanh toán trong vòng 2 giờ sau khi được duyệt.',
                    'cancelled_at'  => $now
                ]);
                
                // Hoàn lại lượt dùng mã giảm giá cho Đơn chờ thanh toán bị hủy
                if (!empty($booking->promo_code)) {
                    \Illuminate\Support\Facades\DB::table('vouchers')
                        ->where('code', $booking->promo_code)
                        ->where('used_count', '>', 0)
                        ->decrement('used_count');
                }
                $cancelledCount++;
            }
        }

        return $cancelledCount;
    }
}