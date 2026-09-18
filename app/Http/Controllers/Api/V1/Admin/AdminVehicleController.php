<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * LỚP ADMIN VEHICLE CONTROLLER (KIỂM DUYỆT & QUẢN LÝ TÀI SẢN PHƯƠNG TIỆN)
 * ============================================================================
 * Controller nghiệp vụ phụ trách giám sát toàn bô phương tiện niêm yết trên hệ thống.
 * Hỗ trợ Quản trị viên tra cứu danh mục, thẩm định hồ sơ phê duyệt ('pending' -> 'available'),
 * từ chối hồ sơ chưa đạt chuẩn và phong tỏa/mở khóa xe vi phạm chính sách kinh doanh.
 */
class AdminVehicleController extends Controller
{
    /**
     * ========================================================================
     * 1. HÀM DANH SÁCH: TRA CỨU VÀ TÌM KIẾM TOÀN BỘ PHƯƠNG TIỆN TRÊN HỆ THỐNG
     * ========================================================================
     * Phục vụ màn hình quản trị toàn cục đội xe của sàn với tính năng:
     * - Tải trước dữ liệu Chủ xe và Thông tin Dòng xe để tối ưu N+1 Query.
     * - Bộ lọc theo trạng thái xe ('pending', 'available', 'locked', 'rejected').
     * - Tìm kiếm theo Biển số, Số khung (VIN) hoặc Tên / Số điện thoại của Chủ xe.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số bộ lọc và phân trang.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON chứa danh sách xe phân trang.
     */
    public function index(Request $request)
    {
        $query = Vehicle::with([
            'owner:id,name,phone,email',
            'carModel:id,brand_name,model_name',
        ]);

        // Lọc theo trạng thái kiểm duyệt hoặc chế độ hoạt động
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Tìm kiếm đa năng theo biển số, số khung VIN, hoặc liên hệ chủ xe
        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('license_plate', 'like', "%{$search}%")
                    ->orWhere('vin_number', 'like', "%{$search}%")
                    ->orWhereHas('owner', function($qOwner) use ($search) {
                        $qOwner->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        // Ưu tiên hiển thị danh sách hồ sơ xe vừa đăng ký mới nhất lên trước
        $vehicles = $query->orderBy('created_at', 'desc')->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $vehicles
        ]);
    }

    /**
     * ========================================================================
     * 2. HÀM CHI TIẾT: THẨM ĐỊNH HỒ SƠ TOÀN VIỆN PHƯƠNG TIỆN (THÔNG SỐ & GIẤY TỜ)
     * ========================================================================
     * Trình bày chi tiết toàn bô dữ liệu nhạy cảm nhất của phương tiện phục vụ việc
     * ra quyết định cấp phép niêm yết: Hình ảnh xe, Biểu giá Phụ phí và Hồ sơ pháp lý
     * Cà vẹt/Đăng ký xe (Legal Documents).
     *
     * @param  int                       $id  ID của phương tiện cho thuê.
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON thông số hồ sơ đầy đủ.
     */
    public function show($id)
    {
        $vehicle = Vehicle::with([
            'owner', 
            'carModel.category', 
            'carModel.transmission', 
            'carModel.fuel',
            'images',
            'surcharges',
            'legalDocuments' // Kéo hồ sơ giấy tờ pháp lý để admin rà soát đối chiếu
        ])->find($id);

        if (!$vehicle) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy xe.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $vehicle
        ]);
    }

    /**
     * ========================================================================
     * 3. HÀM PHÊ DUYỆT (APPROVE): CẤP PHÉP NIÊM YẾT PHƯƠNG TIỆN LÊN HỆ THỐNG
     * ========================================================================
     * Xác nhận hồ sơ hợp lệ, chuyển đổi trạng thái xe từ Chờ duyệt ('pending')
     * sang Sẵn sàng đón khách ('available') trên không gian tìm kiếm chung.
     *
     * @param  int                       $id  ID của xe cần phê duyệt.
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON thông báo thành công.
     */
    public function approve($id)
    {
        $vehicle = Vehicle::find($id);
        if (!$vehicle) return response()->json(['success' => false, 'message' => 'Không tìm thấy xe'], 404);

        // Đảm bảo chỉ thực hiện trên hồ sơ xe đang trong trạng thái chờ kiểm duyệt
        if ($vehicle->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Xe này không ở trạng thái chờ duyệt.'], 400);
        }

        // Mở khóa lưu hành niêm yết công khai
        $vehicle->update(['status' => 'available']);

        return response()->json(['success' => true, 'message' => 'Đã phê duyệt xe thành công.']);
    }

    /**
     * ========================================================================
     * 4. HÀM TỪ CHỐI (REJECT): BÁC BỔ HỒ SƠ PHƯƠNG TIỆN CHƯA ĐẠT CHUẨN
     * ========================================================================
     * Từ chối yêu cầu niêm yết do ảnh sai lệch hoặc giấy tờ bất hợp pháp, chuyển
     * trạng thái sang 'rejected' và đòi hỏi nhập lý do từ chối để phản hồi cho Chủ xe.
     *
     * @param  \Illuminate\Http\Request  $request  Yêu cầu HTTP kèm theo lý do (reason).
     * @param  int                       $id       ID của xe bị từ chối.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON thông tin kết quả.
     */
    public function reject(Request $request, $id)
    {
        // Ràng buộc bắt buộc phải có lý do từ chối rõ ràng để hướng dẫn đối tác cải thiện
        $request->validate(['reason' => 'required|string|max:500']);

        $vehicle = Vehicle::find($id);
        if (!$vehicle) return response()->json(['success' => false, 'message' => 'Không tìm thấy xe'], 404);

        if ($vehicle->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Chỉ có thể từ chối xe đang chờ duyệt.'], 400);
        }

        // Đóng vĩnh viễn quy trình duyệt hồ sơ nháp hiện tại
        $vehicle->update(['status' => 'rejected']);

        return response()->json(['success' => true, 'message' => 'Đã từ chối duyệt xe.']);
    }

    /**
     * ========================================================================
     * 5. HÀM PHONG TỎA (LOCK): KHÓA XE VI PHẠM CHÍNH SÁCH HỆ THỐNG
     * ========================================================================
     * Chặn xe khỏi hệ sinh thái tiếp cận Khách hàng trong trường hợp xe có tranh chấp
     * an toàn chất lượng hoặc vi phạm cam kết uy tín đối tác của Chủ xe.
     *
     * @param  \Illuminate\Http\Request  $request  Yêu cầu kèm lý do vi phạm (reason).
     * @param  int                       $id       ID xe cần phong tỏa.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON kết quả xử lý.
     */
    public function lock(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string']);
        
        $vehicle = Vehicle::find($id);
        if (!$vehicle) return response()->json(['success' => false, 'message' => 'Không tìm thấy xe'], 404);

        // Thi hành phong tỏa xe sang trạng thái Khóa (Locked)
        $vehicle->update(['status' => 'locked']);
        
        return response()->json(['success' => true, 'message' => 'Đã khóa xe thành công.']);
    }

    /**
     * ========================================================================
     * 6. HÀM GIẢI PHONG (UNLOCK): MỞ KHÓA HOẠT ĐỘNG LẠI CHO PHƯƠNG TIỆN
     * ========================================================================
     * Xóa vế phạt khóa xe sau khi các sai phạm hoặc khiếu nại đã được giải quyết
     * thỏa đáng, cho phép phương tiện khôi phục hoạt động kinh doanh.
     *
     * @param  int                       $id  ID của xe cần mở khóa.
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON phán quyết hoàn tất.
     */
    public function unlock($id)
    {
        $vehicle = Vehicle::find($id);
        if (!$vehicle) return response()->json(['success' => false, 'message' => 'Không tìm thấy xe'], 404);

        // Trả về trạng thái hoạt động tự do cho tài sản
        $vehicle->update(['status' => 'available']);
        return response()->json(['success' => true, 'message' => 'Đã mở khóa xe thành công.']);
    }
}