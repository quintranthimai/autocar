<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH CHIẾN DỊCH KHUYẾN MÃI (DISCOUNT CAMPAIGN MODEL)
 * ============================================================================
 * Quản lý các đợt phát hành mã giảm giá, chương trình tri ân hoặc chiến dịch
 * tiếp thị kích cầu thương mại theo mùa vụ (Ví dụ: Khuyến mãi hè, Tết nguyên đán).
 */
class DiscountCampaign extends Model
{
    /**
     * Danh sách thuộc tính cấu hình khung chiến dịch
     * @var array<string>
     */
    protected $fillable = ['title', 'applicable_tier', 'type', 'value', 'start_date', 'end_date'];

    /**
     * ========================================================================
     * 1. QUAN HỆ CHI PHÁT MÃ ƯU ĐÃI (HAS MANY VOUCHERS)
     * ========================================================================
     * Trích xuất toàn bộ danh bạ các Mã Giảm Giá (Voucher) được phát xuất dưới
     * sự chi phối và khung định mức của chiến dịch tiếp thị này.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function vouchers()
    {
        return $this->hasMany(Voucher::class, 'campaign_id');
    }
}