<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH PHƯƠNG TIỆN YÊU THÍCH (FAVORITE VEHICLE MODEL)
 * ============================================================================
 * Biểu diễn danh sách quan tâm và ghi nhớ (Wishlist) của Người dùng Khách
 * hàng đối với các dòng xe tiềm năng trên sàn giao dịch, hỗ trợ truy xuất nhanh.
 */
class FavoriteVehicle extends Model
{
    use HasFactory;

    /**
     * Danh sách các trường cho phép gán thông tin dữ liệu theo chuỗi
     * @var array<string>
     */
    protected $fillable = [
        'user_id',     // Mã định danh Người dùng Khách hàng
        'vehicle_id',  // Mã định danh Xe ô tô được lưu vào danh sách
    ];

    /**
     * ========================================================================
     * 1. QUAN HỆ TRỰC TRÌNH TÀI KHOẢN KHÁCH (BELONGS TO USER)
     * ========================================================================
     * Bản ghi yêu thích này thuộc về quyền sở hữu cá nhân của Tài khoản nào.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ========================================================================
     * 2. QUAN HỆ ĐỐI TẤT PHƯƠNG TIỆN (BELONGS TO VEHICLE)
     * ========================================================================
     * Trích xuất thông tin kỹ thuật của chiếc ô tô đang được lưu trong Bookmark.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
