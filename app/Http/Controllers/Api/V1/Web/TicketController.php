<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & CÁC TRỤ TRỢ GIÚP (IMPORTS)
// ============================================================================
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Ticket;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BỘ ĐIỀU KHIỂN TIẾP NHẬN KHIẾU NẠI & YÊU CẦU HỢP TƯ NGƯỜI DÙNG (TICKET CONTROLLER)
 * Chuyên trách quản lý quy trình gửi Đơn khiếu nại (Tickets/Issues), tích hợp upload hình ảnh 
 * chứng cứ lên hệ thống lưu trữ đám mây Cloudinary và đồng bộ về Cổng giám định Admin.
 */
class TicketController extends Controller
{
    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC TRA CỨU & KHỞI TẠO KHIẾU NẠI (TICKET ACTIONS)
    // ============================================================================

    /**
     * Lấy danh sách lịch sử khiếu nại của người dùng hiện tại
     * 
     * @param Request $request Yêu cầu HTTP
     * @return \Illuminate\Http\JsonResponse Danh sách khiếu nại kèm hồ sơ Xe liên quan
     */
    public function index(Request $request)
    {
        // Tải trước (Eager load) thông tin xe trong đơn đặt thuê để tiết kiệm số lượng Query
        $tickets = Ticket::with(['booking.vehicle'])
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tickets
        ]);
    }

    /**
     * Tạo khiếu nại / ticket hỗ trợ mới
     *
     * Quy trình xử lý:
     * - Kiểm tra trường dữ liệu (tiêu đề, nội dung, mảng tệp ảnh chứng cứ tối đa 5MB/tệp).
     * - Thẩm định sự liên đới: Nếu gắn kèm mã Đơn thuê xe (`booking_id`), người khiếu nại buội phải là Renter hoặc Owner của đơn đó.
     * - Đăng tải tuần tự ảnh lên kho lưu trữ trực tuyến Cloudinary (`tickets_evidences`).
     * - Khởi tạo hồ sơ khiếu nại trong Transaction an toàn với mức độ ưu tiên mặc định 'high'.
     *
     * @param Request $request Yêu cầu HTTP chứa payload Form Data (subject, content, evidences[])
     * @return \Illuminate\Http\JsonResponse Kết quả gửi đơn hỗ trợ
     */
    public function store(Request $request)
    {
        // [Bước 1]: Validate ràng buộc cú pháp và giới hạn kích thước ảnh tải lên
        $request->validate([
            'subject' => 'required|string|max:255',
            'content' => 'required|string',
            'booking_id' => 'nullable|exists:bookings,id',
            'evidences' => 'nullable|array',
            'evidences.*' => 'image|mimes:jpeg,png,jpg,gif,webp,bmp,svg,avif|max:10240' // Max 10MB cho mỗi file đính kèm
        ], [
            'evidences.*.image' => 'Tệp minh chứng phải là định dạng hình ảnh (jpg, png, webp, gif...).',
            'evidences.*.mimes' => 'Hình ảnh tải lên phải thuộc định dạng: jpeg, png, jpg, gif, webp, bmp, svg hoặc avif.',
            'evidences.*.max' => 'Kích thước mỗi ảnh không được vượt quá 10MB.',
            'subject.required' => 'Vui lòng nhập tiêu đề khiếu nại.',
            'content.required' => 'Vui lòng nhập nội dung mô tả sự cố.'
        ]);

        try {
            // Bắt đầu Giao dịch CSDL để phòng ngừa lỗi ghi lửng khi lỗi mạng hoặc Cloudinary từ chối
            DB::beginTransaction();
            $user = $request->user();

            // [Bước 2]: Thẩm định quyền khiếu nại đối với Đơn xe (Nếu có đính kèm booking_id)
            if ($request->booking_id) {
                $booking = Booking::with('vehicle')->find($request->booking_id);
                if ($booking->renter_id !== $user->id && $booking->vehicle->owner_id !== $user->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bạn không có quyền khiếu nại chuyến đi này'
                    ], 403);
                }
            }

            // [Bước 3]: Ký gửi và lưu trữ ảnh minh chứng vào thư mục lưu trữ public
            $attachmentUrls = [];
            if ($request->hasFile('evidences')) {
                foreach ($request->file('evidences') as $file) {
                    $path = $file->store('tickets_evidences', 'public');
                    $attachmentUrls[] = '/storage/' . $path;
                }
            }

            // [Bước 4]: Tạo bản ghi Khiếu nại (Ticket) vào hệ thống
            // Giả sử ticket_category_id = 1 (Phân loại: Khiếu nại tranh chấp chuyến đi)
            $ticket = Ticket::create([
                'user_id' => $user->id,
                'booking_id' => $request->booking_id,
                'ticket_category_id' => 1, 
                'subject' => $request->subject,
                'content' => $request->content,
                'attachments' => $attachmentUrls,
                'status' => 'open',
                'priority' => 'high'
            ]);

            // [Bước 5]: Chốt ghi nhận dữ liệu hợp lệ vào CSDL
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tạo khiếu nại thành công! Admin sẽ sớm phản hồi cho bạn.',
                'data' => $ticket
            ]);

        } catch (\Exception $e) {
            // Hủy giao dịch nếu phát sinh trục trặc kỹ thuật
            DB::rollBack();
            Log::error('Lỗi khi tạo ticket: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống: ' . $e->getMessage()
            ], 500);
        }
    }
}
