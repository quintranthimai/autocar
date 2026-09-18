<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH VÍ CHI TIẾT TRỰC TUYẾN (WALLET MODEL)
 * ============================================================================
 * Quản trị sổ quỹ tiền tệ kỹ thuật số riêng biệt của mỗi Người dùng và Đối
 * tác Chủ xe trên Hệ thống:
 * - Lưu trữ Số dư khả dụng (available balance) phục vụ rút/thuê ngay.
 * - Lưu trữ Số dư đặt cọc/ký quỹ (deposit balance) cho các hợp đồng đang thực thi.
 * - Quản lý trạng thái khóa ví trong các trường hợp nghi ngờ vi phạm gian lận.
 */
class Wallet extends Model
{
    /**
     * Danh sách trường cho phép gán thông số tài chính theo chuỗi
     * @var array<string>
     */
    protected $fillable = [
        'user_id',            // Mã định danh Người dùng sở hữu ví
        'available_balance',  // Số dư tiền mặt khả dụng (VNĐ)
        'deposit_balance',    // Số dư tiền ký quỹ đang bị giam giữ tạm thời
        'status',             // Trạng thái ví ('active' hoặc 'locked')
        'locked_reason',      // Nguyên do ban quản trị quyết định tạm giam ví
        'locked_at',          // Mốc thời gian ra lệnh phong tỏa
    ];

    /**
     * ========================================================================
     * 1. QUAN HỆ TRỰC CHI CHỦ SỞ HỮU (BELONGS TO USER)
     * ========================================================================
     * Sổ quỹ này do Tài khoản Người dùng cụ thể nào nắm quyền sở hữu.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ========================================================================
     * 2. QUAN HỆ LIÊN KẾT THÂM TRANSACTIONS (HAS MANY TRANSACTIONS)
     * ========================================================================
     * Trích xuất trọn bộ nhật ký giao dịch tài chính (Biến động cộng/trừ số dư)
     * từng diễn ra bên trong không gian ví tiền này.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}