<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Category;

/**
 * ============================================================================
 * LỚP CATEGORY CONTROLLER (QUẢN LÝ DANH MỤC PHÂN KHÚC XE NỀN TẢNG)
 * ============================================================================
 * Controller nghiệp vụ chuyên phụ trách quản lý từ điển Danh mục Phân khúc xe
 * (Xe Sedan 4 chỗ, SUV 7 chỗ, Mini hatchback...). Cung cấp thống kê kèm theo số lượng
 * dòng xe liên quan và đảm bảo ràng buộc toàn vẹn khi thực thi gõ bỏ danh mục.
 */
class CategoryController extends Controller
{
    /**
     * ========================================================================
     * 1. HÀM DANH SÁCH: LẤY DANH MỤC PHÂN KHÚC KÈM THÔNG KÊ SỞ LƯỢNG XE
     * ========================================================================
     * Trả về danh sách trọn bộ các Danh mục xe được khởi tạo trên nền tảng,
     * tự động thực thi truy vấn `withCount` để thống kê ngay tổng lượng mẫu xe
     * (Car Models) đang thuộc danh mục nhằm hỗ trợ báo cáo trực quan cho Admin.
     *
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON danh bạ danh mục & số lượng xe.
     */
    public function index()
    {
        // Sử dụng withCount để gán thêm thuộc tính car_models_count đếm số mẫu xe trực thuộc
        $categories = Category::withCount('carModels')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'message' => 'Lấy danh sách danh mục thành công.',
            'data' => $categories
        ]);
    }

    /**
     * ========================================================================
     * 2. HÀM KHỞI TẠO: THÊM MỚI DANH MỤC PHÂN KHÚC XE
     * ========================================================================
     * Tiếp nhận tham số, xác định tính duy nhất của mã định dạng danh mục (name)
     * và ghi chép bản ghi vào cơ sở dữ liệu.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu danh mục cần tạo (name, display_name).
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON kết quả khởi tạo (HTTP 201).
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name',
            'display_name' => 'required|string|max:255',
        ], [
            'name.required' => 'Mã/Tên danh mục là bắt buộc.',
            'name.unique' => 'Mã/Tên danh mục này đã tồn tại trên hệ thống.',
            'display_name.required' => 'Tên hiển thị là bắt buộc.',
        ]);

        $category = Category::create($request->only(['name', 'display_name']));

        return response()->json([
            'success' => true,
            'message' => 'Thêm danh mục mới thành công!',
            'data' => $category
        ], 201);
    }

    /**
     * ========================================================================
     * 3. HÀM CẬP NHẬT: SỬA ĐỔI THÔNG TIN VÀ TẤT ĐỊNH HIỂN THỊ DANH MỤC
     * ========================================================================
     * Điều chỉnh tham số hiển thị, loại trừ bản ghi đang chỉnh sửa khi tiến hành
     * xét duyệt ràng buộc benzina độc nhất (unique validation) trên trường 'name'.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu điều chỉnh (name, display_name).
     * @param  int                       $id       ID của danh mục phân khúc cần cập nhật.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON xác nhận thay đổi hoàn tất.
     */
    public function update(Request $request, $id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy danh mục!'], 404);
        }

        // Ràng buộc hợp lệ hóa thông số, ngoại trừ ID chính chủ của bản ghi hiện hành
        $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $id,
            'display_name' => 'required|string|max:255',
        ], [
            'name.required' => 'Mã/Tên danh mục là bắt buộc.',
            'name.unique' => 'Mã/Tên danh mục này đã tồn tại.',
            'display_name.required' => 'Tên hiển thị là bắt buộc.',
        ]);

        $category->update($request->only(['name', 'display_name']));

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật danh mục thành công!',
            'data' => $category
        ]);
    }

    /**
     * ========================================================================
     * 4. HÀM XÓA BẢN GHI: TIÊU HỦY DANH MỤC CÓ RÀNG BUỘC KIỂM TRA KHÓA NGOẠI
     * ========================================================================
     * Thu hồi bản ghi Danh mục khỏi từ điển sàn thuê xe. TRƯỚC KHI XÓA: Thực hiện
     * kiểm tra an toàn, ngăn cấm mọi nỗ lực tiêu hủy nếu danh mục vẫn đang đóng
     * vai trò phân khúc chủ đạo cho bất kỳ mẫu xe nào trên hệ thống.
     *
     * @param  int                       $id  ID của Danh mục cần thu hồi.
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON xử lý thành công hoặc cảnh báo rủi ro.
     */
    public function destroy($id)
    {
        $category = Category::find($id);

        if (!$category) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy danh mục!'], 404);
        }

        // KIỂM TRA RÀNG BUỘC AN TOÀN: Từ chối hủy nếu có bất cứ mẫu xe nào thuộc danh mục này
        if ($category->carModels()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa! Đang có mẫu xe thuộc danh mục này. Vui lòng chuyển các xe sang danh mục khác trước.'
            ], 400);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa danh mục thành công!'
        ]);
    }
}