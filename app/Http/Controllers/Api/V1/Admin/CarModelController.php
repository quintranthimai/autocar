<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CarModel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ============================================================================
 * LỚP CAR MODEL CONTROLLER (QUẢN LÝ THỦ GHI DÒNG XE & PHIÊN BẢN PHƯƠNG TIỆN)
 * ============================================================================
 * Controller phân hệ CMS Admin chịu trách nhiệm thao tác danh từ điển các Dòng xe,
 * mẫu xe chuẩn hóa (Car Models) mà hệ thống hỗ trợ niêm yết. Áp dụng quy chuẩn
 * xác thực nâng cao nhiều trường cùng lúc để đảm bảo tính duy nhất của phiên bản xe
 * và kiểm soát an toàn toàn vẹn dữ liệu khi có tài sản phụ thuộc.
 */
class CarModelController extends Controller
{
    /**
     * ========================================================================
     * 1. HÀM DANH SÁCH: TOÀN DIỆN MÁY BỘ CHI TIẾT TỪ ĐIỂN DÒNG XE
     * ========================================================================
     * Lấy toàn bô bản ghi dòng xe, tự động kết nối các bảng tham chiếu Khóa ngoại
     * (Danh mục xe, Loại nhiên liệu, Hệ thống truyền động Hộp số) để tải nhanh.
     *
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON mảng dòng xe chuẩn theo Hãng.
     */
    public function index()
    {
        $models = CarModel::with(['category', 'fuel', 'transmission'])->orderBy('brand_name')->get();
        return response()->json(['success' => true, 'data' => $models]);
    }

    /**
     * ========================================================================
     * 2. HÀM KHỞI TẠO: THÊM MỚI PHIÊN BẢN DÒNG XE VÀO TỪ ĐIỂN
     * ========================================================================
     * Validate tham số và sử dụng luật Rule::unique đa trường của Laravel để đảm bảo
     * không trùng lặp một bản ghi có cấu hình 100% y hệt nhau (Hãng, Tên, Phân khúc,
     * Nhiên liệu, Hộp số, Số chỗ) trong cơ sở dữ liệu.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu thông số kỹ thuật xe cần tạo.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON dòng xe khởi tạo thành công (201).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id'      => 'required|exists:categories,id',
            'fuel_id'          => 'required|exists:fuels,id',
            'transmission_id'  => 'required|exists:transmissions,id',
            'brand_name'       => 'required|string',
            
            // THIẾT LẬP BỘ LỌC BẢO ĐẢM TÍNH ĐỘC NHẤT THEO ĐA TRƯỜNG KẾT HỢP
            'model_name' => [
                'required',
                'string',
                Rule::unique('car_models')->where(function ($query) use ($request) {
                    return $query->where('brand_name', $request->brand_name)
                                ->where('category_id', $request->category_id)
                                ->where('fuel_id', $request->fuel_id)
                                ->where('transmission_id', $request->transmission_id)
                                ->where('seat_count', $request->seat_count);
                })
            ],
            'seat_count'       => 'required|integer',
            'fuel_consumption' => 'nullable|string',
        ], [
            // Cung cấp thông báo thân thiện rõ nét để quản trị viên nắm rõ lỗi
            'model_name.unique' => 'Phiên bản xe với chính xác các thông số này (Hãng, Tên, Phân khúc, Nhiên liệu, Hộp số, Số chỗ) đã tồn tại trên hệ thống!',
        ]);
        
        $carModel = CarModel::create($data);
        return response()->json(['success' => true, 'data' => $carModel], 201);
    }

    /**
     * ========================================================================
     * 3. HÀM CẬP NHẬT: ĐIỀU CHỈNH THÔNG SỐ VÀ CẤU HÌNH PHIÊN BẢN XE
     * ========================================================================
     * Cập nhật thông số Dòng xe, áp dụng cơ chế loại trừ chính ID đang chỉnh sửa
     * out ra khỏi phạm vi rà soát trùng lặp đa trường của Rule::unique.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu chỉnh sửa cần cập nhật.
     * @param  int                       $id       ID của Dòng xe tương ứng.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON thông báo hoàn tất.
     */
    public function update(Request $request, $id)
    {
        $carModel = CarModel::findOrFail($id);
        
        $data = $request->validate([
            'category_id'      => 'required|exists:categories,id',
            'fuel_id'          => 'required|exists:fuels,id',
            'transmission_id'  => 'required|exists:transmissions,id',
            'brand_name'       => 'required|string',
            
            // LUẬT KIỂM TRA ĐỘC NHẤT ĐA TRƯỜNG KẾ THỪA VÀO RA ID BẢN GHI HIỆN HẠNH
            'model_name' => [
                'required',
                'string',
                Rule::unique('car_models')->where(function ($query) use ($request) {
                    return $query->where('brand_name', $request->brand_name)
                                ->where('category_id', $request->category_id)
                                ->where('fuel_id', $request->fuel_id)
                                ->where('transmission_id', $request->transmission_id)
                                ->where('seat_count', $request->seat_count);
                })->ignore($id) // Loại trừ chính nó ra khi xét duyệt cập nhật
            ],
            'seat_count'       => 'required|integer',
            'fuel_consumption' => 'nullable|string',
        ], [
            'model_name.unique' => 'Phiên bản xe với chính xác các thông số này (Hãng, Tên, Phân khúc, Nhiên liệu, Hộp số, Số chỗ) đã tồn tại trên hệ thống!',
        ]);

        $carModel->update($data);
        return response()->json(['success' => true, 'data' => $carModel]);
    }

    /**
     * ========================================================================
     * 4. HÀM XÓA BẢN GHI: TIÊU HỦY DÒNG XE CÓ BẢO DIỄN KHÓA NGOẠI AN TOÀN
     * ========================================================================
     * Kiểm soát rủi ro hỏng hóc cấu trúc quan hệ dữ liệu: Nghiêm cấm xóa Dòng xe
     * nếu đang có phương tiện thực tế khai báo liên kết tới mẫu xe này trong DB.
     *
     * @param  int                       $id  ID của Dòng xe cần hủy bỏ.
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON xác nhận thu hồi hoặc báo lỗi.
     */
    public function destroy($id)
    {
        $carModel = CarModel::findOrFail($id);
        
        // Chốt chặn bảo đảm toàn vẹn dữ liệu hệ thống trước khi hủy bản ghi
        if ($carModel->vehicles()->exists()) {
            return response()->json(['success' => false, 'message' => 'Không thể xóa vì có xe thực tế đang thuộc dòng này!'], 400);
        }
        $carModel->delete();
        return response()->json(['success' => true, 'message' => 'Xóa dòng xe thành công']);
    }

    /**
     * ========================================================================
     * 5. HÀM TRUY VẤN THƯƠNG HIỆU: LẤY DANH BẠ HÃNG XE KHÔNG TRÙNG LẶP
     * ========================================================================
     * Trích xuất mảng duy nhất (Distinct) tên của các Thương hiệu/Hãng xe hiện có
     * (Ví dụ: Toyota, Honda, Hyundai) nhằm xây dựng menu lọc tìm kiếm Frontend.
     *
     * @return \Illuminate\Http\JsonResponse  Phản hồi JSON mảng chuỗi danh xưng hãng xe.
     */
    public function getUniqueBrands()
    {
        // Quét trên trường brand_name, loại bỏ trùng lặp và rút gọn thành mảng chuỗi đơn thuần
        $brands = CarModel::select('brand_name')
                        ->distinct()
                        ->whereNotNull('brand_name')
                        ->orderBy('brand_name', 'asc')
                        ->pluck('brand_name');

        return response()->json([
            'success' => true,
            'data' => $brands
        ]);
    }
}