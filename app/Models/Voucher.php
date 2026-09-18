<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH VOUCHER (VOUCHER MODEL)
 * ============================================================================
 * Quản lý từng mã ưu đãi/mã giảm giá cụ thể được phát hành dưới khuôn khổ của
 * một Chiến dịch khuyến mãi (DiscountCampaign). Cung cấp khả năng kiểm đếm số
 * lượt đã áp dụng và giới hạn hạn ngạch phát hành trên Sàn.
 */
class Voucher extends Model
{
    /**
     * Danh sách các thông tin cho phép khởi tạo và cập nhật Voucher
     * @var array<string>
     */
    protected $fillable = ['campaign_id', 'code', 'usage_limit', 'used_count', 'created_by_user_id'];

    /**
     * ========================================================================
     * 1. QUAN HỆ VỚI CHIẾN DỊCH KHUYẾN MÃI (BELONGS TO DISCOUNT CAMPAIGN)
     * ========================================================================
     * Mã giảm giá này được phát sinh từ và chịu sự ràng buộc của Chiến dịch nào.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function campaign()
    {
        return $this->belongsTo(DiscountCampaign::class, 'campaign_id');
    }

    /**
     * ========================================================================
     * 2. QUAN HỆ VỚI NGƯỜI TẠO (BELONGS TO CREATOR)
     * ========================================================================
     * Nhận diện Quản trị viên (Admin) đã thực hiện thao tác khởi tạo mã này.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}