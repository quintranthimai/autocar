<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & CÁC LỚP MÔ HÌNH (IMPORTS & MODELS)
// ============================================================================
use App\Models\FavoriteVehicle;
use App\Models\Vehicle;
use App\Models\Review;
use Illuminate\Http\Request;

/**
 * BỘ ĐIỀU KHIỂN QUẢN LÝ DANH BẠ YÊU THÍCH (FAVORITE VEHICLE CONTROLLER)
 * Phụ trách nghiệp vụ đánh dấu, lưu trữ và tra cứu bộ sưu tập các Phương tiện Yêu thích (Tim đỏ)
 * cá nhân hóa theo từng Người dùng trong hệ thống Sàn giao dịch.
 */
class FavoriteController
{
    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC TRA CỨU & QUẢN DI DIỆN YÊU THÍCH (FAVORITE OPERATIONS)
    // ============================================================================

    /**
     * Lấy danh sách trọn bộ Phương tiện yêu thích của người dùng đang thực hiện xác thực
     * 
     * - Tích hợp truy vấn Eloquent kèm JOIN bảng favorite_vehicles.
     * - Tính toán điểm đánh giá trung bình trực tiếp bằng Sub-Query SQL rỗng tải nhanh (ROUND(AVG(rating), 1)).
     * - Tải trước (Eager Loading) thông tin hình ảnh, Chủ xe, thông số Mẫu xe (Hệ truyền động, Nhiên liệu, Tiện nghi).
     * - Thống kê số lượng chuyến đi đã hoàn thành thành công của từng xe (total_trips).
     *
     * @param Request $request Yêu cầu HTTP chứa thông tin định danh User Sanctum
     * @return \Illuminate\Http\JsonResponse Danh sách phương tiện yêu thích kèm thông số chi tiết
     */
    public function index(Request $request)
    {
        // Trích xuất ID từ thông tin Khách hàng đã chứng thực trong phiên thao tác
        $userId = $request->user()->id;

        // Khởi tạo chuỗi truy vấn phức hợp lấy dữ liệu Xe yêu thích
        $vehicles = Vehicle::query()
            ->select('vehicles.*')
            ->join('favorite_vehicles', 'vehicles.id', '=', 'favorite_vehicles.vehicle_id')
            ->where('favorite_vehicles.user_id', $userId)
            // Truyền bổ sung trường Đánh giá sao trung bình từ bảng Review
            ->addSelect([
                'owner_avg_rating' => Review::selectRaw('ROUND(AVG(rating), 1)')
                    ->whereColumn('reviewee_id', 'vehicles.owner_id')
            ])
            // Nạp trước các quan hệ liên đới (Eager Loading) nhằm ngăn chặn Lỗi N+1 Queries
            ->with([
                'images',
                'owner',
                'carModel.category',
                'carModel.transmission',
                'carModel.fuel',
                'amenities'
            ])
            // Đếm tổng chuỗi chặng đi đã thực thi hoàn thiện của Xe
            ->withCount(['bookings as total_trips' => function ($q) {
                $q->where('status', 'completed');
            }])
            ->orderBy('favorite_vehicles.created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Lấy danh sách xe yêu thích thành công.',
            'data' => $vehicles
        ]);
    }

    /**
     * Thêm mới hoặc Xóa bỏ Xe ra khỏi bộ sưu tập Yêu thích (Cơ chế Toggle đa phương)
     * 
     * - Kiểm tra sự hiện diện của xe trên thị trường.
     * - Nếu xe đã tồn tại trong Sổ yêu thích -> Tiến hành Gỡ bỏ (Un-favorite).
     * - Nếu xe chưa nằm trong Danh mục -> Ghi mới vào bảng (Favorite).
     *
     * @param Request $request Yêu cầu HTTP chứa định danh User
     * @param int|string $vehicleId Mã số nhận dạng (ID) của Phương tiện cần chuyển đổi trạng thái
     * @return \Illuminate\Http\JsonResponse Trạng thái mới của Phương tiện (is_favorited: true/false)
     */
    public function toggle(Request $request, $vehicleId)
    {
        $userId = $request->user()->id;

        // Thẩm định sự tồn tại hợp lệ của Xe trong CSDL
        $vehicle = Vehicle::find($vehicleId);
        if (!$vehicle) {
            return response()->json([
                'success' => false,
                'message' => 'Phương tiện không tồn tại.'
            ], 404);
        }

        // Truy lục bản ghi ghép đôi giữa Người dùng và Phương tiện
        $favorite = FavoriteVehicle::where('user_id', $userId)
            ->where('vehicle_id', $vehicleId)
            ->first();

        // Thực thi phân rã logic công tắc (Toggle Logic)
        if ($favorite) {
            $favorite->delete();
            return response()->json([
                'success' => true,
                'is_favorited' => false,
                'message' => 'Đã xóa xe khỏi danh sách yêu thích.'
            ]);
        } else {
            FavoriteVehicle::create([
                'user_id' => $userId,
                'vehicle_id' => $vehicleId
            ]);

            return response()->json([
                'success' => true,
                'is_favorited' => true,
                'message' => 'Đã thêm xe vào danh sách yêu thích.'
            ]);
        }
    }

    /**
     * Lấy mảng thuần các Mã ID xe đã được thêm vào mục Yêu thích
     * 
     * - Tối ưu hóa chi phí đường truyền phục vụ việc render nhanh icon "Tim đỏ" trên Giao diện Frontend.
     *
     * @param Request $request Yêu cầu HTTP mang theo hồ sơ xác minh
     * @return \Illuminate\Http\JsonResponse Mảng chứa danh sách ID phương tiện yêu thích
     */
    public function ids(Request $request)
    {
        $userId = $request->user()->id;
        
        // Trích xuất tức thì trường vehicle_id thành Mảng một chiều
        $ids = FavoriteVehicle::where('user_id', $userId)->pluck('vehicle_id')->toArray();

        return response()->json([
            'success' => true,
            'data' => $ids
        ]);
    }
}
