<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH VĂN BẢN PHÁP LÝ & HỒ SƠ KYC (LEGAL DOCUMENT MODEL)
 * ============================================================================
 * Lưu chi tiết chứng chỉ định danh pháp lý của cá nhân (CCCD, Giấy Phép Lái Xe)
 * hoặc giấy phép đăng ký lưu hành của phương tiện (Cà-vẹt, Đăng kiểm, Bảo hiểm).
 * Tích hợp lưu trữ kết quả phân tích tự động từ công nghệ quang học (OCR JSON).
 */
class LegalDocument extends Model
{
    /**
     * Danh sách các thông tin chứng từ cho phép khởi tạo hàng loạt
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'vehicle_id',
        'document_type',
        'document_number',
        'front_image_url',
        'back_image_url',
        'ocr_extracted_data',
        'status'
    ];

    /**
     * Ép kiểu trường dữ liệu JSON trích xuất từ OCR sang mảng lập trình PHP
     * @var array<string, string>
     */
    protected $casts = [
        'ocr_extracted_data' => 'array', // Tự động parse JSON thành Array
    ];

    /**
     * ========================================================================
     * 1. QUAN HỆ TRỰC TÍNH TÀI KHOẢN (BELONGS TO USER)
     * ========================================================================
     * Quan hệ: 1 Document thuộc về 1 User (Người dùng nộp xác minh danh tính).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * ========================================================================
     * 2. QUAN HỆ TRỰC TÍNH PHƯƠNG TIỆN (BELONGS TO VEHICLE - DỰ Đ AN HOẠT NỐI)
     * ========================================================================
     * Quan hệ: 1 Document thuộc về 1 Vehicle (Cà vẹt xe, Sổ đăng kiểm xe).
     */
    // Quan hệ: 1 Document thuộc về 1 Vehicle
    // public function vehicle()
    // {
    //     return $this->belongsTo(Vehicle::class);
    // }
}