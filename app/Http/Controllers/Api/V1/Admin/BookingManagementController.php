<?php

namespace App\Http\Controllers\Api\V1\Admin;

use Illuminate\Http\Request;
use App\Models\Booking;

/**
 * ============================================================================
 * LỚP BOOKING MANAGEMENT CONTROLLER (QUẢN CHỊU & ĐIỀU PHỐI ĐƠN HÀNG TOÀN SÀN)
 * ============================================================================
 * Controller nghiệp vụ phụ trách phân hệ Giám sát Chuyến đi dành cho Quản trị viên
 * (CMS Admin), hỗ trợ tra cứu toàn cục lịch sử đặt xe, bộ lọc trạng thái phong phú
 * và đặc quyền can thiệp, điều chỉnh trạng thái các đơn hàng khi xảy ra tình huống đặc biệt.
 */
class BookingManagementController
{
    /**
     * ========================================================================
     * 1. HÀM DANH SÁCH: TRA CỨU TỔNG HỢP TOÀN BỘ ĐƠN HÀNG TRÊN NỀN TẢNG
     * ========================================================================
     * Cung cấp dữ liệu lịch sử đặt xe cho màn hình quản lý tập trung với tính năng:
     * - Tải trước dữ liệu Khách thuê (renter) và Dòng xe (carModel) để tránh lỗi N+1 Query.
     * - Bộ lọc thông minh: Theo trạng thái đơn hàng ('all', 'confirmed'...) hoặc Ngày đặt xe.
     * - Tìm kiếm nâng cao: Khớp theo Mã đơn (#DX1, DX1) hoặc từ khóa định dạng Thông tin khách hàng.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số tra cứu, tra cứu ngày và trạng thái.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON danh sách phân trang (15 bản ghi/trang).
     */
    public function index(Request $request)
    {
        $query = Booking::with(['vehicle.carModel', 'renter']);

        // Chức năng lọc theo trạng thái (nếu có tham số hợp lệ truyền lên)
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Tìm kiếm đa dạng theo mã đơn, hoặc liên hệ trực tiếp của người thuê
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                // Hỗ trợ bóc tách cú pháp tìm mã đơn: DX1, #DX1, dx1 hoặc thuần chữ số
                if (preg_match('/^#?dx(\d+)$/i', trim($search), $matches)) {
                    $q->where('id', $matches[1]);
                } else if (is_numeric(trim($search))) {
                    $q->where('id', trim($search));
                } else {
                    $q->whereHas('renter', function ($userQ) use ($search) {
                        $userQ->where('name', 'ILIKE', "%{$search}%")
                              ->orWhere('email', 'ILIKE', "%{$search}%")
                              ->orWhere('phone', 'ILIKE', "%{$search}%");
                    });
                }
            });
        }

        // Lọc tập trung theo ngày khởi tạo Đơn đặt xe (created_at)
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        }

        // Sắp xếp đơn đặt mới nhất lên hàng đầu tiên và thực thi phân trang (15 dòng/trang)
        $bookings = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $bookings
        ]);
    }

    /**
     * ========================================================================
     * 2. HÀM CẬP NHẬT TRẠNG THÁI: CAN THIỆP THỦ CÔNG TIẾN TRÌNH CHUYẾN ĐI
     * ========================================================================
     * Đặc quyền hỗ trợ Quản trị viên thay đổi trực tiếp trạng thái chuyến đi trong
     * các tình huống khẩn cấp (khắc phục sự cố, hoàn trả tiền hoặc giải phẫu khiếu nại),
     * đồng thời tự động đồng bộ trả lại lịch available cho xe nếu kết thúc đơn.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu trạng thái mục tiêu cần chuyển.
     * @param  int                       $id       ID của đơn đặt xe cần xử lý.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON thông báo kết quả cập nhật.
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending_approval,pending_payment,confirmed,in_progress,completed,cancelled'
        ]);

        $booking = Booking::find($id);

        if (!$booking) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đơn hàng'], 404);
        }

        $booking->status = $request->status;
        $booking->save();

        // Tự động giải tỏa trạng thái bận của xe nếu Admin can thiệp chỉnh sang Hoàn thành
        if ($request->status === 'completed' && $booking->vehicle) {
            $booking->vehicle->status = 'available';
            $booking->vehicle->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật trạng thái thành công'
        ]);
    }

    /**
     * ========================================================================
     * 3. HÀM CHI TIẾT ĐƠN: THẨM ĐỊNH HỒ SƠ CHUYẾN ĐI DÀNH CHO ADMIN
     * ========================================================================
     * Truy xuất toàn vẹn hồ sơ và các quan hệ mật thiết nhất của một chuyến đi:
     * Thông tin Khách hàng, Thông số Kỹ thuật & Tên dòng xe, và tải đặc thù Ảnh bìa
     * (Thumbnail) của phương tiện phục vụ kiểm chứng giao diện CMS.
     *
     * @param  int                       $id  ID của đơn đặt xe.
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON chứa toàn bô thông tin đơn.
     */
    public function show($id)
    {
        $booking = \App\Models\Booking::with([
            'renter',            // Kéo chi tiết tài khoản Khách hàng (User)
            'vehicle.carModel',  // Kéo thuộc tính phương tiện và dòng xe tương ứng
            // Tối ưu hóa truy vấn: Chỉ tải ảnh đóng vai trò làm Thumbnail cho biểu mẫu
            'vehicle.images' => function($q) {
                $q->where('is_thumbnail', true);
            }
        ])->find($id);

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đơn đặt xe này.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $booking
        ]);
    }
}