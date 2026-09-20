<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// --- AUTH ---
use App\Http\Controllers\Api\V1\Auth\AuthController; // API đăng ký, đăng nhập, quên mật khẩu, reset mật khẩu, đổi mật khẩu, lấy thông tin user hiện tại

// --- WEB (Khách thuê / Chủ xe) ---
use App\Http\Controllers\Api\V1\Web\SearchController; // API tìm kiếm & lọc xe
use App\Http\Controllers\Api\V1\Web\VehicleController; // API chi tiết xe & khôi phục xe từ bảo trì
use App\Http\Controllers\Api\V1\Web\BookingController; // API đặt xe & tính giá
use App\Http\Controllers\Api\V1\Web\WalletController; // API ví tiền
use App\Http\Controllers\Api\V1\Web\BookingSurchargeController; // API phụ phí phát sinh trong chuyến
use App\Http\Controllers\Api\V1\Web\PaymentController; // API thanh toán & IPN VNPAY
use App\Http\Controllers\Api\V1\Web\CheckoutController; // API xử lý thanh toán khi đặt xe
use App\Http\Controllers\Api\V1\Web\ReviewController; // API đánh giá sau chuyến
use App\Http\Controllers\Api\V1\Web\CancelBookingController; // API hủy chuyến (cả renter và owner)
use App\Http\Controllers\Api\V1\Web\BookingEvidenceController; // API upload bằng chứng sự cố khi hủy chuyến
use App\Http\Controllers\Api\V1\Web\ProfileController; // API cập nhật thông tin cá nhân (tên, email, số điện thoại)
use App\Http\Controllers\Api\V1\Web\KycController as WebKycController; // Đổi tên import để không trùng với Admin
use App\Http\Controllers\Api\V1\Web\VoucherController; // API lấy danh sách mã giảm giá còn hiệu lực (dành cho khách thuê)
use App\Http\Controllers\Api\V1\Web\LocationController; // API proxy Google geocoding cho frontend
use App\Http\Controllers\Api\V1\Web\NotificationController; // API quản lý thông báo

// --- ADMIN (Điều phối viên / Quản trị viên) ---
use App\Http\Controllers\Api\V1\Admin\UserController; // API tạo tài khoản nhân viên
use App\Http\Controllers\Api\V1\Admin\AdminTicketController; // Controller mới quản lý vé sự cố (Ticket) từ điều phối viên thẩm định bằng chứng hủy chuyến
use App\Http\Controllers\Api\V1\Admin\KycApprovalController; // Controller mới duyệt eKYC
use App\Http\Controllers\Api\V1\Admin\CategoryController; // Controller quản lý danh mục xe (Car Category)
use App\Http\Controllers\Api\V1\Admin\FuelController; // Controller quản lý loại nhiên liệu (Fuel)
use App\Http\Controllers\Api\V1\Admin\TransmissionController; // Controller quản lý loại hộp số (Transmission)
use App\Http\Controllers\Api\V1\Admin\CarModelController; // Controller quản lý mẫu xe (Car Model)
use App\Http\Controllers\Api\V1\Admin\BookingManagementController; // Controller quản lý đơn đặt xe (Booking) từ Admin
use App\Http\Controllers\Api\V1\Admin\TransactionManagementController; // Controller quản lý nhật ký giao dịch (Transaction) từ Admin
use App\Http\Controllers\Api\V1\Admin\WithdrawalManagementController; // Controller quản lý yêu cầu rút tiền từ Admin
use App\Http\Controllers\Api\V1\Admin\AdminVehicleController; // Controller quản lý xe từ Admin (duyệt, khóa, mở khóa)
use App\Http\Controllers\Api\V1\Admin\AmenityController; // Controller quản lý tiện ích (Amenity)
use App\Http\Controllers\Api\V1\Admin\VoucherController as AdminVoucherController; // Controller quản lý mã giảm giá (Vouchers/Promotions) từ Admin


/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES (Không cần đăng nhập)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

/*
|--------------------------------------------------------------------------
| WEB API ROUTES (Dành cho khách hàng chưa đăng nhập / App chính)
|--------------------------------------------------------------------------
*/
Route::prefix('v1/web')->group(function () {
    // API tìm kiếm và reverse địa chỉ (Google Geocoding qua backend)
    Route::get('/locations/search', [LocationController::class, 'search']);
    Route::get('/locations/reverse', [LocationController::class, 'reverse']);

    // API Tìm kiếm & Lọc xe
    Route::get('/search/vehicles', [SearchController::class, 'index']);
    Route::get('/search/filter-options', [SearchController::class, 'getFilterOptions']);
    
    // API Lấy chi tiết xe
    Route::get('/vehicles/form-options', [VehicleController::class, 'getFormOptions']);
    
    Route::get('/vehicles/{id}', [VehicleController::class, 'show'])->whereNumber('id');

    // API Tính giá đặt xe (trước khi đặt)
    Route::post('/bookings/calculate-price', [BookingController::class, 'calculatePrice']);

    // API Nhận kết quả trả về từ VNPAY sau khi quẹt thẻ
    Route::get('/payments/vnpay-return', [PaymentController::class, 'vnpayReturn']);

    // API Nhận IPN từ VNPAY
    Route::get('/payments/vnpay-ipn', [PaymentController::class, 'vnpayIpn']);

    // ----- THÔNG TIN CÔNG KHAI CỦA NGƯỜI DÙNG -----
    Route::get('/users/{id}/public-profile', function ($id) {
        $user = \App\Models\User::select('id', 'name', 'avatar', 'created_at')->findOrFail($id);

        $completedTripsCount = \App\Models\Booking::where('renter_id', $user->id)
            ->where('status', 'completed')
            ->count();

        $reviewsReceived = \App\Models\Review::with('reviewer:id,name,avatar')
            ->where('reviewee_id', $user->id)
            ->latest()
            ->get();
            
        $avgRating = $reviewsReceived->avg('rating') ? round($reviewsReceived->avg('rating'), 1) : 0;
        $totalReviews = $reviewsReceived->count();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'completed_trips_count' => $completedTripsCount,
                'reviews_received' => $reviewsReceived,
                'avg_rating' => $avgRating,
                'total_reviews' => $totalReviews
            ]
        ]);
    });

    // ----- CẤU HÌNH HỆ THỐNG & TRANG TĨNH (PUBLIC) -----
    Route::get('/settings/public', [\App\Http\Controllers\Api\V1\Admin\SystemSettingController::class, 'publicIndex']);
    Route::get('/banners/public', [\App\Http\Controllers\Api\V1\Admin\BannerController::class, 'publicIndex']);
    Route::get('/pages/{slug}', [\App\Http\Controllers\Api\V1\Admin\StaticPageController::class, 'getBySlug']);
});

/*
|--------------------------------------------------------------------------
| PROTECTED AUTH ROUTES (Tài khoản - Bắt buộc phải có Token)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    
    // Lấy thông tin user hiện tại (kèm Role và Ví)
    Route::get('/me', function (Request $request) {
        $user = $request->user()->load(['roles', 'wallet']);
        
        // Dùng get() để lấy mảng TẤT CẢ tài liệu (gồm cả CCCD và GPLX)
        $allDocuments = \App\Models\LegalDocument::where('user_id', $user->id)->get();

        // Tính số chuyến đi (khách thuê) đã hoàn thành
        $completedTripsCount = \App\Models\Booking::where('renter_id', $user->id)
            ->where('status', 'completed')
            ->count();

        // Lấy các đánh giá user nhận được
        $reviewsReceived = \App\Models\Review::with('reviewer:id,name,avatar')
            ->where('reviewee_id', $user->id)
            ->latest()
            ->get();
        $avgRating = $reviewsReceived->avg('rating') ? round($reviewsReceived->avg('rating'), 1) : 0;
        $totalReviews = $reviewsReceived->count();

        // Gộp chung vào cục data trả về
        return response()->json([
            'success' => true,
            'data' => array_merge($user->toArray(), [
                'legal_documents' => $allDocuments,
                'completed_trips_count' => $completedTripsCount,
                'reviews_received' => $reviewsReceived,
                'avg_rating' => $avgRating,
                'total_reviews' => $totalReviews
            ])
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| PRIVATE WEB API (Khách thuê & Chủ xe - BẮT BUỘC ĐĂNG NHẬP)
|--------------------------------------------------------------------------
*/
Route::prefix('v1/web')->middleware('auth:sanctum')->group(function () {
    
    // ----- THÔNG TIN CÁ NHÂN -----
    Route::put('/profile/update', [ProfileController::class, 'update']);
    Route::post('/profile/update-avatar', [ProfileController::class, 'updateAvatar']);
    Route::delete('/profile/delete', [ProfileController::class, 'deleteAccount']);
    Route::post('/profile/upgrade-to-owner', [ProfileController::class, 'upgradeToOwner']);

    // ----- ĐỊNH DANH eKYC -----
    Route::post('/kyc/upload-id-card', [WebKycController::class, 'uploadIdCard']);
    Route::post('/kyc/upload-driver-license', [WebKycController::class, 'uploadDriverLicense']);
    Route::post('/kyc/update-driver-license-class', [WebKycController::class, 'updateDriverLicenseClass']);
    
    // ----- Đặt XE -----
    Route::get('/bookings/my-bookings', [BookingController::class, 'myBookings']);
    Route::get('/bookings/{id}', [BookingController::class, 'show']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::post('/bookings/{id}/approve', [BookingController::class, 'approve']);
    Route::post('/bookings/{id}/reject', [BookingController::class, 'reject']);
    Route::post('/bookings/{id}/pay', [\App\Http\Controllers\Api\V1\Web\PaymentController::class, 'processPayment']);
    
    // Lọc theo khoảng thời gian
    Route::get('/bookings/filter', [\App\Http\Controllers\Api\V1\Web\BookingController::class, 'filterByDateRange']);

    Route::get('/partner/requests', [BookingController::class, 'ownerRequests']);
    Route::get('/partner/bookings', [BookingController::class, 'ownerBookings']);
    Route::post('/partner/bookings/{id}/handover', [BookingController::class, 'handoverVehicle']);
    Route::post('/partner/bookings/{id}/complete', [BookingController::class, 'completeTrip']);

    // ----- PHỤ PHÍ PHÁT SINH & THANH TOÁN -----
    Route::post('/bookings/{id}/surcharges', [BookingSurchargeController::class, 'store']);
    Route::post('/bookings/{id}/checkout', [CheckoutController::class, 'processCheckout']);

    // ----- VÍ TIỀN -----
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::post('/wallet/deposit', [WalletController::class, 'deposit']);
    Route::post('/wallet/withdraw', [WalletController::class, 'withdraw']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactionHistory']);

    // ----- THÔNG BÁO -----
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::get('/wallet/withdrawals', [WalletController::class, 'withdrawalHistory']);

    // ----- KHIẾU NẠI / HỖ TRỢ -----
    Route::get('/tickets', [\App\Http\Controllers\Api\V1\Web\TicketController::class, 'index']);
    Route::post('/tickets', [\App\Http\Controllers\Api\V1\Web\TicketController::class, 'store']);

    // ----- ĐÁNH GIÁ & BẰNG CHỨNG -----
    Route::post('/bookings/{id}/reviews', [ReviewController::class, 'store']);
    Route::post('/bookings/{bookingId}/evidences', [BookingEvidenceController::class, 'store']);

    // ----- HỦY CHUYẾN & SỰ CỐ -----
    Route::post('/bookings/{id}/cancel', [CancelBookingController::class, 'cancelByRenter']);
    Route::post('/bookings/{id}/cancel-by-owner', [CancelBookingController::class, 'cancelByOwner']);
    Route::post('/vehicles/{id}/restore', [VehicleController::class, 'restoreFromMaintenance']);
    Route::post('/vehicles', [VehicleController::class, 'store']);

    // ----- KHUYẾN MÃI -----
    Route::get('/vouchers/available', [VoucherController::class, 'getAvailable']);
    
    // ----- XE YÊU THÍCH (FAVORITES / WISHLIST) -----
    Route::get('/favorites', [\App\Http\Controllers\Api\V1\Web\FavoriteController::class, 'index']);
    Route::get('/favorites/ids', [\App\Http\Controllers\Api\V1\Web\FavoriteController::class, 'ids']);
    Route::post('/favorites/{vehicleId}/toggle', [\App\Http\Controllers\Api\V1\Web\FavoriteController::class, 'toggle']);

    // ----- QUẢN LÝ XE CỦA CHỦ XE -----
    Route::get('/partner/vehicles', [VehicleController::class, 'myVehicles']);
    Route::put('/partner/vehicles/{id}', [VehicleController::class, 'update']);
    Route::get('/partner/vehicles/{id}/busy-dates', [VehicleController::class, 'getBusyDates']);
    Route::post('/partner/vehicles/{id}/busy-dates', [VehicleController::class, 'addBusyDates']);
    Route::delete('/partner/vehicles/{id}/busy-dates', [VehicleController::class, 'removeBusyDate']);
    
    // Thống kê chủ xe
    Route::get('/partner/dashboard-stats', [VehicleController::class, 'getOwnerStats']);
});

/*
|--------------------------------------------------------------------------
| NHÓM API CMS (Dành cho Admin / Staff / Coordinator)
|--------------------------------------------------------------------------
*/
Route::prefix('v1/admin')->middleware('auth:sanctum')->group(function () {
    
    // ----- QUẢN LÝ TÀI KHOẢN -----
    // API Thống kê Dashboard
    Route::get('/dashboard/summary', [App\Http\Controllers\Api\V1\Admin\DashboardController::class, 'getSummary']);
    Route::get('/dashboard/chart', [App\Http\Controllers\Api\V1\Admin\DashboardController::class, 'getChartData']);
    Route::get('/dashboard/vehicle-stats', [App\Http\Controllers\Api\V1\Admin\DashboardController::class, 'getVehicleStats']);
    Route::get('/dashboard/export', [App\Http\Controllers\Api\V1\Admin\DashboardController::class, 'exportReport']);

    // API quản lý người dùng
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);

    // API tạo tài khoản nhân viên
    Route::get('/staff/roles', [UserController::class, 'getAssignableRoles']);
    Route::post('/staff/create', [UserController::class, 'createStaff']);
    
    // ----- QUẢN LÝ THÔNG SỐ XE (Car Category) -----
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('fuels', FuelController::class);
    Route::apiResource('transmissions', TransmissionController::class);
    Route::get('car-models/unique-brands', [CarModelController::class, 'getUniqueBrands']);
    Route::apiResource('car-models', CarModelController::class);
    
    // Quản lý Tiện ích
    Route::get('/amenity-types', [AmenityController::class, 'getTypes']);
    Route::post('/amenity-types', [AmenityController::class, 'storeType']);
    Route::put('/amenity-types/{id}', [AmenityController::class, 'updateType']);
    Route::delete('/amenity-types/{id}', [AmenityController::class, 'destroyType']);

    Route::apiResource('/amenities', AmenityController::class);

    // ----- QUẢN LÝ DUYỆT HỒ SƠ eKYC -----
    Route::get('/kyc-approvals', [KycApprovalController::class, 'index']);           // Lấy danh sách hồ sơ chờ duyệt
    Route::get('/kyc-approvals/{id}', [KycApprovalController::class, 'show']);       // Lấy chi tiết ảnh và Text OCR
    Route::post('/kyc-approvals/{id}/process', [KycApprovalController::class, 'processKyc']);
    
    // ----- QUẢN LÝ VÉ SỰ CỐ (TICKETS) -----
    Route::get('/tickets', [AdminTicketController::class, 'index']);
    Route::get('/tickets/{id}', [AdminTicketController::class, 'show']);
    Route::put('/tickets/{id}/status', [AdminTicketController::class, 'updateStatus']);
    // Điều phối viên thẩm định bằng chứng sự cố từ Ticket
    Route::post('/tickets/{id}/resolve-incident', [AdminTicketController::class, 'resolveCancelIncident']);

    // ----- QUẢN LÝ RÚT TIỀN (WITHDRAWALS) -----
    Route::get('/withdrawals', [WithdrawalManagementController::class, 'index']);
    Route::post('/withdrawals/{id}/approve', [WithdrawalManagementController::class, 'approve']);
    Route::post('/withdrawals/{id}/reject', [WithdrawalManagementController::class, 'reject']);

    // ----- QUẢN LÝ ĐƠN ĐẶT XE (BOOKING) -----
    Route::get('/bookings', [BookingManagementController::class, 'index']);
    Route::put('/bookings/{id}/status', [BookingManagementController::class, 'updateStatus']);
    Route::get('/bookings/{id}', [BookingManagementController::class, 'show']);

    // ----- QUẢN LÝ NHẬT KÝ GIAO DỊCH (TRANSACTION) -----
    Route::get('/transactions', [TransactionManagementController::class, 'index']);

    // ----- QUẢN LÝ VÍ ĐIỆN TỬ (WALLET) -----
    Route::patch('/wallets/freeze', [App\Http\Controllers\Api\V1\Admin\WalletManagementController::class, 'freeze']);
    Route::patch('/wallets/unfreeze', [App\Http\Controllers\Api\V1\Admin\WalletManagementController::class, 'unfreeze']);

    // ----- QUẢN LÝ XE (ADMIN) -----
    Route::get('/vehicles', [AdminVehicleController::class, 'index']);
    Route::get('/vehicles/{id}', [AdminVehicleController::class, 'show']);
    Route::put('/vehicles/{id}/approve', [AdminVehicleController::class, 'approve']);
    Route::put('/vehicles/{id}/reject', [AdminVehicleController::class, 'reject']);
    Route::put('/vehicles/{id}/lock', [AdminVehicleController::class, 'lock']);
    Route::put('/vehicles/{id}/unlock', [AdminVehicleController::class, 'unlock']);

    // ----- QUẢN LÝ MÃ GIẢM GIÁ & KHUYẾN MÃI (PROMOTIONS / VOUCHERS) -----
    Route::apiResource('vouchers', AdminVoucherController::class);

    // ----- QUẢN LÝ CẤU HÌNH & HỆ THỐNG -----
    Route::post('system-settings/batch', [\App\Http\Controllers\Api\V1\Admin\SystemSettingController::class, 'batchUpdate']);
    Route::apiResource('system-settings', \App\Http\Controllers\Api\V1\Admin\SystemSettingController::class);
    Route::apiResource('banners', \App\Http\Controllers\Api\V1\Admin\BannerController::class);
    Route::apiResource('static-pages', \App\Http\Controllers\Api\V1\Admin\StaticPageController::class);
});