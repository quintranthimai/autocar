<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Voucher;
use App\Models\DiscountCampaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * ============================================================================
 * BỘ ĐIỀU KHIỂN QUẢN LÝ MÃ GIẢM GIÁ & KHUYẾN MÃI (ADMIN VOUCHER CONTROLLER)
 * ============================================================================
 * Chuyên trách thực hiện các thao tác CRUD (Thêm, Xem, Sửa, Xóa/Khóa) trên
 * danh bạ Mã giảm giá (Vouchers) và Chiến dịch khuyến mãi (Discount Campaigns).
 */
class VoucherController
{
    /**
     * Lấy danh sách tất cả mã giảm giá kèm thông tin chiến dịch
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Voucher::with(['campaign', 'creator:id,name,email'])->orderByDesc('created_at');

        // Lọc theo từ khóa tìm kiếm (Mã code hoặc tên chiến dịch)
        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ILIKE', "%{$search}%")
                  ->orWhereHas('campaign', function ($c) use ($search) {
                      $c->where('title', 'ILIKE', "%{$search}%");
                  });
            });
        }

        // Lọc theo trạng thái thời gian & lượt dùng (active, upcoming, expired)
        if ($request->filled('status') && $request->status !== 'all') {
            $now = Carbon::now();
            if ($request->status === 'active') {
                $query->whereHas('campaign', function ($c) use ($now) {
                    $c->where('start_date', '<=', $now)->where('end_date', '>=', $now);
                })->where(function ($q) {
                    $q->whereNull('usage_limit')->orWhereRaw('used_count < usage_limit');
                });
            } elseif ($request->status === 'upcoming') {
                $query->whereHas('campaign', function ($c) use ($now) {
                    $c->where('start_date', '>', $now);
                });
            } elseif ($request->status === 'expired') {
                $query->where(function ($q) use ($now) {
                    $q->whereHas('campaign', function ($c) use ($now) {
                        $c->where('end_date', '<', $now);
                    })->orWhereRaw('usage_limit IS NOT NULL AND used_count >= usage_limit');
                });
            }
        }

        $vouchers = $query->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Lấy danh sách mã giảm giá thành công.',
            'data'    => $vouchers
        ]);
    }

    /**
     * Xem chi tiết một mã giảm giá cụ thể
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $voucher = Voucher::with(['campaign', 'creator:id,name,email'])->find($id);

        if (!$voucher) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy mã giảm giá trong hệ thống!'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $voucher
        ]);
    }

    /**
     * Khởi tạo mới Chiến dịch & Mã giảm giá (Create / Store)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'code'        => 'required|string|max:50|unique:vouchers,code',
            'type'        => 'required|in:percentage,fixed_amount',
            'value'       => 'required|numeric|min:1',
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'usage_limit' => 'nullable|integer|min:1',
        ], [
            'title.required'          => 'Vui lòng nhập tên chiến dịch khuyến mãi.',
            'code.required'           => 'Vui lòng nhập mã Coupon.',
            'code.unique'             => 'Mã giảm giá này đã tồn tại trong hệ thống. Vui lòng chọn mã khác!',
            'type.in'                 => 'Loại giảm giá không hợp lệ (chỉ chấp nhận percentage hoặc fixed_amount).',
            'value.required'          => 'Vui lòng nhập giá trị giảm giá.',
            'end_date.after_or_equal' => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu đầu vào không hợp lệ.',
                'errors'  => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // 1. Tạo Chiến dịch khuyến mãi
            $campaign = DiscountCampaign::create([
                'title'           => trim($request->title),
                'applicable_tier' => 'all',
                'type'            => $request->type,
                'value'           => $request->value,
                'start_date'      => Carbon::parse($request->start_date)->startOfDay(),
                'end_date'        => Carbon::parse($request->end_date)->endOfDay(),
            ]);

            // 2. Tạo Mã Voucher
            $voucher = Voucher::create([
                'campaign_id'        => $campaign->id,
                'code'               => strtoupper(trim($request->code)),
                'usage_limit'        => $request->usage_limit ? (int) $request->usage_limit : null,
                'used_count'         => 0,
                'created_by_user_id' => $request->user() ? $request->user()->id : null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Phát hành mã giảm giá thành công!',
                'data'    => $voucher->load('campaign')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống khi tạo mã giảm giá: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cập nhật thông tin mã giảm giá & chiến dịch (Update)
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $voucher = Voucher::with('campaign')->find($id);

        if (!$voucher) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy mã giảm giá!'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'code'        => 'required|string|max:50|unique:vouchers,code,' . $voucher->id,
            'type'        => 'required|in:percentage,fixed_amount',
            'value'       => 'required|numeric|min:1',
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'usage_limit' => 'nullable|integer|min:' . $voucher->used_count, // Không được đặt giới hạn thấp hơn số lượt đã sử dụng
        ], [
            'code.unique'              => 'Mã Coupon bị trùng lặp với một mã khác.',
            'usage_limit.min'          => "Giới hạn sử dụng không thể nhỏ hơn số lượt đã được dùng ({$voucher->used_count} lượt).",
            'end_date.after_or_equal'  => 'Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu cập nhật không hợp lệ.',
                'errors'  => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // 1. Cập nhật Chiến dịch
            if ($voucher->campaign) {
                $voucher->campaign->update([
                    'title'      => trim($request->title),
                    'type'       => $request->type,
                    'value'      => $request->value,
                    'start_date' => Carbon::parse($request->start_date)->startOfDay(),
                    'end_date'   => Carbon::parse($request->end_date)->endOfDay(),
                ]);
            }

            // 2. Cập nhật Voucher
            $voucher->update([
                'code'        => strtoupper(trim($request->code)),
                'usage_limit' => $request->usage_limit ? (int) $request->usage_limit : null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật mã giảm giá thành công!',
                'data'    => $voucher->load('campaign')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Lỗi cập nhật: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Xóa hoặc Khóa/Hủy một mã giảm giá (Delete / Deactivate)
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $voucher = Voucher::with('campaign')->find($id);

        if (!$voucher) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy mã giảm giá!'
            ], 404);
        }

        DB::beginTransaction();
        try {
            // Nếu mã đã từng có người sử dụng (used_count > 0), không được xóa trắng khỏi DB để bảo toàn lịch sử đơn thuê
            // Thay vào đó: Khóa mã bằng cách chỉnh end_date về trong quá khứ hoặc khóa usage_limit = used_count
            if ($voucher->used_count > 0) {
                if ($voucher->campaign) {
                    $voucher->campaign->update([
                        'end_date' => Carbon::now()->subSecond() // Hết hạn ngay lập tức
                    ]);
                }
                $voucher->update([
                    'usage_limit' => $voucher->used_count // Chốt giới hạn tại số đã dùng
                ]);
                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => "Mã [{$voucher->code}] đã có {$voucher->used_count} lượt dùng nên không xóa vĩnh viễn, hệ thống đã đóng phai hoàn toàn hiệu lực mã này!"
                ]);
            }

            // Nếu chưa ai sử dụng: Xóa vĩnh viễn khỏi hệ thống
            $campaignId = $voucher->campaign_id;
            $voucher->delete();

            // Kiểm tra xem chiến dịch có còn mã nào khác không, nếu trống thì xóa luôn chiến dịch
            if ($campaignId && Voucher::where('campaign_id', $campaignId)->count() === 0) {
                DiscountCampaign::where('id', $campaignId)->delete();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Đã xóa vĩnh viễn mã giảm giá [{$voucher->code}] thành công!"
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống khi xóa mã: ' . $e->getMessage()
            ], 500);
        }
    }
}
