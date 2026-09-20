<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * LỚP DASHBOARD CONTROLLER (HỆ THỐNG THỐNG KÊ BIỂU ĐỒ & CHỈ SỐ KPI TOÀN SÀN)
 * ============================================================================
 * Controller phân hệ Quản trị (Admin CMS) chịu trách nhiệm tính toán và trực quan
 * hóa dữ liệu điều hành chiến lược của nền tảng: Tổng doanh thu (GMV), chi phí trả
 * Chủ xe (70%), lợi nhuận ròng (30%), Quỹ bảo hiểm rủi ro, phân tích tỷ lệ duy trì
 * khách hàng cũ/mới và cung cấp tập dữ liệu vẽ biểu đồ chuỗi thời gian hàng tháng.
 */
class DashboardController extends Controller
{
    /**
     * ========================================================================
     * 1. HÀM CHỈ SỐ TỔNG QUAN (KPIS): TÍNH TOÁN THỐNG KÊ V TRẠNG THÁI TOÀN SÀN
     * ========================================================================
     * Thực hiện chuỗi truy vấn phân tích chuyên sâu nhằm tổng hợp các thông số trọng yếu:
     * - Tài chính: GMV (đơn hoàn thành), Chi phí cho Đối tác (70%), Tiền tạm giữ Invoice Due,
     *   Lợi nhuận ròng của Sàn (30%), và cân đối Quỹ Bảo Hiểm Chuyến Đi (Thu - Chi).
     * - Tăng trưởng: Khối lượng hoa hồng tháng hiện tại so sánh tương quan với tháng liền trước.
     * - Người dùng: Tỷ lệ Khách hàng đặt mua lần đầu vs Khách quay lại (Retention metrics).
     * - Hàng đợi nghiệp vụ: 5 phương tiện sinh lời cao nhất, xe đang chờ thẩm định và đơn mới nhất.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số bộ lọc kỳ hạn từ giao diện (nếu có).
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON tổng thể bộ KPI điều hành.
     */
    public function getSummary(Request $request)
    {
        // 1. Tổng doanh thu (GMV) của các chuyến đi đã hoàn tất xuất sắc
        $totalSales = Booking::where('status', 'completed')->sum('total_amount');
        
        // 2. Tổng số chuyến đi từng được tạo trên hệ thống
        $totalBookings = Booking::count();
        
        // 3. Tổng chi phí / Tiền đã thanh toán cho Chủ xe (70% của các đơn hoàn thành TRẢ ĐỦ FULL)
        $totalExpenses = Booking::where('status', 'completed')->where('payment_option', 'full')->sum(DB::raw('total_amount * 0.7'));
        
        // 4. Invoice Due (Tiền Tạm Giữ) - 70% của các đơn đang vận hành (chưa hoàn thành) CHỈ VỚI THANH TOÁN FULL
        $invoiceDue = Booking::whereIn('status', ['pending', 'confirmed', 'in_progress'])->where('payment_option', 'full')->sum(DB::raw('total_amount * 0.7'));

        // 5. Total Profit (Lợi nhuận ròng 30% thuộc về Nền tảng)
        $totalProfit = Booking::where('status', 'completed')->sum(DB::raw('total_amount * 0.3'));
        
        // 6. Quỹ Bảo Hiểm Chuyến Đi (Sàn thu phí bảo hiểm trên mỗi chuyến đi để tự chủ bảo lãnh rủi ro)
        $totalInsuranceCollected = \App\Models\BookingService::where('service_name', 'LIKE', '%Bảo hiểm%')
            ->whereHas('booking', function($query) {
                $query->whereNotIn('status', ['cancelled', 'pending']);
            })->sum('price');
            
        $totalInsurancePaidOut = \App\Models\Transaction::where('description', 'LIKE', '%từ Quỹ Bảo Hiểm%')
            ->sum('amount');
            
        $totalInsuranceFund = $totalInsuranceCollected - $totalInsurancePaidOut;
        
        // Tỷ lệ tăng trưởng (Growth Rate) lợi nhuận ròng tháng hiện tại so với kỳ tháng liền trước
        $currentMonthProfit = Booking::where('status', 'completed')->whereMonth('created_at', Carbon::now()->month)->sum(DB::raw('total_amount * 0.3'));
        $lastMonthProfit = Booking::where('status', 'completed')->whereMonth('created_at', Carbon::now()->subMonth()->month)->sum(DB::raw('total_amount * 0.3'));
        $profitGrowth = $lastMonthProfit > 0 ? round((($currentMonthProfit - $lastMonthProfit) / $lastMonthProfit) * 100, 1) : 0;

        // --- CÁC CHỈ SỐ KHÁCH HÀNG & MỞ RỘNG (NEW STATS) ---
        // 6b. Total Payment Returns (Tổng lượng tài chính đã hoàn trả cho khách hàng do gián đoạn)
        $totalReturns = Transaction::where('type', 'credit')
            ->where('status', 'success')
            ->where('description', 'LIKE', '%Hoàn tiền%')
            ->sum('amount');
            
        // 7. Customers Overview (Khách mới trải nghiệm lần đầu vs Khách trung thành quay lại)
        $renterTripCounts = Booking::where('status', 'completed')
            ->select('renter_id', DB::raw('count(*) as total'))
            ->groupBy('renter_id')
            ->pluck('total');

        $firstTimeCount = $renterTripCounts->filter(fn($count) => $count == 1)->count();
        $returnCount = $renterTripCounts->filter(fn($count) => $count >= 2)->count();

        // 8. Thống kê tỷ trọng nhóm Người dùng trên toàn tài khoản sàn
        $supplierCount = User::whereHas('roles', function($q) {
            $q->whereIn('slug', ['owner', 'partner']);
        })->count();

        $customerCount = User::whereHas('roles', function($q) {
            $q->where('slug', 'renter');
        })->count();

        // 9. Top Vehicles (5 phương tiện hoạt động hiệu quả nhất dựa trên số đơn thành công)
        $topVehicles = Vehicle::with(['carModel.category', 'images' => function($q) {
            $q->where('is_thumbnail', true);
        }])
        ->withCount(['bookings' => function($q) {
            $q->where('status', 'completed');
        }])
        ->orderByDesc('bookings_count')
        ->limit(5)
        ->get()
        ->map(function($vehicle) {
            return [
                'id' => $vehicle->id,
                'name' => $vehicle->carModel ? ($vehicle->carModel->brand_name . ' ' . $vehicle->carModel->model_name) : 'Xe không xác định',
                'category' => $vehicle->carModel && $vehicle->carModel->category ? $vehicle->carModel->category->name : 'N/A',
                'base_price' => $vehicle->base_price,
                'total_trips' => $vehicle->bookings_count,
                'thumbnail' => $vehicle->images->first()->image_url ?? '/img/car-default.png'
            ];
        });

        // 10. Low Stock / Hàng đợi kiểm duyệt (5 phương tiện ở trạng thái pending chờ Admin bóc tách và duyệt)
        $pendingVehicles = Vehicle::with(['carModel', 'images' => function($q) {
            $q->where('is_thumbnail', true);
        }])
        ->where('status', 'pending')
        ->orderByDesc('created_at')
        ->limit(5)
        ->get()
        ->map(function($vehicle) {
            return [
                'id' => $vehicle->id,
                'name' => $vehicle->carModel ? ($vehicle->carModel->brand_name . ' ' . $vehicle->carModel->model_name) : 'Xe không xác định',
                'thumbnail' => $vehicle->images->first()->image_url ?? '/img/car-default.png',
                'status' => $vehicle->status,
                'created_at' => $vehicle->created_at ? $vehicle->created_at->format('Y-m-d') : null
            ];
        });

        // 11. Recent Sales (5 giao dịch đặt xe gần nhất vừa được ghi nhận trên sàn)
        $recentBookings = Booking::with(['vehicle.carModel', 'vehicle.images' => function($q) {
            $q->where('is_thumbnail', true);
        }])
        ->orderByDesc('created_at')
        ->limit(5)
        ->get()
        ->map(function($booking) {
            $vehicle = $booking->vehicle;
            return [
                'id' => $booking->id,
                'vehicle_name' => $vehicle && $vehicle->carModel ? ($vehicle->carModel->brand_name . ' ' . $vehicle->carModel->model_name) : 'Xe không xác định',
                'thumbnail' => $vehicle && $vehicle->images->first() ? $vehicle->images->first()->image_url : '/img/car-default.png',
                'total_amount' => $booking->total_amount,
                'status' => $booking->status,
                'created_at' => $booking->created_at ? $booking->created_at->format('Y-m-d') : null
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'total_sales' => round($totalSales),
                'total_bookings' => $totalBookings,
                'total_expenses' => round($totalExpenses),
                'invoice_due' => round($invoiceDue),
                'total_profit' => round($totalProfit),
                'total_returns' => round($totalReturns),
                'total_insurance_fund' => round($totalInsuranceFund),
                'growth' => [
                    'profit_vs_last_month' => $profitGrowth
                ],
                'customers_overview' => [
                    'first_time' => $firstTimeCount,
                    'return' => $returnCount
                ],
                'user_stats' => [
                    'suppliers' => $supplierCount,
                    'customers' => $customerCount,
                    'orders' => $totalBookings
                ],
                'top_vehicles' => $topVehicles,
                'pending_vehicles' => $pendingVehicles,
                'recent_bookings' => $recentBookings
            ]
        ]);
    }

    /**
     * ========================================================================
     * 2. HÀM BIỂU ĐỒ TÀI CHÍNH (CHART DATA): CHUỖI THỜI GIAN THEO TỪNG THÁNG
     * ========================================================================
     * Truy xuất và gộp dữ liệu tài chính (GMV và Lợi nhuận) theo mốc từng tháng
     * trong một năm dương lịch được chỉ định. Sử dụng câu lệnh EXTRACT(MONTH) chuẩn
     * PostgreSQL để bóc tách mốc thời gian và lấp đầy dải mảng chuỗi 12 tháng.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số năm cần xem (year), mặc định năm nay.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON cấu trúc 2 chuỗi series biểu đồ.
     */
    public function getChartData(Request $request)
    {
        $year = $request->input('year', Carbon::now()->year);

        // Lấy tổng GMV (Sales) và tổng lợi nhuận (Profit) nhóm theo từng tháng
        $monthlyStats = Booking::select(
                DB::raw('EXTRACT(MONTH FROM created_at) as month'),
                DB::raw('SUM(total_amount) as sales'),
                DB::raw('SUM(total_amount * 0.3) as profit')
            )
            ->where('status', 'completed')
            ->whereYear('created_at', $year)
            ->groupBy(DB::raw('EXTRACT(MONTH FROM created_at)'))
            ->orderBy('month')
            ->get();

        $salesSeries = array_fill(0, 12, 0);
        $profitSeries = array_fill(0, 12, 0);

        foreach ($monthlyStats as $item) {
            $monthIndex = (int) $item->month - 1; // Khớp chỉ mục mảng: 1 (Tháng 1) -> 0
            $salesSeries[$monthIndex] = (float) $item->sales;
            $profitSeries[$monthIndex] = (float) $item->profit;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'series' => [
                    [
                        'name' => 'Doanh Thu Chuyến (GMV)',
                        'data' => $salesSeries
                    ],
                    [
                        'name' => 'Lợi Nhuận Nền Tảng (30%)',
                        'data' => $profitSeries
                    ]
                ]
            ]
        ]);
    }

    /**
     * ========================================================================
     * 3. HÀM XUẤT BÁO CÁO (EXPORT REPORT): XUẤT DOANH THU SANG FILE CSV
     * ========================================================================
     * Stream trực tiếp dữ liệu danh sách giao dịch từ DB thành file CSV để 
     * giảm tải bộ nhớ thay vì tải toàn bộ record vào RAM, đáp ứng đúng 
     * yêu cầu của tài liệu đồ án (định dạng CSV).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportReport(Request $request)
    {
        $year = $request->input('year', Carbon::now()->year);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"Bao_Cao_Doanh_Thu_$year.csv\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($year) {
            $file = fopen('php://output', 'w');
            
            // Add BOM to fix UTF-8 in Excel
            fputs($file, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

            // Ghi dòng tiêu đề
            fputcsv($file, [
                'Mã Đơn',
                'Tên Khách Hàng',
                'Tên Xe',
                'Biển Số',
                'Ngày Hoàn Thành',
                'Giá Trị Đơn (GMV)',
                'Hoa Hồng Sàn (30%)',
                'Chi Trả Chủ Xe (70%)'
            ]);

            // Dùng cursor() hoặc chunk() để tối ưu RAM cho tập dữ liệu cực lớn
            Booking::with(['vehicle.carModel', 'renter'])
                ->where('status', 'completed')
                ->whereYear('created_at', $year)
                ->orderBy('created_at', 'desc')
                ->chunk(500, function ($bookings) use ($file) {
                    foreach ($bookings as $booking) {
                        $vehicleName = $booking->vehicle && $booking->vehicle->carModel 
                            ? $booking->vehicle->carModel->brand_name . ' ' . $booking->vehicle->carModel->model_name 
                            : 'Xe không xác định';
                            
                        $licensePlate = $booking->vehicle ? $booking->vehicle->license_plate : 'N/A';
                        $renterName = $booking->renter ? $booking->renter->name : 'N/A';
                        $completedAt = $booking->created_at ? $booking->created_at->format('d/m/Y H:i') : '';
                        
                        $gmv = $booking->total_amount;
                        $commission = $gmv * 0.3;
                        $payout = $gmv * 0.7;

                        fputcsv($file, [
                            '#' . $booking->id,
                            $renterName,
                            $vehicleName,
                            $licensePlate,
                            $completedAt,
                            $gmv,
                            $commission,
                            $payout
                        ]);
                    }
                });

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
    /**
     * ========================================================================
     * 4. HÀM THỐNG KÊ CHI TIẾT LOẠI XE (VEHICLE CATEGORY STATS)
     * ========================================================================
     * Phân tích sâu về doanh thu, lợi nhuận và số đơn theo từng loại xe (4 chỗ, 7 chỗ...)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVehicleStats(Request $request)
    {
        $year = $request->input('year', Carbon::now()->year);

        // 1. Thống kê theo danh mục xe (Category)
        $categoryStats = DB::table('bookings')
            ->join('vehicles', 'bookings.vehicle_id', '=', 'vehicles.id')
            ->join('car_models', 'vehicles.car_model_id', '=', 'car_models.id')
            ->join('categories', 'car_models.category_id', '=', 'categories.id')
            ->where('bookings.status', 'completed')
            ->whereYear('bookings.created_at', $year)
            ->select(
                'categories.name as category_name',
                DB::raw('COUNT(bookings.id) as total_trips'),
                DB::raw('SUM(bookings.total_amount) as total_revenue'),
                DB::raw('SUM(bookings.total_amount * 0.3) as total_profit')
            )
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_revenue')
            ->get();

        // 2. Xe được thuê nhiều nhất và ít nhất
        $vehiclePerformance = DB::table('bookings')
            ->join('vehicles', 'bookings.vehicle_id', '=', 'vehicles.id')
            ->join('car_models', 'vehicles.car_model_id', '=', 'car_models.id')
            ->where('bookings.status', 'completed')
            ->whereYear('bookings.created_at', $year)
            ->select(
                'vehicles.id',
                DB::raw('CONCAT(car_models.brand_name, " ", car_models.model_name) as name'),
                'vehicles.license_plate',
                DB::raw('COUNT(bookings.id) as total_trips'),
                DB::raw('SUM(bookings.total_amount) as total_revenue'),
                DB::raw('SUM(bookings.total_amount * 0.3) as total_profit')
            )
            ->groupBy('vehicles.id', 'car_models.brand_name', 'car_models.model_name', 'vehicles.license_plate')
            ->get();

        $mostRented = $vehiclePerformance->sortByDesc('total_trips')->take(5)->values();
        $leastRented = $vehiclePerformance->sortBy('total_trips')->take(5)->values();
        $highestProfit = $vehiclePerformance->sortByDesc('total_profit')->take(5)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'categories' => $categoryStats,
                'most_rented' => $mostRented,
                'least_rented' => $leastRented,
                'highest_profit' => $highestProfit
            ]
        ]);
    }
}
