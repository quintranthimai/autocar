<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & CÁC LỚP THỰC THI (IMPORTS)
// ============================================================================
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * BỘ ĐIỀU KHIỂN QUẢN TRỊ THÔNG BÁO NGƯỜI DÙNG (NOTIFICATION CONTROLLER)
 * Phụ trách cung cấp danh sách bản tin cảnh báo, đếm huy hiệu thông báo chưa đọc (Unread Badge)
 * và thao tác cập nhật trạng thái đọc của người dùng trong ứng dụng.
 */
class NotificationController extends Controller
{
    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC TRUY VẤN & TRẠNG THÁI THÔNG BÁO (NOTIFICATION FETCH & STATS)
    // ============================================================================

    /**
     * Lấy danh sách thông báo của người dùng hiện tại có áp dụng giới hạn bản ghi (Default limit = 20)
     * 
     * @param Request $request Yêu cầu HTTP chứa tham số `limit`
     * @return \Illuminate\Http\JsonResponse Danh sách thông báo
     */
    public function index(Request $request)
    {
        // Kiểm tra quyền chứng thực
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Giám sát trần tải dữ liệu để bảo vệ bộ nhớ Server
        $limit = $request->input('limit', 20);
        $notifications = $user->notifications()->take($limit)->get();

        return response()->json([
            'success' => true,
            'data' => $notifications
        ]);
    }

    /**
     * Lấy số lượng thông báo chưa đọc (Phục vụ huy hiệu số màu đỏ trên Quả chuông)
     *
     * @param Request $request Yêu cầu HTTP
     * @return \Illuminate\Http\JsonResponse Kết quả đếm (count)
     */
    public function unreadCount(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        return response()->json([
            'success' => true,
            'count' => $user->unreadNotifications()->count()
        ]);
    }

    // ============================================================================
    // 3. NHÓM PHƯƠNG THỨC CẬP NHẬT TRẠNG THÁI (STATUS MUTATORS)
    // ============================================================================

    /**
     * Đánh dấu 1 thông báo là đã đọc theo Mã ID
     * 
     * @param Request $request Yêu cầu HTTP
     * @param int|string $id Định danh thông báo
     * @return \Illuminate\Http\JsonResponse Trạng thái thực thi
     */
    public function markAsRead(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Đối chiếu quyền sở hữu thông báo trước khi ghi nhận trạng thái
        $notification = $user->notifications()->where('id', $id)->first();
        if ($notification) {
            $notification->markAsRead();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Đánh dấu tất cả thông báo của tài khoản là đã đọc (Mark All As Read)
     *
     * @param Request $request Yêu cầu HTTP
     * @return \Illuminate\Http\JsonResponse Trạng thái thực thi
     */
    public function markAllAsRead(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // Cập nhật đồng loạt cột read_at trong cơ sở dữ liệu
        $user->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }
}
