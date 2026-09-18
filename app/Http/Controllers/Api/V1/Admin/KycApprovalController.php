<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\LegalDocument;
use Illuminate\Support\Facades\Notification;
use App\Notifications\KycResultNotification;

/**
 * ============================================================================
 * LỚP KYC APPROVAL CONTROLLER (THẨM HỢP ĐỊNH DANH HỒ SƠ ĐỐI TÁC eKYC)
 * ============================================================================
 * Controller nghiệp vụ chuyên biệt phụ trách quy trình Thẩm định Giấy tờ pháp lý
 * (eKYC) do Người dùng tải lên. Vận hành dưới nguyên tắc ACID bằng DB Transaction,
 * tự động tổng hợp trạng thái định danh tổng thể của Chủ sở hữu và gởi thông điệp
 * điều phối tự động đến hòm thư người dùng qua hệ thống Laravel Notification.
 */
class KycApprovalController extends Controller
{
    /**
     * ========================================================================
     * 1. HÀM CHI TIẾT HỒ SƠ: TRUY XUẤT GIẤY TỜ TRÌNH XEM TRẢM THẨM ĐỊNH
     * ========================================================================
     * Tra cứu một tài liệu pháp y xác định kèm thông tin cơ bản của tác giả.
     *
     * @param  int                       $id  ID của Bản ghi Hồ sơ Giấy tờ (LegalDocument).
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON chi tiết tài liệu hoặc báo lỗi 404.
     */
    public function show($id)
    {
        $document = LegalDocument::with('user:id,name,email')->find($id);

        if (!$document) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy hồ sơ!'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $document
        ]);
    }

    /**
     * ========================================================================
     * 2. HÀM INDEX CHUẨN RESOURCE: BẮC CẦU SANG DANH ĐOÀN ĐỢI XỬ LÝ
     * ========================================================================
     * Điểm nối tiếp chuẩn RESTful Resource, tự động tái điều hướng logic xử lý sang
     * hàm `pendingList` nhằm tận dụng các tính năng phân trang và tinh lọc chuyên sâu.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số bộ lọc trạng thái.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON của danh bạ hồ sơ eKYC.
     */
    public function index(Request $request)
    {
        return $this->pendingList($request);
    }

    /**
     * ========================================================================
     * 3. HÀM DANH SÁCH HỒ SƠ (PENDING LIST): TRA CỨU TÀI LIỆU CẦN DUYỆT
     * ========================================================================
     * Lấy toàn bô hồ sơ theo mức độ kiểm duyệt (Mặc định lọc danh bạ 'pending').
     * Tái cơ cấu (transform) dữ liệu để bổ trợ trực tiếp các thuộc tính tên và
     * email người gửi cho tương tác liền mạch với bộ khung giao diện Frontend Vue.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số trạng thái (?status=pending|approved...).
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON danh sách phân trang (10 mục/trang).
     */
    public function pendingList(Request $request)
    {
        // Nhận trạng thái từ URL (?status=pending), nếu không truyền mặc định là pending
        $status = $request->query('status', 'pending');

        try {
            // Lấy danh sách giấy tờ kèm thông tin user (id, name, email)
            $documents = LegalDocument::with('user:id,name,email') 
                ->where('status', $status)
                ->orderBy('created_at', 'asc') 
                ->paginate(10);

            // Duyệt qua mảng để gom cấu trúc data lại cho đúng chuẩn Frontend Vue cần
            $documents->getCollection()->transform(function($doc) {
                $doc->user_name = $doc->user ? $doc->user->name : 'Không rõ';
                $doc->user_email = $doc->user ? $doc->user->email : 'Không rõ';
                return $doc;
            });

            return response()->json([
                'success' => true,
                'message' => 'Lấy danh sách thành công.',
                'data' => $documents
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi truy vấn Database: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ========================================================================
     * 4. HÀM XỬ LÝ DUYỆT (PROCESS KYC): PHÊ HẬU TRUNG TÂM HOẶC TỪ CHỐI HỒ SƠ
     * ========================================================================
     * Quyết định số phận một giấy tờ định danh (approved/rejected). ĐƯỢC BẢO TRỢ BỞI
     * GIAO DỊCH CƠ SỞ DỮ LIỆU (DB::beginTransaction) đảm bảo tuyệt đối sự đồng nhất:
     * - Cập nhật trạng thái bản ghi LegalDocument.
     * - Quét tổng quát TẤT CẢ giấy tờ thuộc Người dùng để suy luận lại trạng thái
     *   định danh `kyc_status` chung của toàn tài khoản (Một bị bác => Tổng từ chối).
     * - Bắn thông điệp hòm thư thông báo kết quả thẩm định kèm lý do rớt sang cho Khách hàng.
     *
     * @param  \Illuminate\Http\Request  $request     Thao tác (action) & lý do bác bỏ (nếu có).
     * @param  int                       $documentId  ID của tài liệu cần thẩm định.
     * @return \Illuminate\Http\JsonResponse          Phản hồi JSON xác thực trạng thái tiến trình.
     */
    public function processKyc(Request $request, $documentId)
    {
        $request->validate([
            'action' => 'required|string|in:approved,rejected',
            'reject_reason' => 'required_if:action,rejected|string|max:500' 
        ]);

        $document = LegalDocument::find($documentId);

        if (!$document) {
            return response()->json(['success' => false, 'message' => 'Hồ sơ không tồn tại!'], 404);
        }

        DB::beginTransaction();
        try {
            $document->status = $request->action;
            $document->save();

            $user = User::find($document->user_id);
            if ($user) {
                // Sửa lỗi ghi đè trạng thái: Lấy trạng thái của TẤT CẢ giấy tờ thuộc user này
                $documentStatuses = LegalDocument::where('user_id', $user->id)
                    ->pluck('status')
                    ->toArray();

                if (in_array('rejected', $documentStatuses)) {
                    // Nếu có bất kỳ giấy tờ nào bị từ chối -> Tổng thể tài khoản là từ chối
                    $user->kyc_status = 'rejected';
                } elseif (in_array('pending', $documentStatuses)) {
                    // Nếu không có giấy bị từ chối, nhưng còn giấy đang chờ -> Tổng thể là chờ duyệt
                    $user->kyc_status = 'pending';
                } else {
                    // Nếu tất cả đều approved -> Tổng thể tài khoản được công nhận approved
                    $user->kyc_status = 'approved';
                }
                
                $user->save();

                // Gửi thông báo trực tiếp qua luồng thông điệp cho User
                $rejectReason = $request->action === 'rejected' ? $request->reject_reason : null;
                $user->notify(new KycResultNotification($document, $request->action, $rejectReason));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $request->action === 'approved' 
                                ? "Đã DUYỆT hồ sơ thành công!" 
                                : "Đã TỪ CHỐI hồ sơ."
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
}

