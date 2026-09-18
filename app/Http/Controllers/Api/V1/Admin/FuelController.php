<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fuel;
use Illuminate\Http\Request;

/**
 * ============================================================================
 * LỚP FUEL CONTROLLER (QUẢN LÝ TỰ ĐIỂN LOẠI NHIÊN LIỆU PHƯƠNG TIỆN)
 * ============================================================================
 * Controller nghiệp vụ chuyên phân hệ CMS Admin chịu trách nhiệm vận hành danh mục
 * loại nhiên liệu xe (Xăng, Dầu Diesel, Điện, Hybrid...). Tự động chốt chặn rào cản
 * xóa khi có bất kỳ dòng xe nào đang khai báo sử dụng nguồn nhiên liệu đó.
 */
class FuelController extends Controller
{
    /**
     * ========================================================================
     * 1. HÀM DANH SÁCH: LẤY TRỌN BỘ CÁC LOẠI NHIÊN LIỆU HỖ TRỢ
     * ========================================================================
     * Cung cấp từ điển danh mục nhiên liệu cho các Select box hoặc bảng quản trị.
     *
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON chứa toàn bô loại nhiên liệu.
     */
    public function index()
    {
        return response()->json(['success' => true, 'data' => Fuel::all()]);
    }

    /**
     * ========================================================================
     * 2. HÀM KHỞI TẠO: THÊM MỚI DANH MỤC NHIÊN LIỆU VÀO HỆ THỐNG
     * ========================================================================
     * Xác thực độ độc nhất của trường 'name' trước khi lưu bản ghi vào cơ sở dữ liệu.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu nhiên liệu mới (name, display_name).
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON thông báo tạo thành công (201).
     */
    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|unique:fuels', 'display_name' => 'required']);
        return response()->json(['success' => true, 'data' => Fuel::create($data)], 201);
    }

    /**
     * ========================================================================
     * 3. HÀM CẬP NHẬT: THAY ĐỔI THÔNG BẢO HIỂN THỊ LOẠI NHIÊN LIỆU
     * ========================================================================
     * Sửa đổi mã hoặc tên hiển thị của nhiên liệu, loại trừ bản ghi đang cập nhật
     * ra khỏi quy trình thẩm duyệt tính duy nhất.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu chỉnh sửa cần lưu.
     * @param  int                       $id       ID của nhiên liệu tương ứng.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON bản ghi cập nhật thành công.
     */
    public function update(Request $request, $id)
    {
        $fuel = Fuel::findOrFail($id);
        $data = $request->validate(['name' => 'required|unique:fuels,name,'.$id, 'display_name' => 'required']);
        $fuel->update($data);
        return response()->json(['success' => true, 'data' => $fuel]);
    }

    /**
     * ========================================================================
     * 4. HÀM XÓA BẢN GHI: TIÊU HỦY NHIÊN LIỆU CÓ CHỐT CHẶN BẢO HIỂM RÀNG BUỘC
     * ========================================================================
     * Hủy danh mục nhiên liệu khỏi từ điển, cương quyết từ chối thực hiện nỗ lực
     * xóa bỏ nếu hiện hữu dòng xe (CarModel) đang phụ thuộc vào mã nhiên liệu này.
     *
     * @param  int                       $id  ID của loại nhiên liệu cần hủy.
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON thông báo thu hồi hoặc cảnh báo lỗi.
     */
    public function destroy($id)
    {
        $fuel = Fuel::findOrFail($id);
        // Kiểm soát toàn vẹn khóa ngoại: Cấm xóa khi đang có dòng xe kết nối
        if ($fuel->carModels()->exists()) {
            return response()->json(['success' => false, 'message' => 'Nhiên liệu này đang được sử dụng bởi các dòng xe!'], 400);
        }
        $fuel->delete();
        return response()->json(['success' => true, 'message' => 'Xóa thành công']);
    }
}