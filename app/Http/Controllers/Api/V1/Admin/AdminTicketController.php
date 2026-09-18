<?php

namespace App\Http\Controllers\Api\V1\Admin;

use Illuminate\Http\Request;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

/**
 * ============================================================================
 * LỚP ADMIN TICKET CONTROLLER (QUẢN LÝ KHIẾU NẠI & PHÂN DUNG TÀI CHÍNH)
 * ============================================================================
 * Controller thuộc phân hệ Quản trị viên (CMS Admin), chịu trách nhiệm rà soát
 * toàn diện các yêu cầu hỗ trợ, khiếu nại và đặc biệt là phân xử minh chứng
 * hủy chuyến từ Chủ xe (thẩm định gian lận, quyết định xử phạt điểm uy tín & trừ ví).
 */
class AdminTicketController
{
    /**
     * ========================================================================
     * 1. HÀM DANH SÁCH: TRA CỨU VÀ LỌC DANH BẠ YÊU CẦU KHIẾU NẠI (TICKETS)
     * ========================================================================
     * Cung cấp danh sách các vé khiếu nại (Ticket) trong hệ thống với hỗ trợ:
     * - Phân trang theo tham số tùy biến (mặc định 10 bản ghi).
     * - Bộ lọc linh hoạt: Trạng thái xử lý ('open', 'closed') và Vai trò ('owner', 'renter').
     * - Tìm kiếm từ khóa nâng cao theo Mã vé (#KN1), Tiêu đề, Nội dung hoặc Thông tin liên hệ.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số bộ lọc, tìm kiếm và phân trang.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON chứa mảng dữ liệu vé khiếu nại.
     */
    public function index(Request $request)
    {
        $query = Ticket::with(['user.roles', 'booking'])
            ->orderByDesc('updated_at');

        // Lọc theo điều kiện Trạng thái vé hỗ trợ
        if ($request->filled('status') && $request->status !== 'all') {
            $status = $request->status;
            if ($status === 'open') {
                $query->whereIn('status', ['new', 'open']);
            } elseif ($status === 'closed') {
                $query->whereIn('status', ['resolved', 'closed']);
            } else {
                $query->where('status', $status);
            }
        }

        // Lọc theo vai trò của tài khoản tạo vé
        if ($request->filled('role') && $request->role !== 'all') {
            $role = $request->role;
            if ($role === 'owner') {
                $query->whereHas('user.roles', fn($q) => $q->where('slug', 'owner'));
            }
            if ($role === 'renter') {
                $query->whereHas('user.roles', fn($q) => $q->where('slug', 'renter'));
            }
        }

        // Thực hiện tìm kiếm theo các tiêu chí đa năng
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                // Hỗ trợ tìm chính xác theo mã Yêu cầu (ví dụ: #KN1, KN1, kn1, KN12)
                if (preg_match('/^#?kn(\d+)$/i', trim($search), $matches)) {
                    $q->where('id', $matches[1]);
                } else {
                    $q->where('subject', 'ILIKE', "%{$search}%")
                        ->orWhere('content', 'ILIKE', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'ILIKE', "%{$search}%")
                                ->orWhere('email', 'ILIKE', "%{$search}%")
                                ->orWhere('phone', 'ILIKE', "%{$search}%");
                        });
                }
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate((int) $request->input('per_page', 10)),
        ]);
    }

    /**
     * ========================================================================
     * 2. HÀM CHI TIẾT: XEM THÔNG BÁO, BẰNG CHỨNG HỒ SƠ 1 VÉ KHIẾU NẠI
     * ========================================================================
     * Tải trọn bộ liên kết thông tin: Tài khoản tạo vé, Đơn đặt xe tranh chấp,
     * Khách hàng, Chủ xe và Phương tiện, hỗ trợ Admin thẩm định hồ sơ chính xác.
     *
     * @param  int                       $id  ID của vé hỗ trợ (Ticket).
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON chi tiết toàn diện của vé.
     */
    public function show($id)
    {
        $ticket = Ticket::with([
            'user',
            'booking.renter',
            'booking.vehicle.owner',
            'booking.vehicle.carModel'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $ticket
        ]);
    }

    /**
     * ========================================================================
     * 3. HÀM CẬP NHẬT TRẠNG THÁI: CHUYỂN DỊCH TIẾN ĐỘ VÉ KHIẾU NẠI
     * ========================================================================
     * Cho phép Nhân viên / Quản trị viên cập nhật tình trạng tiếp nhận xử lý vé,
     * đồng thời gán nhận dạng điều phối viên hiện hành (assigned_staff_id).
     *
     * @param  \Illuminate\Http\Request  $request   Dữ liệu yêu cầu chuyển trạng thái ('open', 'in_progress', 'closed').
     * @param  int                       $ticketId  ID của vé hỗ trợ.
     * @return \Illuminate\Http\JsonResponse        Phản hồi JSON xác nhận xử lý thành công.
     */
    public function updateStatus(Request $request, $ticketId)
    {
        $request->validate([
            'status' => 'required|in:open,in_progress,closed',
        ]);

        $ticket = Ticket::findOrFail($ticketId);

        // Chuyển đổi tham số giao diện thành tham số chuỗi hợp pháp trên DB
        $mappedStatus = match ($request->status) {
            'open' => 'new',
            'in_progress' => 'in_progress',
            'closed' => 'closed',
        };

        $ticket->status = $mappedStatus;
        $ticket->assigned_staff_id = Auth::id();
        $ticket->save();

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật trạng thái ticket thành công.',
            'data' => $ticket,
        ]);
    }

    /**
     * ========================================================================
     * 4. HÀM PHÂN XỬ: THẨM ĐỊNH TICKET HỦY CHUYẾN & XỬ PHẠT CHỦ XE GIAN LẬN
     * ========================================================================
     * Chức năng đặc quyền cho Admin & Coordinator phân xử các vụ hủy chuyến do Chủ xe:
     * - Nếu Bằng chứng Hợp lệ (approve): Miễn trách nhiệm, đóng Ticket.
     * - Nếu Bằng chứng Giả mạo / Lách luật (reject): Thi hành cơ chế chế tài gồm:
     *   + Trừ điểm uy tín (Credit Score) của Chủ xe.
     *   + Trừ trực tiếp tiền phạt từ Ví Khả dụng của Chủ xe sang Ví Khách hàng bù đắp.
     *
     * @param  \Illuminate\Http\Request  $request   Dữ liệu phán quyết (action, penalty_amount, deduct_score).
     * @param  int                       $ticketId  ID của vé sự cố khiếu nại.
     * @return \Illuminate\Http\JsonResponse        Phản hồi JSON phán quyết phân xử tài chính.
     */
    public function resolveCancelIncident(Request $request, $ticketId)
    {
        $user = Auth::user();

        // 1. CHỐT CHẶN BẢO MẬT: Chỉ vai trò 'admin' và 'coordinator' mới được cấp quyền xử lý
        $user->load('roles'); 
        $isAuthorized = $user->roles->whereIn('slug', ['admin', 'coordinator'])->isNotEmpty();

        if (!$isAuthorized) {
            return response()->json([
                'success' => false, 
                'message' => 'Truy cập bị từ chối. Bạn không có thẩm quyền phân xử tài chính.'
            ], 403);
        }

        // 2. Validate ràng buộc biểu mẫu quyết định từ Điều phối viên
        $request->validate([
            'action'         => 'required|in:approve,reject',
            'penalty_amount' => 'required_if:action,reject|numeric|min:0', // Bắt buộc nhập số tiền phạt nếu bác bỏ
            'deduct_score'   => 'required_if:action,reject|numeric|min:0|max:5', // Trừ từ 0 - 5 điểm uy tín
            'admin_note'     => 'nullable|string|max:500' // Ghi chú giải thích cho phán quyết
        ]);

        $ticket = Ticket::with('booking')->findOrFail($ticketId);

        // Ràng buộc bảo đảm không xét xử nhiều lần cho một phán quyết đã hoàn tất
        if (in_array($ticket->status, ['resolved', 'closed'])) {
            return response()->json(['success' => false, 'message' => 'Vé khiếu nại này đã được xử lý xong.'], 400);
        }

        try {
            DB::beginTransaction();

            $owner = User::findOrFail($ticket->user_id);
            $booking = $ticket->booking;

            if ($request->action === 'approve') {
                // ==========================================
                // KỊCH BẢN A: MINH CHỨNG HỢP LỆ (Chủ xe vô tội)
                // ==========================================
                $ticket->status = 'resolved';
                $ticket->assigned_staff_id = $user->id;
                
                // Ghi vết lý do của Admin vào hồ sơ Ticket để minh bạch khiếu nại hậu sự
                if ($request->admin_note) {
                    $ticket->content .= "\n[Ghi chú Admin " . $user->name . "]: " . $request->admin_note;
                }
                $ticket->save();

                $message = 'Đã duyệt ảnh minh chứng hợp lệ. Ticket đóng, Chủ xe không bị phạt.';
            } else {
                // ==========================================
                // KỊCH BẢN B: ẢNH GIẢ MẠO / LÁCH LUẬT (Xử phạt tài chính)
                // ==========================================
                $ticket->status = 'resolved';
                $ticket->assigned_staff_id = $user->id;
                if ($request->admin_note) {
                    $ticket->content .= "\n[Quyết định Phạt từ " . $user->name . "]: " . $request->admin_note;
                }
                $ticket->save();

                // 1. Thực hiện chế tài điểm uy tín (Giới hạn cận dưới không cho điểm âm)
                $owner->credit_score = max(0, $owner->credit_score - $request->deduct_score);
                $owner->save();

                // 2. Thực hiện khấu trừ bồi thường tài chính trên Ví (Khóa dòng chống Race Condition)
                $penaltyAmount = $request->penalty_amount;
                if ($penaltyAmount > 0 && $booking) {
                    $ownerWallet = Wallet::where('user_id', $owner->id)->lockForUpdate()->first();
                    $renterWallet = Wallet::where('user_id', $booking->renter_id)->lockForUpdate()->first();

                    // Trừ tiền bồi thường từ Ví của Chủ xe vi phạm
                    $ownerWallet->available_balance -= $penaltyAmount;
                    
                    if ($ownerWallet->available_balance < 0) {
                        $ownerWallet->status = 'locked';
                        $ownerWallet->locked_reason = 'Nợ tiền phạt/bồi thường sự cố gian lận từ quyết định phân xử của Admin.';
                        $ownerWallet->locked_at = now();
                        
                        // Cưỡng chế: Khóa niêm yết toàn bộ xe Sẵn sàng đón khách của Chủ xe
                        \App\Models\Vehicle::where('owner_id', $owner->id)
                            ->where('status', 'available')
                            ->update(['status' => 'locked']);
                    }
                    $ownerWallet->save();

                    Transaction::create([
                        'wallet_id'    => $ownerWallet->id,
                        'booking_id'   => $booking->id,
                        'amount'       => $penaltyAmount,
                        'type'         => 'debit',
                        'balance_type' => 'available',
                        'description'  => 'Phạt trừ tiền do gian lận bằng chứng sự cố hủy chuyến #' . $booking->id
                    ]);

                    // Cộng khoản tiền bồi thường này vào Ví cho Khách hàng chịu rủi ro bị hủy
                    $renterWallet->available_balance += $penaltyAmount;
                    $renterWallet->save();

                    Transaction::create([
                        'wallet_id'    => $renterWallet->id,
                        'booking_id'   => $booking->id,
                        'amount'       => $penaltyAmount,
                        'type'         => 'credit',
                        'balance_type' => 'available',
                        'description'  => 'Nhận bồi thường do Chủ xe gian lận hủy chuyến #' . $booking->id
                    ]);
                }

                $message = 'Đã bác bỏ minh chứng. Hệ thống đã trừ ' . $request->deduct_score . ' điểm uy tín và ' . number_format($request->penalty_amount) . 'đ bồi thường từ Ví chủ xe.';
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $message
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống PostgreSQL: ' . $e->getMessage()], 500);
        }
    }
}