<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transmission;
use Illuminate\Http\Request;

/**
 * ============================================================================
 * LỚP TRANSMISSION CONTROLLER (QUẢN LÝ TỰ ĐIỂN HỆ THỐNG TRUYỀN ĐỘNG HỌP SỐ)
 * ============================================================================
 * Controller nghiệp vụ thuộc phân hệ CMS Admin chịu trách nhiệm quản lý từ điển
 * phân loại hệ thống Hộp số phương tiện (Hộp số tự động, Số sàn, Bán tự động...).
 * Áp dụng chốt chặn bảo toàn dữ liệu quan hệ trước mọi nỗ lực tiêu hủy danh mục.
 */
class TransmissionController extends Controller
{
    /**
     * ========================================================================
     * 1. HÀM DANH SÁCH: TRA CỨU TRỌN BỘ DANH HỢP CÁC HỌP SỐ HỢP LỆ
     * ========================================================================
     * Trích xuất bộ từ điển hộp số sắp xếp theo mốc khởi tạo mới nhất lên đầu.
     *
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON danh sách trọn bộ Hộp số.
     */
    public function index()
    {
        $transmissions = Transmission::orderBy('created_at', 'desc')->get();
        
        return response()->json([
            'success' => true,
            'data' => $transmissions
        ]);
    }

    /**
     * ========================================================================
     * 2. HÀM KHỞI TẠO: THÊM MỚI DANH MỤC HỌP SỐ VÀO HỆ THỐNG TỰ ĐIỂN
     * ========================================================================
     * Kiểm chứng ràng buộc không trùng lặp mã 'name' trước khi tạo bản ghi mới.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu hộp số (name, display_name).
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON thông tin ban hành (201).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:transmissions,name',
            'display_name' => 'required|string|max:255',
        ], [
            'name.required' => 'Mã/Tên hộp số là bắt buộc.',
            'name.unique' => 'Mã/Tên hộp số này đã tồn tại.',
            'display_name.required' => 'Tên hiển thị là bắt buộc.',
        ]);

        $transmission = Transmission::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Thêm hộp số mới thành công!',
            'data' => $transmission
        ], 201);
    }

    /**
     * ========================================================================
     * 3. HÀM CẬP NHẬT: THAY ĐỔI CẤU HÌNH HIỂN THỊ DANH MỤC HỌP SỐ
     * ========================================================================
     * Sửa đổi tham số, loại trừ ID của bản ghi hiện hành khỏi luồng rà soát độc nhất.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu thay đổi cần cập nhật.
     * @param  int                       $id       ID của danh mục Hộp số tương ứng.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON kết quả điều chỉnh.
     */
    public function update(Request $request, $id)
    {
        $transmission = Transmission::find($id);

        if (!$transmission) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy hộp số!'], 404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255|unique:transmissions,name,' . $id,
            'display_name' => 'required|string|max:255',
        ], [
            'name.required' => 'Mã/Tên hộp số là bắt buộc.',
            'name.unique' => 'Mã/Tên hộp số này đã tồn tại.',
            'display_name.required' => 'Tên hiển thị là bắt buộc.',
        ]);

        $transmission->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật hộp số thành công!',
            'data' => $transmission
        ]);
    }

    /**
     * ========================================================================
     * 4. HÀM XÓA BẢN GHI: TIÊU HỦY HỌP SỐ CÓ RÀNG BUỘC KIỂM THƯ TOÀN VẸN
     * ========================================================================
     * Thu hồi bản ghi danh mục Hộp số. TRƯỚC KHI XÓA: Thực thi ràng buộc an toàn,
     * ngăn cấm mọi nỗ lực thao tác hủy nếu hiện có dòng xe (CarModel) vẫn đang
     * phụ thuộc cấu hình vào bộ hộp số này.
     *
     * @param  int                       $id  ID của Hộp số cần gỡ bỏ.
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON hoàn tất hay thông báo rào cản.
     */
    public function destroy($id)
    {
        $transmission = Transmission::find($id);

        if (!$transmission) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy hộp số!'], 404);
        }

        // Kiểm tra an toàn: Nếu có mẫu xe đang dùng loại hộp số này thì chặn không cho xóa
        if ($transmission->carModels()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa! Đang có dòng xe sử dụng loại hộp số này.'
            ], 400);
        }

        $transmission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa hộp số thành công!'
        ]);
    }
}