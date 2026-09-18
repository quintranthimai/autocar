<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH THỰC THỂ ĐƠN THUÊ XE (BOOKING MODEL)
 * ============================================================================
 * Trung tâm kiểm soát toàn bộ chu trình giao dịch cho thuê phương tiện trên Sàn:
 * - Lưu ký các mốc thời gian hành trình, điểm giao/nhận xe và dòng tiền tạm thanh.
 * - Tích hợp bộ tính năng mở rộng qua Thuộc tính tính toán động (`appends`) để
 *   truyền tải trực diện phí Bảo hiểm và chi phí Giao nhận sang tầng Giao diện.
 */
class Booking extends Model
{
    /**
     * Danh sách các trường cơ cấu cho phép khởi tạo và cập nhật hàng loạt
     * @var array<string>
     */
    protected $fillable = [
        'renter_id', 'vehicle_id', 'pickup_location', 'dropoff_location',
        'start_datetime', 'end_datetime', 'total_amount', 'status',
        'is_contract_signed', 'contract_file_url', 'cancel_by', 'cancel_reason', 'payment_option',
        'promo_code', 'discount_amount', 'payment_reminder_sent_at', 'deposit_amount', 'final_settlement_method', 'payment_gateway_id', 'cancelled_at'
    ];

    /**
     * Chuyển đổi định dạng dữ liệu thuộc tính sang thể thức chuẩn lập trình
     * @var array<string, string>
     */
    protected $casts = [
        'discount_amount'          => 'float',
        'total_amount'             => 'float',
        'deposit_amount'           => 'float',
        'payment_reminder_sent_at' => 'datetime',
        'cancelled_at'             => 'datetime',
    ];

    /**
     * Danh sách thuộc tính mở rộng được đính kèm vào JSON trả về
     * @var array<string>
     */
    protected $appends = ['total_insurance_fee', 'delivery_fee'];

    /**
     * ========================================================================
     * 1. HÀM TÍNH BIỂU TIÊU PHÍ BẢO HIỂM ĐÔNG (TOTAL INSURANCE FEE ATTRIBUTE)
     * ========================================================================
     * Tự động lọc và tổng hợp tổng số tiền mà Khách đã chi trả cho gói Bảo hiểm
     * chuyến đi nằm trong bảng dịch vụ mở rộng (`booking_services`).
     *
     * @return float|int  Tổng chi phí bảo hiểm cho đơn hàng.
     */
    public function getTotalInsuranceFeeAttribute()
    {
        return $this->additionalServices()
            ->where('service_name', 'Bảo hiểm chuyến đi')
            ->sum('price');
    }

    /**
     * ========================================================================
     * 2. HÀM TÍNH BIỂU TIÊU PHÍ GIAO XE ĐÔNG (DELIVERY FEE ATTRIBUTE)
     * ========================================================================
     * Tự động cộng gộp chi phí giao nhận xe tận nơi phát sinh trong hợp đồng.
     *
     * @return float|int  Tổng phí dịch vụ giao trả xe tại chỗ.
     */
    public function getDeliveryFeeAttribute()
    {
        return $this->additionalServices()
            ->where('service_name', 'Phí giao nhận xe tận nơi')
            ->sum('price');
    }

    /**
     * ========================================================================
     * 3. QUAN HỆ KHÁCH HÀNG ĐẶT THUÊ (BELONGS TO RENTER)
     * ========================================================================
     * Chuyến đi này do người dùng Khách thuê nào đăng ký.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function renter()
    {
        return $this->belongsTo(User::class, 'renter_id');
    }

    /**
     * ========================================================================
     * 4. QUAN HỆ PHƯƠNG TIỆN VỊ HI DIỆN (BELONGS TO VEHICLE)
     * ========================================================================
     * Chuyến đi này tham chiếu đến ô tô cụ thể nào trên danh bạ phương tiện.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /**
     * ========================================================================
     * 5. QUAN HỆ DỊCH VỤ GIA TĂNG (HAS MANY ADDITIONAL SERVICES)
     * ========================================================================
     * Danh sách các gói dịch vụ mua kèm (Bảo hiểm, tài xế phụ, giao xe tận nơi...).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function additionalServices()
    {
        return $this->hasMany(BookingService::class);
    }

    /**
     * ========================================================================
     * 6. QUAN HỆ MINH CHỨNG CHI TRÌNH GIAO NHẬN (HAS MANY EVIDENCES)
     * ========================================================================
     * Tập hợp kho bằng chứng hình ảnh và video hiện trạng ghi nhận lại lúc
     * giao xe (`pickup`) và thu hồi trả xe (`dropoff`).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function evidences()
    {
        return $this->hasMany(BookingEvidence::class);
    }

    /**
     * ========================================================================
     * 7. QUAN HỆ CỔNG THANH TOÁN SỬ DỤNG (BELONGS TO PAYMENT GATEWAY)
     * ========================================================================
     * Tham chiếu định danh tới Cổng thanh toán (VNPay, Ví nội bộ) mà Khách dùng.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function paymentGateway()
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    /**
     * ========================================================================
     * 8. QUAN HỆ PHỤ PHÍ PHÁT SINH THỰC TẾ (HAS MANY SURCHARGES)
     * ========================================================================
     * Hồ sơ các chi phí phạt phát sinh được Chủ xe khai báo lúc hoàn tất chuyến
     * đi (Ví dụ: Phí Quá giờ, Quá hạn mức số kilomet, Phí Vệ sinh, Trầy xước xe).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bookingSurcharges()
    {
        return $this->hasMany(BookingSurcharge::class);
    }

    /**
     * ========================================================================
     * 9. QUAN HỆ Ý KIẾN ĐÁNH GIÁ CHUYẾN ĐI (HAS MANY REVIEWS)
     * ========================================================================
     * Các bài thảo luận và chấm điểm xếp hạng liên đới sau khi chặng đi khép lại
     * (bao gồm đánh giá từ phía Khách thuê và ý kiến nhận định từ phía Chủ xe).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}
