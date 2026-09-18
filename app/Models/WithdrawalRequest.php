<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH YÊU CẦU R T TH CHI TR (WITHDRAWAL REQUEST MODEL)
 * ============================================================================
 * Quản lý các lệnh yêu cầu tất toán và rút thu nhập hoặc tiền dư trong Ví
 * nội bộ chuyển khoản thẳng ra Ngân hàng cá nhân của Khách hàng hoặc Chủ xe,
 * hỗ trợ quy trình thẩm định phê duyệt của Ban Tài chính Admin.
 */
class WithdrawalRequest extends Model
{
    /**
     * Các trường cho phép cập nhật thông số và tài khoản ngân hàng thụ hưởng
     * @var array<string>
     */
    protected $fillable = [
        'user_id',            // Mã Người dùng phát lệnh rút
        'wallet_id',          // Mã Ví nguồn bị tạm trừ tiền
        'amount',             // Số tiền giải ngân (VNĐ)
        'bank_name',          // Tên ngân hàng thụ hưởng (Vietcombank, MB...)
        'bank_account',       // Số tài khoản ngân hàng
        'bank_account_name',  // Tên chủ sở hữu hợp lệ (In hoa không dấu)
        'status',             // Trạng thái ('pending', 'approved', 'rejected')
        'rejection_reason',   // Lý do bác bỏ nếu lệnh không đạt điều kiện
        'processed_by',       // Mã Quản trị viên (Admin) đã duyệt/nhận xét
        'processed_at',       // Mốc thời gian hoàn tất chuyển khoản
    ];

    /**
     * Quy đổi số tiền sang định dạng Số thực và thời gian sang Đối tượng ngày
     * @var array<string, string>
     */
    protected $casts = [
        'amount'       => 'float',
        'processed_at' => 'datetime',
    ];

    /**
     * ========================================================================
     * 1. QUAN HỆ TRỰC CHI CH K S TH (BELONGS TO USER)
     * ========================================================================
     * Lệnh rút tiền này phát sinh theo chỉ thị của Tài khoản người dùng nào.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ========================================================================
     * 2. QUAN HỆ TRỰC TR CHI V B TH N (BELONGS TO WALLET)
     * ========================================================================
     * Tham chiếu Sổ quỹ Ví cá nhân nơi số tiền rút đang được cất giữ hoặc giam giữ.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * ========================================================================
     * 3. QUAN HỆ ADMIN Đ TH M PH PH TR (BELONGS TO PROCESSOR)
     * ========================================================================
     * Nhận diện Quản trị viên Ban tài chính thực hiện quyết định Duyệt/Từ chối.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
