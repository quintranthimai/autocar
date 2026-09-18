<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wallet;
use Illuminate\Support\Facades\Auth;

/**
 * ============================================================================
 * LỚP WALLET MANAGEMENT CONTROLLER (QUẢN CHỊU & KIỂM DIỆN KHÓA TÀI KHOẢN VÍ)
 * ============================================================================
 * Controller phân hệ Quản trị (CMS Admin) chuyên bám sát nghiệp vụ quản lý trang
 * thái hoạt động Ví Tiền Người dùng: Áp dụng cơ chế Đóng băng (Freeze) hoặc Giải
 * tỏa Mở khóa (Unfreeze) tức thời phục vụ công tác thanh tra nghi vấn tài chính
 * hoặc thi hành giải quyết tranh chấp pháp lý trên sàn.
 */
class WalletManagementController extends Controller
{
    /**
     * ========================================================================
     * 1. HÀM ĐÓNG BĂNG VÍ (FREEZE): KHÓA TẠM DỪNG GIAO DỊCH TÀI SẢN VÍ
     * ========================================================================
     * Chuyển trạng thái Ví sang `locked`, ghi nhận chi tiết lý do khóa và thời điểm
     * phong tỏa. Mọi hành vi rút tiền, tiêu xài hoặc luân chuyển thuộc ví sẽ bị
     * gián đoạn triệt để nhằm ngăn chặn rủi ro thất thoát.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số chứa ID ví và lời giải thích (reason).
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON xác nhận khóa ví thành công.
     */
    public function freeze(Request $request)
    {
        $request->validate([
            'wallet_id' => 'required|exists:wallets,id',
            'reason'    => 'required|string',
        ]);

        $wallet = Wallet::findOrFail($request->wallet_id);
        
        // Cập nhật tình trạng phong tỏa tài chính và lưu trữ biên lai nguyên nhân
        $wallet->status = 'locked';
        $wallet->locked_reason = $request->reason;
        $wallet->locked_at = now();
        $wallet->save();

        return response()->json([
            'success' => true,
            'message' => 'Đã khóa ví thành công!',
            'data'    => $wallet
        ]);
    }

    /**
     * ========================================================================
     * 2. HÀM MỞ KHÓA VÍ (UNFREEZE): PHỤC HỒI TRẠNG THÁI HOẠT ĐỘNG
     * ========================================================================
     * Giải tỏa hạn chế tài chính, phục hồi trạng thái Ví về `active` và xóa bỏ
     * mọi lịch sử khóa tạm thời trước đó, cho phép tài khoản tiếp tục thực thi
     * giao dịch và trao đổi tiền tệ bình thường trên Nền tảng.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số chứa ID ví cần mở khóa.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON thông báo tháo gỡ hoàn tất.
     */
    public function unfreeze(Request $request)
    {
        $request->validate([
            'wallet_id' => 'required|exists:wallets,id',
        ]);

        $wallet = Wallet::findOrFail($request->wallet_id);
        
        // Tẩy xóa hồ sơ tạm giữ và phục hồi năng lực giao dịch của ví
        $wallet->status = 'active';
        $wallet->locked_reason = null;
        $wallet->locked_at = null;
        $wallet->save();

        return response()->json([
            'success' => true,
            'message' => 'Đã mở khóa ví thành công!',
            'data'    => $wallet
        ]);
    }
}
