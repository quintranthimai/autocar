<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH THỰC THỂ PHƯƠNG TIỆN GIAO THÔNG (VEHICLE MODEL)
 * ============================================================================
 * Biểu diễn trọn vẹn thông số kỹ thuật, biển số, định vị địa lý GPS và chính sách
 * vận hành (Giao xe miễn phí, giảm giá tuần, giới hạn số Kilomet) của từng chiếc
 * xe ô tô được cho thuê trên toàn hệ thống sàn Sàn Thuê Xe.
 */
class Vehicle extends Model
{
    /**
     * Danh sách trường cho phép thiết lập và chỉnh sửa dữ liệu hàng loạt
     * @var array<string>
     */
    protected $fillable = [
        'owner_id', 'car_model_id', 'license_plate', 'vin_number',
        'engine_number', 'year', 'base_price', 'description',
        'parking_address', 'latitude', 'longitude', 'current_fuel_level', 'status', 'required_documents',
        
        // Cột Giao xe (Đã bổ sung free_delivery_radius_km)
        'is_mortgage_exempt','is_delivery_supported','delivery_radius_km','delivery_fee_per_km', 'free_delivery_radius_km',
        
        // Cột Giảm giá
        'is_discount_enabled', 'weekly_discount_percent',
        
        // Cột Giới hạn KM
        'is_mileage_limit_enabled', 'mileage_limit_per_day', 'extra_fee_per_km',
        
        // Điều khoản
        'rental_terms'
    ];

    /**
     * Ép kiểu dữ liệu tự động cho biến công tắc boolean, tọa độ và đơn giá
     * @var array<string, string>
     */
    protected $casts = [
        'required_documents'       => 'array',
        'is_mortgage_exempt'       => 'boolean',
        'is_delivery_supported'    => 'boolean',
        'is_discount_enabled'      => 'boolean',        // Thêm mới
        'is_mileage_limit_enabled' => 'boolean',        // Thêm mới
        'latitude'                 => 'decimal:8',      // Ép kiểu chính xác tọa độ bản đồ
        'longitude'                => 'decimal:8',
        'base_price'               => 'decimal:2',
    ];

    // ==========================================
    // CÁC HÀM RELATIONSHIP GIỮ NGUYÊN BÊN DƯỚI
    // ==========================================

    /**
     * 1. QUAN HỆ CHỦ TRỢ S TR CHI TI THỤ TR (BELONGS TO OWNER)
     * Chiếc xe này thuộc về 1 Chủ xe (User).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * 2. QUAN HỆ THAM CHIẾU MẪU XE (BELONGS TO CAR MODEL)
     * Chiếc xe này thuộc về 1 Mẫu xe cụ thể (CarModel).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function carModel()
    {
        return $this->belongsTo(CarModel::class);
    }

    /**
     * 3. QUAN HỆ LỊCH BIỂU & GIÁ TÙY NH CHÍNH (HAS MANY CALENDARS)
     * 1 Xe có nhiều lịch bận/giá tùy chỉnh theo từng ngày trong năm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function calendars()
    {
        return $this->hasMany(VehicleCalendar::class);
    }

    /**
     * 4. QUAN HỆ TIỆN ÍCH TRANG BỊ TR TI (BELONGS TO MANY AMENITIES)
     * 1 Xe có nhiều tiện ích (Quan hệ n-n qua bảng trung gian `amenity_vehicle`).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function amenities()
    {
        return $this->belongsToMany(Amenity::class, 'amenity_vehicle', 'vehicle_id', 'amenity_id');
    }

    /**
     * 5. QUAN HỆ HO KÝ CHUY CHÍNH Đ TI (HAS MANY BOOKINGS)
     * 1 chiếc xe có nhiều Chuyến đi/Đơn đặt từ Khách hàng.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * 6. QUAN HỆ Ý KIẾN PH CHUY TI (HAS MANY THROUGH REVIEWS)
     * Xuyên qua bảng Bookings để lấy tất cả Đánh giá (Reviews) của xe này.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function reviews()
    {
        return $this->hasManyThrough(Review::class, Booking::class);
    }

    /**
     * 7. QUAN HỆ BỘ SỰU TẬP TR KÝ HÌNH Đ TI (HAS MANY IMAGES)
     * 1 Xe có nhiều Ảnh (ưu tiên ảnh đại diện Thumbnail trước, theo thứ tự).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function images()
    {
        return $this->hasMany(VehicleImage::class)
                    ->orderBy('is_thumbnail', 'desc')
                    ->orderBy('display_order', 'asc');
    }

    /**
     * 8. QUAN HỆ PHỤ PHÍ TI M K K HI HÌNH (HAS MANY SURCHARGES)
     * 1 chiếc xe có nhiều cấu hình Phụ phí thiếp lập riêng (Phí vệ sinh, quá giờ).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function surcharges()
    {
        return $this->hasMany(VehicleSurcharge::class);
    }

    /**
     * 9. QUAN HỆ H KÝ S KÝ H CHÍNH KIỂM KIỆM (HAS MANY LEGAL DOCUMENTS)
     * 1 Xe có nhiều Hợp đồng/Giấy phép lưu hành (LegalDocument: Cà vẹt, Bảo hiểm).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function legalDocuments()
    {
        return $this->hasMany(LegalDocument::class, 'vehicle_id');
    }
}