<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH NHẬT KÝ GIAO DỊCH VÍ TÀI CHÍNH (TRANSACTION MODEL)
 * ============================================================================
 * Quản lý chi tiết lịch sử lưu chuyển dòng tiền (Cộng tiền, Trừ tiền, Tạm giữ,
 * Giải tỏa) bên trong Ví (Wallet) hoặc Giao dịch qua ngoại Vi (VNPay, Ngân hàng).
 * Tích hợp sự kiện tự động theo dõi sự thay đổi để phát thông báo chuông cho User.
 */
class Transaction extends Model
{
    /**
     * Danh sách các trường cho phép gán thông số giao dịch hàng loạt
     * @var array<string>
     */
    protected $fillable = [
        'wallet_id', 
        'booking_id', 
        'amount', 
        'type', 
        'balance_type', 
        'description',
        'status',
        'release_at',
        'created_by',
        'reference_type',
        'reference_id',
        'txn_ref',
        'payment_gateway_id',
        'created_at', 
        'updated_at'
    ];

    /**
     * ========================================================================
     * 1. HÀM KHỞI MẢO SỰ KIỆN MÔ HÌNH (BOOT METHOD)
     * ========================================================================
     * Đăng ký bộ lắng nghe sự kiện khi một bản ghi Transaction được tạo mới hoặc
     * thay đổi trạng thái thành công (`success`). Lập tức kích hoạt luồng thông
     * báo biến động số dư (`BalanceChangedNotification`) chuyển tới người dùng.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::saved(function ($transaction) {
            if ($transaction->status === 'success') {
                $shouldNotify = false;

                if ($transaction->wasRecentlyCreated) {
                    $shouldNotify = true;
                } elseif ($transaction->wasChanged('status')) {
                    $shouldNotify = true;
                }

                if ($shouldNotify) {
                    $wallet = $transaction->wallet;
                    if ($wallet && $wallet->user) {
                        $wallet->user->notify(new \App\Notifications\BalanceChangedNotification($transaction));
                    }
                }
            }
        });
    }

    /**
     * ========================================================================
     * 2. QUAN HỆ VÍ TÀI CHÍNH THỰC THI (BELONGS TO WALLET)
     * ========================================================================
     * Giao dịch tài chính này xảy ra trên sổ quỹ / Ví cá nhân của ai.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * ========================================================================
     * 3. QUAN HỆ CHUYẾN ĐI THAM CHIẾU (BELONGS TO BOOKING)
     * ========================================================================
     * Giao dịch này thu/chi chi phó giải ngân cho Đơn đặt xe cụ thể nào (nếu có).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}