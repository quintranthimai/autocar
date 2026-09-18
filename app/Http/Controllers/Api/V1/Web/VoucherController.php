<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & CÔNG CỤ TRỢ GIÚP (IMPORTS)
// ============================================================================
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * BỘ ĐIỀU KHIỂN CHIẾN KHÔNG MÃ GIẢM GIÁ (VOUCHER & CAMPAIGNS CONTROLLER)
 * Chuyên trách quản lý và tra cứu danh bạ các Chương trình Ưu đãi & Mã giảm giá
 * (Promotional Vouchers) đang còn thời hạn sử dụng trên toàn hệ thống Sàn.
 */
class VoucherController
{
    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC LỌC & DANH BẠ VOUCHER HỢP LỆ (VOUCHER DISCOVERY)
    // ============================================================================

    /**
     * Lấy danh sách các mã giảm giá (Vouchers) đang còn đầy đủ hiệu lực áp dụng
     * 
     * Tiêu chuẩn Lọc & Rà soát (Business Rules):
     * - Đối chiếu mốc thời gian thực tế (`start_date` <= NOW <= `end_date`).
     * - Rà soát giới hạn lượt dùng: Chỉ tải mã chưa đặt định mức giới hạn HOẶC lượt đã dùng (`used_count`) vẫn thấp hơn trần tối đa (`usage_limit`).
     * - Phân loại ưu tiên: Các chiến dịch sắp hết hạn sẽ được ưu tiên xếp trước để giục giã khách thao tác.
     *
     * @return \Illuminate\Http\JsonResponse Danh sách chi tiết ưu đãi khả dụng
     */
    public function getAvailable()
    {
        $now = Carbon::now();

        // Kết nối hợp nhất giữa bảng Vouchers và Bảng Khuyến mãi Discount Campaigns
        $vouchers = DB::table('vouchers')
            ->join('discount_campaigns', 'vouchers.campaign_id', '=', 'discount_campaigns.id')
            ->where('discount_campaigns.start_date', '<=', $now)
            ->where('discount_campaigns.end_date', '>=', $now)
            ->where(function ($query) {
                // Rà soát sức chứa voucher: Mã không giới hạn HOẶC lượt sử dụng còn trống
                $query->whereNull('vouchers.usage_limit')
                      ->orWhereRaw('vouchers.used_count < vouchers.usage_limit');
            })
            ->select(
                'vouchers.code',
                'discount_campaigns.title as description',
                'discount_campaigns.type',
                'discount_campaigns.value'
            )
            ->orderBy('discount_campaigns.end_date', 'asc') // Ưu tiên hiển thị mã ưu đãi sắp hết thời hạn trước
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Lấy danh sách mã giảm giá thành công.',
            'data' => $vouchers
        ]);
    }
}