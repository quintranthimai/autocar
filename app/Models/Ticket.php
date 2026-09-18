<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH YÊU CẦU HỖ TRỢ KỸ THUẬT & KHIẾU NẠI (SUPPORT TICKET MODEL)
 * ============================================================================
 * Quản lý phiếu hỗ trợ kỹ thuật, yêu cầu tư vấn hoặc đơn khiếu nại tranh chấp
 * tài chính phát sinh từ phía Khách hàng hoặc Chủ xe, phục vụ ban Kiểm soát xử lý.
 */
class Ticket extends Model
{
    /**
     * Danh sách các trường cho phép khai báo thông số phiếu hỗ trợ
     * @var array<string>
     */
    protected $fillable = [
        'ticket_category_id', 'user_id', 'booking_id', 'assigned_staff_id',
        'subject', 'content', 'attachments', 'status', 'priority', 'rating'
    ];

    /**
     * Quy đổi tệp tin đính kèm từ dạng chuỗi JSON sang Mảng dữ liệu PHP
     * @var array<string, string>
     */
    protected $casts = [
        'attachments' => 'array',
    ];

    /**
     * ========================================================================
     * 1. QUAN HỆ TRỰC TRÌNH NHÓM VẤN ĐỀ (BELONGS TO TICKET CATEGORY)
     * ========================================================================
     * Phiếu khiếu nại này thuộc danh mục nghiệp vụ nào (Lỗi thanh toán, xe hỏng...).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(TicketCategory::class, 'ticket_category_id');
    }

    /**
     * ========================================================================
     * 2. QUAN HỆ TÁC GIẢ KHỞI TAO KHIẾU NẠI (BELONGS TO USER)
     * ========================================================================
     * Tài khoản người dùng (Khách/Chủ xe) tạo phiếu yêu cầu can thiệp hỗ trợ.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ========================================================================
     * 3. QUAN HỆ ĐƠN ĐẶT XE THAM CHIẾU (BELONGS TO BOOKING)
     * ========================================================================
     * Khiếu nại nảy sinh trong khuôn khổ chặng hành trình/đơn thuê xe cụ thể nào.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * ========================================================================
     * 4. QUAN HỆ ĐẶC VỤ XỬ LÝ PHIẾU (BELONGS TO ASSIGNED STAFF)
     * ========================================================================
     * Tham chiếu tới Quản trị viên/Nhân viên Hỗ trợ (Support Staff) được chỉ định.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function assignedStaff()
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }
}