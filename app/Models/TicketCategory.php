<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH DANH MỤC KHIẾU NẠI HỖ TRỢ (TICKET CATEGORY MODEL)
 * ============================================================================
 * Phân lớp giải quyết các tranh chấp và khiếu nại của người dùng trên nền tảng
 * (Ví dụ: Sự cố Thanh toán/Hoàn tiền, Sự cố Trì hoãn giao xe, Vi phạm hợp đồng).
 */
class TicketCategory extends Model
{
    /**
     * Các trường định nghĩa nhóm vấn đề khiếu nại
     * @var array<string>
     */
    protected $fillable = ['name', 'display_name'];

    /**
     * ========================================================================
     * 1. QUAN HỆ CHI TRÌNH DANH SÁCH PHIẾU (HAS MANY TICKETS)
     * ========================================================================
     * Trích xuất trọn bộ các phiếu yêu cầu hỗ trợ (Ticket) được phát tạo dưới
     * hạng mục nguyên do khiếu nại này.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }
}