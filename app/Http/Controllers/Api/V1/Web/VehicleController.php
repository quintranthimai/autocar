<?php

namespace App\Http\Controllers\Api\V1\Web;

use Illuminate\Support\Facades\DB;

use App\Models\Vehicle;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ============================================================================
 * LỚP VEHICLE CONTROLLER (QUẢN LÝ XE CHO THUÊ & CHUYẾN ĐI PHẦN NGƯỜI DÙNG/CHỦ XE)
 * ============================================================================
 * Controller nghiệp vụ phụ trách hiển thị thông tin chi tiết xe (Public), quản lý
 * đội xe cho Chủ xe (đăng ký xe mới, cập nhật hồ sơ xe, mở khóa phục hồi sau bảo trì,
 * thiết lập các danh mục phí bồi dưỡng/khử mùi và khóa lịch bận ngày nhận khách).
 */
class VehicleController
{
    /**
     * ========================================================================
     * 1. HÀM CHI TIẾT: LẤY THÔNG TIN CHI TIẾT XE VÀ CÁC CHỈ SỐ LỘ TRÌNH (PUBLIC)
     * ========================================================================
     * Phục vụ trang xem chi tiết phương tiện của Khách hàng, tổng hợp toàn vẹn:
     * - Thông số kỹ thuật xe, tiện ích, cấu hình giá, lịch sử giá và danh sách phụ phí.
     * - Các chỉ số tín nhiệm của Chủ xe (điểm đánh giá trung bình, số chuyến hoàn thành).
     * - Lịch trống / ngày bận (dựa trên lịch khóa tự động và đơn đặt xe hiện hành).
     * - Cấu hình chính sách bảo hiểm và danh sách phương tiện tương tự cùng phân khúc.
     *
     * @param  int  $id                           ID của xe cho thuê cần hiển thị.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON chứa toàn bộ dữ liệu chi tiết xe.
     */
    public function show($id)
    {
        // 1. KÉO DỮ LIỆU LÕI (Xe, Ảnh, Thông số, Tiện ích, Phụ phí, Chủ xe)
        $vehicle = Vehicle::with([
            'images',
            'carModel.category',
            'carModel.transmission',
            'carModel.fuel',
            'amenities',
            'surcharges',
            'owner',
            // Lấy danh sách lịch bận được thiết lập từ hôm nay trở về sau
            'calendars' => function($q) {
                $q->where('date', '>=', now()->format('Y-m-d'))->orderBy('date');
            }
        ])
        ->findOrFail($id);

        $ownerId = $vehicle->owner_id;

        // Tải danh sách đánh giá công khai (Reviews) nhắm đến Chủ xe này
        $vehicle->load(['reviews' => function($q) use ($ownerId) {
            $q->where('reviews.reviewee_id', $ownerId)
              ->with('reviewer:id,name,avatar')
              ->latest('reviews.created_at');
        }]);

        // Tải tổng số lượng lượt đánh giá của Chủ xe
        $vehicle->loadCount(['reviews' => function($q) use ($ownerId) {
            $q->where('reviews.reviewee_id', $ownerId);
        }]);

        // Lấy danh sách các ngày đã có người thuê thực tế (Booking active)
        $activeBookings = $vehicle->bookings()
            ->whereIn('status', ['pending', 'approved', 'in_progress'])
            ->where('end_datetime', '>=', now()->startOfDay())
            ->get(['start_datetime', 'end_datetime']);
        
        $bookedDates = [];
        foreach ($activeBookings as $booking) {
            $start = \Carbon\Carbon::parse($booking->start_datetime)->startOfDay();
            $end = \Carbon\Carbon::parse($booking->end_datetime)->startOfDay();
            for ($date = $start; $date->lte($end); $date->addDay()) {
                $bookedDates[] = $date->format('Y-m-d');
            }
        }
        $vehicle->booked_dates = array_values(array_unique($bookedDates));

        // 2. TÍNH TOÁN CÁC CHỈ SỐ CỦA XE VÀ CHỦ XE (PHƯƠNG ÁN 1: TÁCH BIỆT TRỊ GIÁ CHỦ VÀ XE)
        // 2.1 Tính điểm trung bình và tổng số chuyến đi thành công của riêng chiếc xe này
        $vehicle->avg_rating = $vehicle->reviews->avg('rating') ? round($vehicle->reviews->avg('rating'), 1) : 0;
        $vehicle->total_reviews = $vehicle->reviews_count;
        $vehicle->total_trips = $vehicle->bookings()->where('status', 'completed')->count();

        // 2.2 Tính tổng thành tựu tích lũy trên toàn bộ các xe của Chủ nhà (Host Reputation)
        if ($vehicle->owner) {
            $vehicle->owner->total_trips = \App\Models\Booking::whereHas('vehicle', function($q) use ($ownerId) {
                $q->where('owner_id', $ownerId);
            })->where('status', 'completed')->count();
        }

        // Tính toán các thông số phụ phí dự kiến
        $vehicle->overtime_fee = round($vehicle->base_price / 10);
        $vehicle->fuel_fee_policy = 'Thanh toán theo thực tế (Số vạch hao hụt * Định mức lít/vạch * Giá xăng/điện thị trường)';

        // Xử lý đền bù phụ phí (vệ sinh, khử mùi) mặc định nếu chủ xe chưa tự cài đặt
        $hasCleaning = false;
        $hasDeodorize = false;
        foreach ($vehicle->surcharges as $surcharge) {
            if ($surcharge->surcharge_type === 'cleaning') $hasCleaning = true;
            if ($surcharge->surcharge_type === 'deodorize') $hasDeodorize = true;
        }
        
        if (!$hasCleaning || !$hasDeodorize) {
            $defaultSurcharges = collect($vehicle->surcharges);
            if (!$hasCleaning) {
                $defaultSurcharges->push((object)[
                    'surcharge_type' => 'cleaning',
                    'price' => 100000, // Phí vệ sinh mặc định 100k
                    'description' => 'Phí vệ sinh xe mặc định'
                ]);
            }
            if (!$hasDeodorize) {
                $defaultSurcharges->push((object)[
                    'surcharge_type' => 'deodorize',
                    'price' => 350000, // Phí khử mùi mặc định 350k
                    'description' => 'Phí khử mùi xe mặc định'
                ]);
            }
            // Ghi đè lại danh sách phụ phí vào quan hệ của Eloquent
            $vehicle->setRelation('surcharges', $defaultSurcharges);
        }

        // 3. LẤY CẤU HÌNH HỆ THỐNG (Bảo hiểm, Chính sách huỷ chuyến)
        $systemPolicies = [
            'insurance_fee_per_day' => SystemSetting::where('setting_key', 'insurance_fee')->value('setting_value') ?? 70761,
            'extra_insurance_fee' => SystemSetting::where('setting_key', 'extra_insurance_fee')->value('setting_value') ?? 50000,
            'cancellation_policy' => 'Miễn phí hủy trong 1h. Hủy trước 7 ngày phạt 10%...' 
        ];

        // 4. LẤY XE TƯƠNG TỰ (Cùng phân khúc Category, loại trừ xe hiện tại)
        $similarVehicles = Vehicle::with(['images' => function($q) {
                $q->where('is_thumbnail', true); // Chỉ lấy ảnh bìa để tối ưu băng thông
            }, 'carModel.transmission', 'carModel.fuel'])
            ->where('status', 'available')
            ->where('id', '!=', $vehicle->id)
            ->whereHas('carModel', function($q) use ($vehicle) {
                $q->where('category_id', $vehicle->carModel->category_id);
            })
            ->withCount(['bookings as total_trips' => function($q) {
                $q->where('status', 'completed');
            }])
            ->latest()
            ->take(4)
            ->get()
            ->map(function ($sim) {
                return [
                    'id' => $sim->id,
                    'name' => $sim->carModel->brand_name . ' ' . $sim->carModel->model_name . ' ' . $sim->year,
                    'thumbnail' => $sim->images->first()->image_url ?? null,
                    'base_price' => $sim->base_price,
                    'transmission' => $sim->carModel->transmission->display_name ?? null,
                    'fuel' => $sim->carModel->fuel->display_name ?? null,
                    'seat_count' => $sim->carModel->seat_count,
                    'address' => $sim->parking_address,
                    'total_trips' => $sim->total_trips ?? 0,
                ];
            });

        // 5. TRẢ VỀ JSON HOÀN CHỈNH CHO GIAO DIỆN CLIENT
        return response()->json([
            'success' => true,
            'message' => 'Lấy thông tin chi tiết xe thành công.',
            'data' => [
                'vehicle_info' => $vehicle,
                'system_policies' => $systemPolicies,
                'similar_vehicles' => $similarVehicles
            ]
        ]);
    }

    /**
     * ========================================================================
     * 2. HÀM QUẢN LÝ: KHÔI PHỤC TRẠNG THÁI HOẠT ĐỘNG TỪ CHẾ ĐỘ BẢO TRÌ
     * ========================================================================
     * Cho phép Chủ xe mở khóa xe trở lại sàn nhận khách sau khi kết thúc bảo trì.
     * Áp dụng chuỗi rà soát nghiêm ngặt về quyền tài khoản, nợ tài chính và tranh chấp sự cố.
     *
     * @param  \Illuminate\Http\Request  $request    Yêu cầu HTTP gửi lên.
     * @param  int                       $vehicleId  ID của phương tiện cần mở khóa.
     * @return \Illuminate\Http\JsonResponse         Phản hồi JSON trạng thái sau khi phục hồi.
     */
    public function restoreFromMaintenance(Request $request, $vehicleId)
    {
        $user = Auth::user();
        $vehicle = Vehicle::findOrFail($vehicleId);

        // 1. Kiểm tra quyền sở hữu đối với phương tiện
        if ($vehicle->owner_id !== $user->id) {
            return response()->json([
                'success' => false, 
                'message' => 'Bạn không có quyền thao tác trên chiếc xe này.'
            ], 403);
        }

        // 1.5 Kiểm tra danh sách đen (Blacklist account)
        if ($user->is_blacklisted) {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản của bạn đang bị khóa (Blacklist) do vi phạm chính sách hệ thống. Bạn không thể thực hiện thao tác này!'
            ], 403);
        }

        // 1.6 Kiểm tra trạng thái hồ sơ pháp lý (eKYC)
        if ($user->kyc_status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản của bạn chưa được xác minh danh tính (KYC) hoặc đã bị thu hồi. Vui lòng cập nhật hồ sơ để tiếp tục hoạt động!'
            ], 403);
        }

        // 1.7 Kiểm tra tình trạng tài chính Ví điện tử (Áp dụng xử phạt âm cọc)
        $wallet = $user->wallet;
        if ($wallet) {
            if ($wallet->status === 'locked') {
                return response()->json([
                    'success' => false,
                    'message' => 'Ví điện tử của bạn đang bị khóa. Vui lòng liên hệ CSKH để xử lý trước khi khôi phục xe!'
                ], 403);
            }
            if ($wallet->available_balance < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Số dư ví của bạn đang âm (nợ phí phạt hệ thống do hủy chuyến). Vui lòng nạp tiền thanh toán nợ trước khi mở khóa xe!'
                ], 403);
            }
        }

        // 2. Ràng buộc trạng thái hiện tại (Chỉ xe ở chế độ bảo trì mới được xử lý)
        if ($vehicle->status !== 'maintenance') {
            return response()->json([
                'success' => false, 
                'message' => 'Xe của bạn hiện không ở trạng thái bảo trì.'
            ], 400);
        }

        // 2.5 Ràng buộc Tickets: Cấm mở lại xe nếu đang bị khiếu nại hoặc chờ Admin thẩm định sự cố
        $hasPendingTicket = \App\Models\Ticket::whereHas('booking', function ($q) use ($vehicleId) {
            $q->where('vehicle_id', $vehicleId);
        })->whereIn('status', ['new', 'open', 'in_progress'])->exists();

        if ($hasPendingTicket) {
            return response()->json([
                'success' => false, 
                'message' => 'Xe đang có yêu cầu báo cáo sự cố chờ Admin thẩm định. Bạn không thể tự mở khóa lúc này!'
            ], 403);
        }

        // 3. Đưa xe trở lại trạng thái sẵn sàng đón khách (Available)
        $vehicle->status = 'available';
        $vehicle->save();

        return response()->json([
            'success' => true,
            'message' => 'Tuyệt vời! Xe của bạn đã sẵn sàng hoạt động trở lại trên hệ thống.',
            'data' => [
                'vehicle_id' => $vehicle->id,
                'status' => $vehicle->status
            ]
        ]);
    }

    /**
     * ========================================================================
     * 3. HÀM ĐĂNG KÝ: TẠO MỚI HỒ SƠ PHƯƠNG TIỆN CHO THUÊ (CHỜ VIỆC PHÊ DUYỆT)
     * ========================================================================
     * Cho phép Đối tác Chủ xe gửi yêu cầu niêm yết xe lên hệ thống, kèm tải lên:
     * - Hình ảnh thực tế phương tiện (3-10 ảnh).
     * - Giấy tờ Cà vẹt pháp lý (lưu đồng bộ sang bảng LegalDocument để hậu kiểm).
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu biểu mẫu và file đính kèm.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON xác nhận đăng ký thành công.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Kiểm tra phân quyền truy cập chức năng dành cho Chủ xe
        if (!$user->roles->contains('slug', 'owner') && !$user->roles->contains('slug', 'partner')) {
            return response()->json(['success' => false, 'message' => 'Bạn cần nâng cấp thành Đối tác Chủ xe để thực hiện chức năng này.'], 403);
        }

        // Validate biểu mẫu chi tiết từ Frontend gửi lên
        $request->validate([
            'license_plate' => 'required|string|unique:vehicles,license_plate',
            'car_model_id' => 'required|integer',
            'year' => 'required|integer',
            'base_price' => 'required|numeric|min:300000|max:3000000',
            'parking_address' => 'required|string',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'images' => 'required|array|min:3|max:10',
            'images.*' => 'image|mimes:jpeg,png,jpg|max:5120',
            'cavet_image' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ], [
            'latitude.required' => 'Hệ thống không lấy được tọa độ GPS. Vui lòng chọn địa chỉ từ danh sách gợi ý của bản đồ.',
            'images.required' => 'Vui lòng tải lên ít nhất 3 ảnh xe.',
            'images.array' => 'Danh sách ảnh xe không hợp lệ.',
            'images.min' => 'Vui lòng tải lên tối thiểu 3 ảnh xe.',
            'images.max' => 'Bạn chỉ được tải tối đa 10 ảnh xe.',
            'cavet_image.required' => 'Vui lòng tải lên 1 ảnh giấy tờ xe (cà vẹt).'
        ]);

        DB::beginTransaction();
        try {
            // 1. MAP DỮ LIỆU FRONTEND -> DATABASE VÀ LƯU HỒ SƠ XE CHỜ VƯỢT KHẢO
            $vehicle = Vehicle::create([
                'owner_id' => $user->id,
                'car_model_id' => $request->car_model_id,
                'license_plate' => strtoupper($request->license_plate),
                'year' => $request->year,
                'base_price' => $request->base_price,
                'description' => $request->description,
                'status' => 'pending', // Luôn khởi tạo với trạng thái Chờ duyệt
                
                // Thông tin tọa độ định vị GPS
                'parking_address' => $request->parking_address,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                
                // Mapping cấu hình chính sách giao nhận xe tận nhà
                'is_delivery_supported' => filter_var($request->delivery_enabled, FILTER_VALIDATE_BOOLEAN),
                'delivery_radius_km' => $request->delivery_radius,
                'delivery_fee_per_km' => $request->delivery_fee_per_km,
                'free_delivery_radius_km' => $request->free_delivery_radius,
                
                // Mapping chính sách khuyến mãi thuê dài ngày
                'is_discount_enabled' => filter_var($request->discount_enabled, FILTER_VALIDATE_BOOLEAN),
                'weekly_discount_percent' => $request->weekly_discount_percent,
                
                // Mapping giới hạn cự ly di chuyển trong ngày
                'is_mileage_limit_enabled' => filter_var($request->mileage_limit_enabled, FILTER_VALIDATE_BOOLEAN),
                'mileage_limit_per_day' => $request->mileage_limit_per_day,
                'extra_fee_per_km' => $request->extra_fee_per_km,

                // Các điều khoản và cam kết tài sản
                'rental_terms' => $request->rental_terms,
                'is_mortgage_exempt' => filter_var($request->is_mortgage_exempt, FILTER_VALIDATE_BOOLEAN),
            ]);

            // 2. LƯU DANH BẠ TIỆN ÍCH (Amenities - Quan hệ nhiều-nhiều)
            if ($request->has('amenities') && !empty($request->amenities)) {
                $amenityIds = explode(',', $request->amenities); 
                $vehicle->amenities()->attach($amenityIds);
            }

            // 3. XỬ LÝ LƯU TRỮ HỆ THỐNG HÌNH ẢNH XE TRÊN PUBLIC STORAGE
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $index => $image) {
                    $path = $image->store('vehicles/images', 'public');
                    $vehicle->images()->create([
                        'image_url' => asset('storage/' . $path),
                        'is_thumbnail' => $index === 0 // Chọn ảnh vị trí đầu tiên làm Ảnh Bìa đại diện
                    ]);
                }
            }

            // 4. ĐỒNG BỘ GIẤY TỜ CÀ VẸT VÀO HỒ SƠ KIỂM DUYỆT PHÁP LÝ (LEGAL DOCUMENTS)
            if ($request->hasFile('cavet_image')) {
                $cavetPath = $request->file('cavet_image')->store('documents/vehicles', 'public');
                \App\Models\LegalDocument::create([
                    'user_id' => $user->id,
                    'vehicle_id' => $vehicle->id, 
                    'document_type' => 'vehicle_registration',
                    'document_number' => strtoupper($request->license_plate),
                    'front_image_url' => asset('storage/' . $cavetPath),
                    'back_image_url' => asset('storage/' . $cavetPath),
                    'status' => 'pending',
                ]);
            }

            // Hoàn tât Transaction khởi tạo thành công
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Đăng ký xe thành công! Vui lòng chờ Ban quản trị phê duyệt.',
                'data' => $vehicle
            ], 201);

        } catch (\Exception $e) {
            // Đảo ngược dữ liệu nếu xuất hiện ngoại lệ kĩ thuật
            DB::rollBack();
            return response()->json([
                'success' => false, 
                'message' => 'Lỗi hệ thống: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ========================================================================
     * 4. HÀM DANH MỤC: TRỢ GIÚP BIỂU MẪU (LẤY OPTIONS CHO MÀN ĐĂNG KÝ XE)
     * ========================================================================
     * Cung cấp trọn bộ danh mục từ khóa chuyên dụng cho giao diện chọn trên Client
     * bao gồm: Phân khúc (Category), Nhiên liệu (Fuel), Hộp số, Dòng xe và Tiện ích.
     *
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON danh sách tham chiếu.
     */
    public function getFormOptions()
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'categories' => \App\Models\Category::all(),
                    'fuels' => \App\Models\Fuel::all(),
                    'transmissions' => \App\Models\Transmission::all(),
                    'car_models' => \App\Models\CarModel::all(),
                    'amenities' => \App\Models\Amenity::all(),
                ]
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi Backend: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ========================================================================
     * 5. HÀM TRUY VẤN: LẤY DANH SÁCH XE CỦA CHỦ XE (MY VEHICLES)
     * ========================================================================
     * Hỗ trợ Đối tác quản lý danh sách đội xe thuộc sở hữu, kèm theo thông số
     * đếm nhanh số chuyến đi thành công để đánh giá hiệu suất kinh doanh.
     *
     * @param  \Illuminate\Http\Request  $request  Yêu cầu HTTP gửi lên.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON mảng danh sách xe.
     */
    public function myVehicles(Request $request)
    {
        $user = Auth::user();

        $vehicles = Vehicle::with(['carModel', 'images' => function($q) {
            $q->where('is_thumbnail', true); // Chỉ tải hình ảnh bìa để tăng tốc độ phản hồi
        }])
        ->withCount(['bookings as total_trips' => function($q) {
            $q->where('status', 'completed');
        }])
        ->where('owner_id', $user->id)
        ->orderBy('created_at', 'desc')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $vehicles
        ]);
    }

    /**
     * ========================================================================
     * 6. HÀM CẬP NHẬT: THAY ĐỔI THÔNG SỐ HỒ SƠ PHƯƠNG TIỆN CHO THUÊ
     * ========================================================================
     * Xử lý yêu cầu điều chỉnh các trường thông tin cơ bản: giá niêm yết, địa chỉ,
     * các điều khoản, danh sách phụ phí (Vệ sinh/Khử mùi) và cấu hình khuyến mãi.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu chỉnh sửa gửi lên từ Chủ xe.
     * @param  int                       $id       ID của phương tiện cần chỉnh sửa.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON kết quả xử lý cập nhật.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        
        // Xác minh chủ xe chính chủ
        $vehicle = Vehicle::where('id', $id)->where('owner_id', $user->id)->first();
        if (!$vehicle) return response()->json(['success' => false, 'message' => 'Xe không tồn tại hoặc bạn không có quyền sửa.'], 404);

        $request->validate([
            'base_price' => 'required|numeric|min:300000|max:3000000',
            'parking_address' => 'required|string',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
        ]);

        DB::beginTransaction();
        try {
            // Cập nhật thông số chính
            $vehicle->base_price = $request->base_price;
            $vehicle->description = $request->description ?? $vehicle->description;
            $vehicle->parking_address = $request->parking_address;
            $vehicle->latitude = $request->latitude;
            $vehicle->longitude = $request->longitude;
            
            // Cập nhật chính sách dịch vụ gia tăng (Giao xe / Giám giá / Giới hạn số Km)
            if ($request->has('is_delivery_supported')) $vehicle->is_delivery_supported = filter_var($request->is_delivery_supported, FILTER_VALIDATE_BOOLEAN);
            if ($request->has('delivery_radius_km')) $vehicle->delivery_radius_km = $request->delivery_radius_km;
            if ($request->has('free_delivery_radius_km')) $vehicle->free_delivery_radius_km = $request->free_delivery_radius_km;
            if ($request->has('delivery_fee_per_km')) $vehicle->delivery_fee_per_km = $request->delivery_fee_per_km;
            
            if ($request->has('is_discount_enabled')) $vehicle->is_discount_enabled = filter_var($request->is_discount_enabled, FILTER_VALIDATE_BOOLEAN);
            if ($request->has('weekly_discount_percent')) $vehicle->weekly_discount_percent = $request->weekly_discount_percent;

            if ($request->has('is_mileage_limit_enabled')) $vehicle->is_mileage_limit_enabled = filter_var($request->is_mileage_limit_enabled, FILTER_VALIDATE_BOOLEAN);
            if ($request->has('mileage_limit_per_day')) $vehicle->mileage_limit_per_day = $request->mileage_limit_per_day;
            if ($request->has('extra_fee_per_km')) $vehicle->extra_fee_per_km = $request->extra_fee_per_km;

            if ($request->has('rental_terms')) $vehicle->rental_terms = $request->rental_terms;
            if ($request->has('is_mortgage_exempt')) $vehicle->is_mortgage_exempt = filter_var($request->is_mortgage_exempt, FILTER_VALIDATE_BOOLEAN);
            
            $vehicle->save();

            // Cập nhật đồng bộ các tiện ích đi kèm (Amenities sync)
            if ($request->has('amenities')) {
                $amenityIds = is_array($request->amenities) ? $request->amenities : json_decode($request->amenities, true);
                if (is_array($amenityIds)) {
                    $vehicle->amenities()->sync($amenityIds);
                }
            }
            
            // Cập nhật toàn bộ các cấu hình Phụ phí bồi dưỡng của xe
            if ($request->has('surcharges')) {
                $surcharges = is_array($request->surcharges) ? $request->surcharges : json_decode($request->surcharges, true);
                if (is_array($surcharges)) {
                    \App\Models\VehicleSurcharge::where('vehicle_id', $vehicle->id)->delete();
                    foreach ($surcharges as $surcharge) {
                        \App\Models\VehicleSurcharge::create([
                            'vehicle_id' => $vehicle->id,
                            'surcharge_type' => $surcharge['surcharge_type'],
                            'title' => $surcharge['surcharge_type'] === 'cleaning' ? 'Phí vệ sinh' : ($surcharge['surcharge_type'] === 'deodorize' ? 'Phí khử mùi' : ($surcharge['surcharge_type'] === 'over_limit' ? 'Phí vượt giới hạn' : 'Phụ phí')),
                            'price' => $surcharge['price'],
                            'description' => $surcharge['description'] ?? null
                        ]);
                    }
                }
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Cập nhật thông tin xe thành công!', 'data' => $vehicle]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Lỗi cập nhật: ' . $e->getMessage()], 500);
        }
    }

    /**
     * ========================================================================
     * 7. HÀM TRUY VẤN LỊCH: LẤY DANH SÁCH CÁC NGÀY BỊ KHÓA / ĐÃ ĐẶT (BUSY DATES)
     * ========================================================================
     * Tra cứu danh sách ngày bận trên bảng lịch phương tiện (VehicleCalendar)
     * giúp Chủ xe quản lý trực quan trên bộ lịch tương tác Frontend.
     *
     * @param  int  $id                           ID của phương tiện cho thuê.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON mảng ngày bị khóa.
     */
    public function getBusyDates($id)
    {
        $user = Auth::user();
        $vehicle = Vehicle::where('id', $id)->where('owner_id', $user->id)->first();
        if (!$vehicle) return response()->json(['success' => false, 'message' => 'Xe không hợp lệ'], 404);

        $busyDates = \App\Models\VehicleCalendar::where('vehicle_id', $id)->where('is_blocked', true)->orderBy('date', 'asc')->get();
        return response()->json(['success' => true, 'data' => $busyDates]);
    }

    /**
     * ========================================================================
     * 8. HÀM THIẾT LẬP LỊCH: THÊM NGÀY BẬN / KHÓA LỊCH NHẬN KHÁCH
     * ========================================================================
     * Cho phép Chủ xe chủ động đóng lịch thuê trong một khoảng thời gian cụ thể
     * (thí dụ xe đi bảo trì riêng hoặc sử dụng cá nhân). Ràng buộc cấm thực hiện
     * nếu trong khoảng ngày được lựa chọn đã có Đơn đặt xe hiện hành.
     *
     * @param  \Illuminate\Http\Request  $request  Yêu cầu HTTP (start_date, end_date).
     * @param  int                       $id       ID của phương tiện cần khóa lịch.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON kết quả cập nhật lịch.
     */
    public function addBusyDates(Request $request, $id)
    {
        $user = Auth::user();
        $vehicle = Vehicle::where('id', $id)->where('owner_id', $user->id)->first();
        if (!$vehicle) return response()->json(['success' => false, 'message' => 'Xe không hợp lệ'], 404);

        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date'
        ]);

        $start = \Carbon\Carbon::parse($request->start_date);
        $end = \Carbon\Carbon::parse($request->end_date);

        // Kiểm tra xem có booking nào nằm trong khoảng thời gian muốn khóa này không
        $hasBooking = \App\Models\Booking::where('vehicle_id', $id)
            ->whereIn('status', ['pending', 'approved', 'in_progress'])
            ->where(function($q) use ($start, $end) {
                $q->whereBetween('start_datetime', [$start, $end])
                  ->orWhereBetween('end_datetime', [$start, $end])
                  ->orWhere(function($q2) use ($start, $end) {
                      $q2->where('start_datetime', '<=', $start)->where('end_datetime', '>=', $end);
                  });
            })->exists();
            
        if ($hasBooking) {
            return response()->json(['success' => false, 'message' => 'Không thể khóa lịch vì xe đang có đơn đặt trong khoảng thời gian này.'], 400);
        }

        DB::beginTransaction();
        try {
            // Khởi tạo hoặc cập nhật trạng thái khóa lịch cho từng ngày trong giai đoạn
            for ($date = $start; $date->lte($end); $date->addDay()) {
                \App\Models\VehicleCalendar::updateOrCreate(
                    ['vehicle_id' => $id, 'date' => $date->format('Y-m-d')],
                    ['is_blocked' => true]
                );
            }
            DB::commit();
            return response()->json(['success' => true, 'message' => 'Đã khóa lịch thành công.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    /**
     * ========================================================================
     * 9. HÀM THIẾT LẬP LỊCH: GỠ KHÓA NGÀY BẬN TRÊN HỆ THỐNG
     * ========================================================================
     * Xóa bản ghi khóa lịch của một ngày cụ thể, trả lại trang thái sẵn sàng
     * để phương tiện có thể tiếp tục nhận đơn đặt xe từ khách hàng.
     *
     * @param  int                       $id       ID của phương tiện cho thuê.
     * @param  \Illuminate\Http\Request  $request  Tham số truy vấn URL (date).
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON kết quả hủy khóa.
     */
    public function removeBusyDate($id, Request $request)
    {
        $user = Auth::user();
        $vehicle = Vehicle::where('id', $id)->where('owner_id', $user->id)->first();
        if (!$vehicle) return response()->json(['success' => false, 'message' => 'Xe không hợp lệ'], 404);

        $date = $request->query('date');
        if (!$date) return response()->json(['success' => false, 'message' => 'Thiếu tham số ngày (date)'], 400);

        // Thực hiện xóa dòng giới hạn lịch trên cơ sở dữ liệu
        \App\Models\VehicleCalendar::where('vehicle_id', $id)->where('date', $date)->delete();

        return response()->json(['success' => true, 'message' => 'Đã mở khóa lịch thành công.']);
    }
    /**
     * ========================================================================
     * 10. HÀM THỐNG KÊ: LẤY THỐNG KÊ DOANH THU & XE ĐƯỢC THUÊ NHIỀU CỦA CHỦ XE
     * ========================================================================
     */
    public function getOwnerStats(Request $request)
    {
        $user = Auth::user();
        $year = $request->input('year', \Carbon\Carbon::now()->year);
        $month = $request->input('month', \Carbon\Carbon::now()->month);

        $bookings = DB::table('bookings')
            ->join('vehicles', 'bookings.vehicle_id', '=', 'vehicles.id')
            ->join('car_models', 'vehicles.car_model_id', '=', 'car_models.id')
            ->where('vehicles.owner_id', $user->id)
            ->where('bookings.status', 'completed')
            ->whereYear('bookings.end_datetime', $year)
            ->whereMonth('bookings.end_datetime', $month)
            ->select(
                'vehicles.id',
                'vehicles.license_plate',
                DB::raw('CONCAT(car_models.brand_name, " ", car_models.model_name) as vehicle_name'),
                DB::raw('COUNT(bookings.id) as trips_count'),
                DB::raw('SUM(bookings.total_amount) as total_revenue') 
            )
            ->groupBy('vehicles.id', 'vehicles.license_plate', 'car_models.brand_name', 'car_models.model_name')
            ->get();
            
        $totalRevenue = $bookings->sum('total_revenue');
        $totalEarning = $totalRevenue * 0.7; // Tạm tính chủ xe nhận 70%
        $totalTrips = $bookings->sum('trips_count');
        
        $topVehicle = $bookings->sortByDesc('trips_count')->first();
        
        return response()->json([
            'success' => true,
            'data' => [
                'total_revenue' => $totalRevenue,
                'total_earning' => $totalEarning,
                'total_trips' => $totalTrips,
                'top_vehicle' => $topVehicle,
                'vehicles_stats' => $bookings
            ]
        ]);
    }
}
