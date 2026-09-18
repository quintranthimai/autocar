<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * LỚP TÁC VỤ TỰ ĐỘNG GIẢI PHÓNG TIỀN GIAM GIỮ (RELEASE PENDING TRANSACTIONS JOB)
 * ============================================================================
 * Tác vụ ngầm định kỳ (Queue/Cron Job) thi hành giải ngân dòng tiền thu nhập
 * hoặc tiền cọc bảo vệ (Time-lock 7 ngày) khi chuyến xe kết thúc không có khiếu
 * nại. Áp dụng chuẩn kiểm soát tài chính ACID (DB Transaction) và Khóa bi quan
 * (lockForUpdate) nhằm triệt tiêu rủi ro sai lệch tài chính trong quá trình chốt
 * thanh khoản cho Đối tác.
 */
class ReleasePendingTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ========================================================================
     * 1. HÀM THỰC THI NGHIỆP VỤ JOB (HANDLE): THẨM ĐỊNH & CÔNG CHUYỂN TRUY TỆ
     * ========================================================================
     * Quá trình giải phóng các khoản tiền đang chịu ràng buộc chờ duyệt:
     * - Trích xuất tập giao dịch mang trạng thái `pending` đã đạt hoặc vượt qua
     *   mốc định kỳ cho phép thanh toán `release_at` (nhỏ hơn hoặc bằng NOW).
     * - Thiết lập Giao dịch CSDL (Transaction) và Giam giữ Khóa (lockForUpdate)
     *   trên bản ghi Ví nội bộ (Wallet) tương ứng.
     * - Chỉ giải tỏa tiền vào số dư khả dụng (`available_balance`) khi Ví chưa
     *   bị đóng băng hoặc khóa tranh chấp (`status !== 'locked'`).
     * - Cập nhật trạng thái Giao dịch thành công (`success`) kèm chú thích.
     *
     * @return void
     */
    public function handle(): void
    {
        $now = Carbon::now();

        // Lấy tất cả các giao dịch đang bị giam (pending) và đã đến thời hạn release_at
        $pendingTransactions = Transaction::where('status', 'pending')
            ->whereNotNull('release_at')
            ->where('release_at', '<=', $now)
            ->get();

        foreach ($pendingTransactions as $transaction) {
            try {
                DB::beginTransaction();

                // Lấy ví của giao dịch và khóa bi quan để ngăn rủi ro xung đột luồng
                $wallet = Wallet::where('id', $transaction->wallet_id)->lockForUpdate()->first();

                if ($wallet && $wallet->status !== 'locked') {
                    // Cộng tiền vào số dư khả dụng khi Ví không nằm trong diện đóng băng
                    $wallet->available_balance += $transaction->amount;
                    $wallet->save();

                    // Cập nhật trạng thái giao dịch đã chính thức thanh khoản
                    $transaction->update([
                        'status'      => 'success',
                        'description' => $transaction->description . ' [Đã giải phóng]'
                    ]);
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                \Illuminate\Support\Facades\Log::error('Lỗi khi giải phóng tiền cọc cho Transaction ID: ' . $transaction->id . ' - ' . $e->getMessage());
            }
        }
    }
}
