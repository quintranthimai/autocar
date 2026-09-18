<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & CÁC TRỤ AI DỊCH VỤ (IMPORTS & SERVICES)
// ============================================================================
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\UploadIdCardRequest;
use App\Services\AI\OcrService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use App\Notifications\NewKycSubmittedNotification;
use App\Models\User;
use Exception;

/**
 * BỘ ĐIỀU KHIỂN ĐỊNH DANH ĐIỆN TỬ TỰ ĐỘNG HÓA AI OCR (AI e-KYC CONTROLLER)
 * Chuyên trách vận hành công cụ Nhận dạng Ký tự Quang học (OCR - FPT AI / Google AI) để 
 * tự động bóc tách thông tin Thẻ Căn Cước (CCCD) và Giấy Phép Lái Xe (GPLX), kiểm soát chống 
 * gian lận tráo giấy tờ trùng lặp và liên đới cảnh báo đến Đội ngũ Duyệt đơn Admin.
 */
class KycController extends Controller
{
    /**
     * Dịch vụ Trí tuệ Nhân tạo xử lý bóc tách thông tin hình ảnh OCR
     * @var OcrService
     */
    protected $ocrService;

    /**
     * Khởi tạo Bộ điều khiển và tiêm trích Dịch vụ AI OCR (Dependency Injection)
     *
     * @param OcrService $ocrService
     */
    public function __construct(OcrService $ocrService)
    {
        $this->ocrService = $ocrService;
    }

    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC XỬ LÝ OCR THẺ CĂN CƯỚC (CITIZEN ID CARD PROCESSING)
    // ============================================================================

    /**
     * Tải lên và Giải mã Tự động Thẻ Căn cước Công dân (CCCD) bằng AI OCR
     * 
     * Quy tắc An ninh & Bóc tách:
     * - Ký gửi hai mặt ảnh sang `OcrService` để bóc tách Trường Số thẻ, Họ tên, Ngày sinh, Quê quán...
     * - Rà soát Trùng lặp Khép kín: Trường hợp Số CCCD đã thuộc sở hữu của Tài khoản khác -> Từ chối và Xóa tệp ảnh rác.
     * - Làm sạch tệp cũ: Nếu Khách hàng tải lại, hệ thống xóa bỏ giấy tờ và tệp ảnh cũ của người đó trên ổ Storage.
     *
     * @param UploadIdCardRequest $request Yêu cầu HTTP đã thẩm định tệp `front_image` và `back_image`
     * @return \Illuminate\Http\JsonResponse Dữ liệu bóc tách được từ AI OCR
     */
    public function uploadIdCard(UploadIdCardRequest $request)
    {
        try {
            $user = auth('sanctum')->user();

            DB::beginTransaction();

            // --------------------------------------------------------------------------
            // [Bước 1]: Triển khai Dịch vụ AI OCR (FPT AI/Cloud) xử lý nhận diện hình ảnh
            // --------------------------------------------------------------------------
            $result = $this->ocrService->processIdCard(
                $request->file('front_image'),
                $request->file('back_image'),
                $user->id
            );

            // --------------------------------------------------------------------------
            // [Bước 2]: Kiểm duyệt Chống trùng lặp Thẻ Căn cước trên Sàn (Fraud Prevention)
            // --------------------------------------------------------------------------
            $exists = DB::table('legal_documents')
                ->where('document_number', $result['document_number'])
                ->where('document_type', 'id_card')
                ->where('user_id', '!=', $user->id)
                ->exists();

            if ($exists) {
                // Xóa tệp ảnh rác vừa ghi vào đĩa lưu trữ để tiết kiệm dung lượng Storage
                $frontPath = str_replace(asset('storage') . '/', '', $result['front_url']);
                $backPath = str_replace(asset('storage') . '/', '', $result['back_url']);
                Storage::disk('public')->delete([$frontPath, $backPath]);
                throw new Exception("Thẻ Căn cước này đã được liên kết với một tài khoản khác trong hệ thống.");
            }

            // --------------------------------------------------------------------------
            // [Bước 3]: Thu dọn hồ sơ CCCD cũ và tiêu hủy file ảnh vật lý nếu khách tải đi tải lại
            // --------------------------------------------------------------------------
            $oldDocs = DB::table('legal_documents')
              ->where('user_id', $user->id)
              ->where('document_type', 'id_card')
              ->get();
              
            foreach ($oldDocs as $oldDoc) {
                $frontPath = str_replace(asset('storage') . '/', '', $oldDoc->front_image_url);
                $backPath = str_replace(asset('storage') . '/', '', $oldDoc->back_image_url);
                Storage::disk('public')->delete([$frontPath, $backPath]);
            }

            DB::table('legal_documents')
              ->where('user_id', $user->id)
              ->where('document_type', 'id_card')
              ->delete();

            // --------------------------------------------------------------------------
            // [Bước 4]: Lập hợp đồng Ghi chuỗi chuỗi OCR vào bảng legal_documents
            // --------------------------------------------------------------------------
            DB::table('legal_documents')->insert([
                'user_id'            => $user->id,
                'vehicle_id'         => null,
                'document_type'      => 'id_card',
                'document_number'    => $result['document_number'],
                'front_image_url'    => $result['front_url'],
                'back_image_url'     => $result['back_url'],
                'ocr_extracted_data' => $result['ocr_data'],
                'status'             => 'pending', 
                'created_at'         => now(),
                'updated_at'         => now()
            ]);

            // --------------------------------------------------------------------------
            // [Bước 5]: Chuyển trạng thái xác thực hồ sơ cá nhân sang Chờ duyệt (`pending`)
            // --------------------------------------------------------------------------
            DB::table('users')->where('id', $user->id)->update([
                'kyc_status' => 'pending'
            ]);

            DB::commit();

            // Kích hoạt chuông thông báo theo thời gian thực tới đội ngũ Quản trị viên (Admins)
            $admins = User::whereHas('roles', function($q) {
                $q->where('slug', 'admin');
            })->get();
            Notification::send($admins, new NewKycSubmittedNotification($user, 'id_card'));

            return response()->json([
                'success' => true,
                'message' => 'Tải lên CCCD thành công. Hệ thống đang tiến hành phê duyệt.',
                'data' => [
                    'front_image' => $result['front_url'],
                    'back_image'  => $result['back_url'],
                    'extracted'   => json_decode($result['ocr_data'])
                ]
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error("uploadIdCard EXCEPTION: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============================================================================
    // 3. NHÓM PHƯƠNG THỨC XỬ LÝ OCR GIẤY PHÉP LÁI XE (DRIVER LICENSE PROCESSING)
    // ============================================================================

    /**
     * Tải lên và Giải mã tự động Giấy phép lái xe (GPLX) bằng AI OCR
     *
     * Điều kiện Tiên quyết (Business Prerequisite):
     * - BUỘC PHẢI KHAI BÁO THẺ CĂN CƯỚC (CCCD) THÀNH CÔNG TRƯỚC KHI TẢI GPLX.
     * - AI sẽ nỗ lực đọc Hạng Bằng (B1, B2, C...). Nếu ảnh mờ không thấy hạng bằng, cờ `requires_manual_class` sẽ
     *   bật lên để yêu cầu Khách hàng tự tay chọn bộ sung qua API `updateDriverLicenseClass`.
     *
     * @param Request $request Yêu cầu HTTP chứa file ảnh GPLX
     * @param OcrService $ocrService Dịch vụ AI OCR tiêm trích runtime
     * @return \Illuminate\Http\JsonResponse Dữ liệu GPLX giải mã được
     */
    public function uploadDriverLicense(Request $request, \App\Services\AI\OcrService $ocrService)
    {
        // [Bước 1]: Kiểm định tệp tin tải lên tại Controller (Tối đa 10MB/tệp)
        $request->validate([
            'gplx_front' => 'required|image|mimes:jpeg,png,jpg|max:10240', // max:10240 cho phép dung lượng lên đến 10MB
            'gplx_back'  => 'required|image|mimes:jpeg,png,jpg|max:10240',
        ]);

        $user = $request->user();

        // [Bước 2]: Kiểm tra Điều kiện tiên quyết - Bắt buộc sở hữu hồ sơ Thẻ Căn Cước trước
        $hasIdCard = DB::table('legal_documents')
            ->where('user_id', $user->id)
            ->where('document_type', 'id_card')
            ->exists();

        if (!$hasIdCard) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn phải xác thực Thẻ Căn cước (CCCD) trước khi tải lên Giấy phép lái xe.'
            ], 400);
        }

        try {
            // [Bước 3]: Ký gửi về bộ máy AI FPT xử lý nhận dạng ký tự Bằng Lái
            $ocrResult = $ocrService->processDriverLicense(
                $request->file('gplx_front'), // Ánh xạ trường ảnh mặt trước GPLX
                $request->file('gplx_back'),  // Ánh xạ trường ảnh mặt sau GPLX
                $user->id
            );

            // [Bước 4]: Rà soát sự trùng lặp Bằng Lái trên toàn hệ sinh thái Sàn
            $exists = DB::table('legal_documents')
                ->where('document_number', $ocrResult['document_number'])
                ->where('document_type', 'driver_license')
                ->where('user_id', '!=', $user->id)
                ->exists();

            if ($exists) {
                // Tiêu hủy ảnh nháp vừa giải nén
                $frontPath = str_replace(url('storage') . '/', '', $ocrResult['front_url']);
                $backPath = str_replace(url('storage') . '/', '', $ocrResult['back_url']);
                Storage::disk('public')->delete([$frontPath, $backPath]);
                throw new Exception("Giấy phép lái xe này đã được liên kết với một tài khoản khác trong hệ thống.");
            }

            // [Bước 5]: Dọn dẹp bản ghi Bằng lái xe cũ và xóa file vật lý tương ứng
            $oldGplxDocs = \App\Models\LegalDocument::where('user_id', $user->id)
              ->where('document_type', 'driver_license')
              ->get();
              
            foreach ($oldGplxDocs as $oldDoc) {
                $frontPath = str_replace(asset('storage') . '/', '', $oldDoc->front_image_url);
                $backPath = str_replace(asset('storage') . '/', '', $oldDoc->back_image_url);
                Storage::disk('public')->delete([$frontPath, $backPath]);
                $oldDoc->delete();
            }

            // [Bước 6]: Ghi nhận hồ sơ mới vào CSDL với nhãn định dạng là `driver_license`
            $document = \App\Models\LegalDocument::create([
                'user_id'            => $user->id,
                'document_type'      => 'driver_license', // Định danh Giấy Phép Lái Xe
                'document_number'    => $ocrResult['document_number'],
                'front_image_url'    => $ocrResult['front_url'],
                'back_image_url'     => $ocrResult['back_url'],
                'ocr_extracted_data' => json_decode($ocrResult['ocr_data'], true), // Giải nén JSON ngăn tình trạng double-encode
                'status'             => 'pending',
            ]);

            // Kích hoạt Cảnh báo Quản trị viên
            $admins = User::whereHas('roles', function($q) {
                $q->where('slug', 'admin');
            })->get();
            Notification::send($admins, new NewKycSubmittedNotification($user, 'driver_license'));

            // Đánh giá Ngoại lệ: Kiểm tra xem AI có gặp trở ngại trong việc đọc Hạng bằng hay không
            $requiresManualClass = empty(json_decode($ocrResult['ocr_data'], true)['class']);

            return response()->json([
                'success' => true,
                'message' => 'Tải lên GPLX thành công.',
                'requires_manual_class' => $requiresManualClass, // Truyền tín hiệu cho Frontend tự động mở modal bổ sung Hạng bằng
                'data'    => [
                    'front_image' => $ocrResult['front_url'],
                    'back_image'  => $ocrResult['back_url'],
                    'extracted'   => json_decode($ocrResult['ocr_data'])
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // ============================================================================
    // 4. NHÓM PHƯƠNG THỨC GIA CỐ HỒ SƠ THỦ CÔNG (MANUAL FALLBACK METHODS)
    // ============================================================================

    /**
     * Cập nhật hạng bằng lái xe do người dùng lựa chọn thủ công (Khi AI OCR không tự nhận dạng được do ảnh chói/mờ)
     *
     * @param Request $request Yêu cầu HTTP chứa trường `licence_class` (B1, B2, C...)
     * @return \Illuminate\Http\JsonResponse Kết quả gia cố hồ sơ OCR
     */
    public function updateDriverLicenseClass(Request $request)
    {
        // Rà soát Danh mục Hạng bằng Bắt buộc
        $request->validate([
            'licence_class' => 'required|string|in:B1,B2,C,D,E,F'
        ]);

        $user = $request->user();

        // Tìm kiếm bản ghi Giấy phép Lái xe đang nằm trong danh mục Chờ duyệt
        $document = \App\Models\LegalDocument::where('user_id', $user->id)
            ->where('document_type', 'driver_license')
            ->where('status', 'pending')
            ->first();

        if (!$document) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy hồ sơ Giấy phép lái xe đang chờ duyệt.'
            ], 404);
        }

        // Bóc tách mảng JSON lưu trữ, gán bổ sung trường Hạng Bằng (`class`) và Lưu trở lại CSDL
        $ocrData = $document->ocr_extracted_data;
        if (is_string($ocrData)) {
            $ocrData = json_decode($ocrData, true);
        }
        
        $ocrData['class'] = $request->licence_class;
        $document->ocr_extracted_data = $ocrData;
        $document->save();

        return response()->json([
            'success' => true,
            'message' => 'Đã cập nhật hạng bằng lái xe. Hồ sơ đang chờ duyệt.',
            'data' => [
                'extracted' => $ocrData
            ]
        ]);
    }
}