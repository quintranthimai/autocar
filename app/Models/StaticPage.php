<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH TRANG VĂN BẢN TĨNH (STATIC PAGE MODEL)
 * ============================================================================
 * Lưu trữ nội dung bài viết định kỳ ít chỉnh sửa phục vụ phổ biến chính sách,
 * điều khoản dịch vụ và thông tin pháp lý (Ví dụ: Trang Giới thiệu, Chính sách
 * bảo mật thông tin, Chính sách giải quyết tranh chấp tổn thất, Quy chế hoạt động).
 */
class StaticPage extends Model
{
    /**
     * Danh sách các thông tin bài viết tĩnh được biên tập
     * @var array<string>
     */
    protected $fillable = ['slug', 'title', 'content', 'updated_by_admin_id'];

    /**
     * ========================================================================
     * 1. QUAN HỆ QUẢN TRỊ VIÊN CẬP NHẬT (BELONGS TO UPDATER)
     * ========================================================================
     * Xác định Quản trị viên (Admin) nào là người khởi tạo hoặc cập nhật bài viết.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by_admin_id');
    }
}