<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\AmenityType;
use Illuminate\Http\Request;

/**
 * ============================================================================
 * LỚP AMENITY CONTROLLER (QUẢN LÝ DANH BẠ TIỆN ÍCH & NHÓM TIỆN ÍCH)
 * ============================================================================
 * Controller phân hệ CMS Admin chịu trách nhiệm thao tác CRUD đối với từ điển
 * tiện ích xe cho thuê (như Bản đồ, Camera hành trình, Sunroof...) cùng phân nhóm
 * tiện ích (Tính năng an toàn, Tiện nghi nội thất...). Áp dụng kiểm soát an toàn
 * toàn vẹn dữ liệu PostgreSQL trước khi thực hiện hành động xóa.
 */
class AmenityController extends Controller
{
    /**
     * ========================================================================
     * 1. HÀM DANH SÁCH: TỔNG HỢP TOÀN BỘ CÁC TIỆN ÍCH KÈM NHÓM
     * ========================================================================
     * Tra cứu toàn bộ các Tiện ích chi tiết được lưu trong hệ thống, tự động
     * gán kèm thông tin của Nhóm Tiện ích cha (AmenityType) để trình bày trực quan.
     *
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON mảng tiện ích chi tiết.
     */
    public function index()
    {
        $amenities = Amenity::with('amenityType')->orderBy('id', 'desc')->get();
        return response()->json(['success' => true, 'data' => $amenities]);
    }

    /**
     * ========================================================================
     * 2. HÀM TRUY VẤN NHÓM: LẤY DANH BẠ NHÓM TIỆN ÍCH (AMENITY TYPES)
     * ========================================================================
     * Cung cấp danh sách các danh mục Phân nhóm tiện ích để phục vụ trình hiển thị
     * Dropdown (Select Box) trên giao diện Thêm mới / Chỉnh sửa Tiện ích của Client.
     *
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON mảng danh mục nhóm tiện ích.
     */
    public function getTypes()
    {
        $types = AmenityType::all();
        return response()->json(['success' => true, 'data' => $types]);
    }

    /**
     * ========================================================================
     * 3. HÀM KHỞI TẠO: THÊM MỚI BẢN GHI TIỆN ÍCH VÀO TỪ ĐIỂN
     * ========================================================================
     * Tiếp nhận biểu mẫu tạo mới Tiện ích chi tiết, xác thực tính duy nhất của mã
     * nhận diện (name) và ràng buộc tồn tại với ID Nhóm tiện ích tương ứng.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu tiện ích (amenity_type_id, name, display_name, icon).
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON xác nhận tạo mới thành công.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'amenity_type_id' => 'required|exists:amenity_types,id',
            'name'            => 'required|string|unique:amenities,name',
            'display_name'    => 'required|string',
            'icon'            => 'nullable|string'
        ]);

        $amenity = Amenity::create($validated);
        // Load ngay quan hệ Nhóm tiện ích cha để Frontend cập nhật bảng mà không cần tải lại trang
        $amenity->load('amenityType'); 

        return response()->json(['success' => true, 'message' => 'Thêm tiện ích thành công!', 'data' => $amenity]);
    }

    /**
     * ========================================================================
     * 4. HÀM CẬP NHẬT: THAY ĐỔI THÔNG SỐ CỦA TIỆN ÍCH CHI TIẾT
     * ========================================================================
     * Thực hiện cập nhật tên hiển thị, mã icon biểu tượng hoặc chuyển hướng thuôc về
     * Nhóm tiện ích khác, loại trừ kiểm tra trùng ID của chính bản ghi hiện tại.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu cần sửa đổi.
     * @param  int                       $id       ID của Tiện ích cần cập nhật.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON kết quả điều chỉnh.
     */
    public function update(Request $request, $id)
    {
        $amenity = Amenity::findOrFail($id);

        $validated = $request->validate([
            'amenity_type_id' => 'required|exists:amenity_types,id',
            'name'            => 'required|string|unique:amenities,name,' . $id,
            'display_name'    => 'required|string',
            'icon'            => 'nullable|string'
        ]);

        $amenity->update($validated);
        $amenity->load('amenityType');

        return response()->json(['success' => true, 'message' => 'Cập nhật thành công!', 'data' => $amenity]);
    }

    /**
     * ========================================================================
     * 5. HÀM XÓA BẢN GHI: THƯ HỦY TIỆN ÍCH CÓ RÀNG BUỘC TOÀN VẸN DỮ LIỆU
     * ========================================================================
     * Chấm dứt tồn tại một Tiện ích khỏi hệ thống. TRƯỚC KHI XÓA: Áp dụng chốt chặn
     * rà soát trên bảng trung gian quan hệ Nhiều - Nhiều (amenity_vehicle), cấm thuyên
     * chuyển xóa nếu đang có xe thực tế khai báo sử dụng tiện ích này.
     *
     * @param  int                       $id  ID của Tiện ích cần tiêu hủy.
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON xác nhận thu hồi thành công.
     */
    public function destroy($id)
    {
        $amenity = Amenity::findOrFail($id);

        // Kiểm tra bảo bối an toàn: Cấm xóa khi phương tiện trên sàn đang ràng buộc
        if ($amenity->vehicles()->count() > 0) {
            return response()->json([
                'success' => false, 
                'message' => 'Không thể xóa! Đang có phương tiện sử dụng tiện ích này.'
            ], 400);
        }

        $amenity->delete();
        return response()->json(['success' => true, 'message' => 'Đã xóa tiện ích thành công.']);
    }

    /**
     * ========================================================================
     * 6. HÀM KHỞI TẠO NHÓM: THÊM MỚI DANH MỤC PHÂN NHÓM TIỆN ÍCH
     * ========================================================================
     * Khởi tạo một cụm chủ đề mới trong từ điển để gom nhóm các tiện ích con
     * (Ví dụ: Thiết bị thông minh, Tiện ích an toàn).
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu tên nhóm (name, display_name).
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON bản ghi nhóm vừa tạo.
     */
    public function storeType(Request $request)
    {
        $validated = $request->validate([
            'name'         => 'required|string|unique:amenity_types,name',
            'display_name' => 'required|string',
        ]);

        $type = AmenityType::create($validated);

        return response()->json([
            'success' => true, 
            'message' => 'Thêm Nhóm tiện ích thành công!', 
            'data'    => $type
        ]);
    }

    /**
     * ========================================================================
     * 7. HÀM CẬP NHẬT NHÓM: SỬA ĐỔI THÔNG BẢO DANH MỤC PHÂN NHÓM
     * ========================================================================
     * Cập nhật thông số tiêu đề của Nhóm Tiện ích, giữ vững tính nhất quán cho toàn
     * hệ thống tiện ích chi tiết phụ thuộc phía dưới.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu sửa đổi tên nhóm.
     * @param  int                       $id       ID của Nhóm Tiện ích cần cập nhật.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON kết quả xử lý.
     */
    public function updateType(Request $request, $id)
    {
        $type = AmenityType::findOrFail($id);

        $validated = $request->validate([
            'name'         => 'required|string|unique:amenity_types,name,' . $id,
            'display_name' => 'required|string',
        ]);

        $type->update($validated);

        return response()->json([
            'success' => true, 
            'message' => 'Cập nhật Nhóm tiện ích thành công!', 
            'data'    => $type
        ]);
    }

    /**
     * ========================================================================
     * 8. HÀM XÓA NHÓM: TIÊU HỦY PHÂN NHÓM TIỆN ÍCH CÓ BẢO DIỄN KHÓA NGOẠI
     * ========================================================================
     * Xóa danh mục cha Nhóm Tiện ích. Áp dụng cơ chế bảo vệ an toàn: Nghiêm cấm xóa
     * nếu nhóm này đang đóng vai trò là thư mục gốc của bất kỳ Tiện ích con nào.
     *
     * @param  int                       $id  ID của Nhóm Tiện ích cần thu Hủy.
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON xác nhận kết quả xử lý.
     */
    public function destroyType($id)
    {
        $type = AmenityType::findOrFail($id);

        // Kiểm tra an toàn: Đảm bảo nhóm đã được dọn trống không còn tiện ích chi tiết bên trong
        if ($type->amenities()->count() > 0) {
            return response()->json([
                'success' => false, 
                'message' => 'Không thể xóa! Đang có Tiện ích chi tiết sử dụng nhóm này.'
            ], 400);
        }

        $type->delete();

        return response()->json([
            'success' => true, 
            'message' => 'Đã xóa nhóm tiện ích thành công.'
        ]);
    }
}