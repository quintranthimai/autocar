<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // Nếu bạn dùng Sanctum cho API

/**
 * ============================================================================
 * LỚP MÔ HÌNH THỰC THỂ NGUY Đ KHOẢN (USER MODEL)
 * ============================================================================
 * Trung tâm xác thực và định danh chủ thể người dùng trong toàn bộ Hệ sinh thái:
 * - Quản trị thông tin hồ sơ, điểm uy tín (credit score) và xếp hạng hội viên.
 * - Liên kết mọi thực thể (Xe cho thuê, Đơn đặt, Ví tiền, Ý kiến đánh giá, Hỗ trợ).
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Danh sách các thông tin hồ sơ cho phép cập nhật hàng loạt
     * @var array<string>
     */
    protected $fillable = [
        'name', 'email', 'password', 'phone', 'address', 'avatar',
        'kyc_status', 'credit_score', 'is_blacklisted', 'avg_rental_spend', 
        'avg_partner_revenue', 'membership_tier', 'membership_tier_name', 'response_rate',
        'response_time_minutes',
    ];

    /**
     * Các dữ liệu bảo mật phải bị ẩn đi khi chuyển hóa thành JSON
     * @var array<string>
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * Danh sách thuộc tính mở rộng động được tính toán và đính kèm
     * @var array<string>
     */
    protected $appends = [
        'avg_rating', 'total_reviews'
    ];

    // ========================================================================
    // CÁC MỐI QUAN HỆ TRỰC H THỤ L HO (RELATIONSHIPS)
    // ========================================================================

    /**
     * 1. QUAN HỆ DANH TRÌNH VAI TRÒ (BELONGS TO MANY ROLES)
     * 1 User có nhiều Roles (Bảng trung gian role_user).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id');
    }

    /**
     * 2. QUAN HỆ VÍ TIỀN ĐỊNH DANH (HAS ONE WALLET)
     * 1 User sở hữu đúng 1 Wallet cá nhân phục vụ chi phó và ký quỹ.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * 3. QUAN HỆ KHO PHƯƠNG TIỆN ĐỐI TẤT (HAS MANY VEHICLES)
     * 1 User (Partner/Chủ xe) có thể sở hữu và đăng tải nhiều Vehicles.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'owner_id');
    }

    /**
     * 4. QUAN HỆ LỊCH SỬ CHUYẾN ĐI (HAS MANY BOOKINGS)
     * 1 User (Renter/Khách hàng) có thể tham dự và tạo lập nhiều Bookings.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bookings()
    {
        return $this->hasMany(Booking::class, 'renter_id');
    }

    /**
     * 5. QUAN HỆ PHIẾU HỖ TRỢ XỬ LÝ (HAS MANY ASSIGNED TICKETS)
     * 1 User (Support Staff/Admin) chịu trách nhiệm xử lý nhiều Tickets.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function assignedTickets()
    {
        return $this->hasMany(Ticket::class, 'assigned_staff_id');
    }

    /**
     * 6. QUAN HỆ HỒ SƠ CHUY PHÉP KYC (HAS MANY LEGAL DOCUMENTS)
     * 1 User có thể gửi nhiều hồ sơ LegalDocuments (CCCD, GPLX).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function legalDocuments()
    {
        return $this->hasMany(LegalDocument::class);
    }

    /**
     * 7. QUAN HỆ Ý KIẾN ĐÁNH GIÁ THỤ HƯỞNG (HAS MANY REVIEWS RECEIVED)
     * Các đánh giá mà người dùng này NHẬN ĐƯỢC từ các đối tác.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reviewsReceived()
    {
        return $this->hasMany(Review::class, 'reviewee_id');
    }

    /**
     * 8. QUAN HỆ Ý KIẾN ĐÁNH GIÁ PHÁT KIẾN (HAS MANY REVIEWS GIVEN)
     * Các đánh giá mà người dùng này ĐÃ GỬI tặng người khác.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reviewsGiven()
    {
        return $this->hasMany(Review::class, 'reviewer_id');
    }

    /**
     * ========================================================================
     * 9. HÀM TÍNH BIỂU TIÊU Đ TIỂU Đ TI NH GI TR TI (GET AVG RATING ATTRIBUTE)
     * ========================================================================
     * Điểm đánh giá trung bình từ toàn bộ các phản hồi mà người dùng nhận được.
     *
     * @return float|int  Điểm sao trung bình (Mặc định 0 nếu chưa có đánh giá).
     */
    public function getAvgRatingAttribute()
    {
        return $this->reviewsReceived()->avg('rating') ?: 0;
    }

    /**
     * ========================================================================
     * 10. HÀM TÍNH BIỂU TIÊU T T NH Đ TI NH GI (GET TOTAL REVIEWS ATTRIBUTE)
     * ========================================================================
     * Tổng số lượt đánh giá đã nhận được trong chu kỳ hoạt động.
     *
     * @return int  Số lượng ý kiến bình luận thụ hưởng.
     */
    public function getTotalReviewsAttribute()
    {
        return $this->reviewsReceived()->count();
    }
}