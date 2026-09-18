<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & CÁC LỚP MÔ HÌNH (IMPORTS & MODELS)
// ============================================================================
use Illuminate\Http\Request;
use App\Models\Vehicle;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

/**
 * BỘ ĐIỀU KHIỂN TÌM KIẾM & HỆ THỐNG LỌC PHƯƠNG TIỆN (SEARCH & FILTER CONTROLLER)
 * Chuyên trách điều phối động cơ Tìm kiếm đa chiều phục vụ Người dùng công cộng (Public):
 * Lọc theo tình trạng, khung giờ đặt, khoảng cách GPS địa lý (Haversine), thông số kỹ thuật và độ uy tín Chủ xe.
 */
class SearchController
{
    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC TÌM KIẾM CHI TIẾT ĐA VI (PUBLIC SEARCH ENGINE)
    // ============================================================================

    /**
     * API Tìm kiếm & Lọc xe (Public)
     * 
     * @param Request $request Yêu cầu HTTP chứa các tham số Bộ lọc (thời gian, vị trí, giá, hãng,...)
     * @return \Illuminate\Http\JsonResponse Danh sách phương tiện phân trang phù hợp
     */
    public function index(Request $request)
    {
        // ============================================================================
        // NHÓM 1: TRUY VẤN CẤP ĐỘ CỐT LÕI (CORE QUERY GENERATOR)
        // Chỉ nạp xe có trạng thái Sẵn sàng (available), đối chiếu thông số từ `car_models`
        // và tự động loại bỏ xe của Chủ xe bị khóa Ví nội bộ (Locked Wallet Protection).
        // ============================================================================
        $query = Vehicle::query()
            ->select('vehicles.*')
            ->join('car_models', 'vehicles.car_model_id', '=', 'car_models.id')
            ->where('vehicles.status', 'available')
            ->whereDoesntHave('owner.wallet', function ($q) {
                $q->where('status', 'locked');
            });

        // ============================================================================
        // NHÓM 2: LỚP LỌC SINH TỒN V V SINH THÁI (SURVIVAL & AVAILABILITY FILTERS)
        // ============================================================================
        // 1. Lọc theo Khung Thời gian Thuê (Tự động loại phương tiện vướng lịch Đặt xe hoặc Bị Chủ xe Khóa lịch)
        if ($request->filled('start_datetime') && $request->filled('end_datetime')) {
            $start = $request->start_datetime;
            $end = $request->end_datetime;

            // Kiểm soát cấn lịch với các Đơn thuê hiện hữu thông qua quan hệ 'bookings'
            $query->whereDoesntHave('bookings', function ($q) use ($start, $end) {
                $q->where('start_datetime', '<', $end)
                ->where('end_datetime', '>', $start)
                ->whereIn('status', ['pending_approval', 'pending_payment', 'confirmed', 'in_progress']);
            });

            // Kiểm soát cấn lịch với Cài đặt Khóa ngày của Chủ xe trong quan hệ 'calendars'
            $query->whereDoesntHave('calendars', function ($q) use ($start, $end) {
                $q->where('is_blocked', true)
                ->whereBetween('date', [date('Y-m-d', strtotime($start)), date('Y-m-d', strtotime($end))]);
            });
        }

        // 2. Lọc theo Tọa độ Vị trí & Bán kính (Áp dụng công thức Lượng giác Haversine tương thích PostgreSQL)
        if ($request->filled('latitude') && $request->filled('longitude')) {
            $lat = $request->latitude;
            $lng = $request->longitude;
            $radius = $request->input('radius', 50); // Bán kính mặc định: 50km

            // Công thức tính khoảng cách bề mặt hình cầu (Haversine Formula) tính theo đơn vị Kilometers
            $haversine = "( 6371 * acos( cos( radians(?) ) * cos( radians( latitude ) ) * cos( radians( longitude ) - radians(?) ) + sin( radians(?) ) * sin( radians( latitude ) ) ) )";

            // Đồng thời tạo cột ảo `distance` và sàng lọc ngay tại tầng Cơ sở dữ liệu
            $query->selectRaw("{$haversine} AS distance", [$lat, $lng, $lat])
                ->whereRaw("{$haversine} <= ?", [$lat, $lng, $lat, $radius])
                  ->orderBy('distance', 'asc'); // Sắp xếp ưu tiên các xe nằm gần vị trí Khách hàng nhất lên trước
        } else {
            // Mặc định hiển thị các phương tiện mới gia nhập hệ thống nhất lên đầu
            $query->latest('vehicles.created_at'); 
        }

        // ============================================================================
        // NHÓM 3: PHỄU LỌC THÔNG SỐ KỸ THUẬT (TECHNICAL & SPECIFICATION FILTERS)
        // Tự động xáo trộn và xếp chồng tiêu chí theo cấu trúc Cascading Conditions
        // ============================================================================
        $query->when($request->category_id, fn($q, $val) => $q->where('car_models.category_id', $val))
            ->when($request->seat_count, fn($q, $val) => $q->where('car_models.seat_count', $val))
            ->when($request->brand_name, fn($q, $val) => $q->where('car_models.brand_name', $val))
            ->when($request->model_name, fn($q, $val) => $q->where('car_models.model_name', $val))
            ->when($request->transmission_id, fn($q, $val) => $q->where('car_models.transmission_id', $val))
            ->when($request->fuel_id, fn($q, $val) => $q->where('car_models.fuel_id', $val));

        // ============================================================================
        // NHÓM 4: CÁ NHÂN HÓA CHI PHÍ & TRẢI NGHIỆM (PRICE, AMENITIES & HOST RATINGS)
        // ============================================================================
        // Phễu lọc Khách hàng Mục tiêu theo Giới hạn tài chính và Đời xe (Năm sản xuất)
        $query->when($request->min_price, fn($q, $val) => $q->where('vehicles.base_price', '>=', $val))
            ->when($request->max_price, fn($q, $val) => $q->where('vehicles.base_price', '<=', $val))
            ->when($request->min_year, fn($q, $val) => $q->where('vehicles.year', '>=', $val));

        // Lọc xe Hỗ trợ giao nhận tận tay
        $query->when($request->has('is_delivery_supported'), function ($q) use ($request) {
                    if ($request->is_delivery_supported === 'true' || $request->is_delivery_supported === true || $request->is_delivery_supported == 1) {
                        $q->where('vehicles.is_delivery_supported', true);
                    }
                });

        // Lọc xe Miễn thuế chấp/Không yêu cầu thủ tục rườm rà
        $query->when($request->has('is_mortgage_exempt'), function ($q) use ($request) {
            if ($request->is_mortgage_exempt === 'true' || $request->is_mortgage_exempt === true || $request->is_mortgage_exempt == 1) {
                $q->where('vehicles.is_mortgage_exempt', true);
            }
        });

        // Lọc theo Danh mục Tiện ích mong muốn (Sử dụng quan hệ Many-to-Many với `amenities`)
        if ($request->filled('amenity_ids')) {
            $amenityIds = is_array($request->amenity_ids) ? $request->amenity_ids : explode(',', $request->amenity_ids);
            foreach ($amenityIds as $id) {
                $query->whereHas('amenities', fn($q) => $q->where('amenities.id', $id));
            }
        }

        // Trích xuất Sub-Query tĩnh vào Select tạo cột ảo `owner_avg_rating`
        $query->addSelect([
            'owner_avg_rating' => Review::selectRaw('ROUND(AVG(rating), 1)')
                ->whereColumn('reviewee_id', 'vehicles.owner_id')
        ]);

        // Lọc Tiêu chuẩn Chủ xe Uy tín/Super Host (Tương thích thắt chặt PostgreSQL Sub-query in Where Clause)
        if ($request->filled('min_owner_rating')) {
            $query->whereRaw(
                '(SELECT ROUND(AVG(rating), 1) FROM reviews WHERE reviewee_id = vehicles.owner_id) >= ?',
                [$request->min_owner_rating]
            );
        }

        // ============================================================================
        // TRẢ VỀ KẾT QUẢ ĐỐI TƯỢNG PHẦN TRANG (PAGINATED RESPONSE)
        // ============================================================================
        // Nạp trước dữ liệu liên kết Eager Loading nhằm loại trừ độ chễ query N+1
        $query->with([
            'images' => function($q) {
                $q->where('is_thumbnail', true); // Chỉ lấy hình ảnh đại diện (ảnh bìa Thumbnail) cho giao diện thẻ xe
            },
            'owner', 
            'carModel.category', 
            'carModel.transmission', 
            'carModel.fuel', 
            'amenities'
        ])->withCount(['bookings as total_trips' => function($q) {
            $q->where('status', 'completed'); // Đếm lượt hoàn hảo cho độ uy tín của xe
        }]);

        return response()->json([
            'success' => true,
            'message' => 'Lấy danh sách xe thành công.',
            'data' => $query->paginate($request->input('per_page', 15))
        ]);
    }

    // ============================================================================
    // 3. NHÓM PHƯƠNG THỨC NẠP DANH H MỤC BỘ LỌC TĨNH (FILTER OPTIONS PROVIDER)
    // ============================================================================

    /**
     * Cung cấp toàn bộ danh bạ các Tùy chọn Lọc cho Frontend (Loại xe, Nhiên liệu, Hộp số, Mẫu xe)
     *
     * @return \Illuminate\Http\JsonResponse Dữ liệu tùy chọn tĩnh
     */
    public function getFilterOptions() {
        return response()->json([
            'success' => true,
            'data' => [
                'categories' => \App\Models\Category::all(),
                'fuels' => \App\Models\Fuel::all(),
                'transmissions' => \App\Models\Transmission::all(),
                'car_models' => \App\Models\CarModel::all() 
            ]
        ]);
    }
}