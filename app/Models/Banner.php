<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH BẢNG HIỆU QUẢNG CÁO GIAO DIỆN (BANNER MODEL)
 * ============================================================================
 * Quản trị hình ảnh truyền thông, biểu ngữ tiếp thị và chiến dịch quảng cáo hiển
 * thị trực diện tại các vị trí nổi bật trên trang chủ ứng dụng và website.
 */
class Banner extends Model
{
    // Bảng này phục vụ hiển thị độc lập cho Frontend nên không áp dụng Eloquent Relationship
    
    /**
     * Danh sách các trường cho phép gán thông tin theo bộ
     * @var array<string>
     */
    protected $fillable = [
        'title',          // Tiêu đề chiến dịch/quảng cáo
        'image_url',      // Đường dẫn tĩnh tệp hình ảnh
        'redirect_url',   // Liên kết chuyển hướng khi người dùng clicks
        'position',       // Vị trí hiển thị (Ví dụ: top_slider, middle_section)
        'is_active',      // Cờ trạng thái hiển thị (1: Đang Bật, 0: Ẩn)
        'display_order'   // Thứ tự sắp xếp ưu tiên hiển thị
    ];
}