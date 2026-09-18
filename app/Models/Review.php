<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH ĐÁNH GIÁ & PHẢN HỒI CHẤT LƯỢNG (REVIEW MODEL)
 * ============================================================================
 * Lưu trữ ý kiến bình luận, cảm nhận trải nghiệm và điểm số xếp hạng (1-5 Sao)
 * giữa các chủ thể giao dịch sau chặng hành trình (Từ Khách thuê đánh giá Xe/Chủ
 * xe, hoặc Chủ xe đánh giá uy tín thói quen giữ xe của Khách thuê).
 */
class Review extends Model
{
    /**
     * Danh sách các thông tin cho phép lưu giữ ý kiến và lời giải đáp
     * @var array<string>
     */
    protected $fillable = ['booking_id', 'reviewer_id', 'reviewee_id', 'rating', 'comment', 'reply_comment'];

    /**
     * ========================================================================
     * 1. QUAN HỆ ĐƠN HÀNG TRẢI NGHIỆM (BELONGS TO BOOKING)
     * ========================================================================
     * Quan hệ với Booking (Đánh giá này phát xuất từ sau khi hoàn tất Đơn nào).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * ========================================================================
     * 2. QUAN HỆ TÁC GIẢ BÀI BIỆN LUẬN (BELONGS TO REVIEWER)
     * ========================================================================
     * Người viết đánh giá (Thường là Khách hàng thuê xe hoặc Chủ xe đối tác).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * ========================================================================
     * 3. QUAN HỆ ĐỐI TẤT NHẬN ĐÁNH GIÁ (BELONGS TO REVIEWEE)
     * ========================================================================
     * Người được đánh giá (Chủ sở hữu cho thuê hoặc Khách sử dụng xe).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function reviewee()
    {
        return $this->belongsTo(User::class, 'reviewee_id');
    }
}