<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Exception;

/**
 * ============================================================================
 * LỚP DỊCH VỤ TRÍ TUỆ NHÂN TẠO OCR (AI OCR SERVICE)
 * ============================================================================
 * Dịch vụ nòng cốt chuyên trách nhận dạng và bóc tách thông tin tự động từ hình
 * ảnh giấy tờ tùy thân (Căn cước công dân - CCCD và Giấy phép lái xe - GPLX) thông
 * qua sức mạnh Mô hình ngôn ngữ lớn Gemini AI / FPT AI. Tích hợp các bộ lọc và
 * quy tắc kiểm định chặt chẽ về tuổi đời (18+) và hạng bằng lái an toàn (B1+).
 */
class OcrService
{
    /**
     * ========================================================================
     * 1. HÀM XỬ LÝ CĂN CƯỚC CÔNG DÂN (PROCESS ID CARD): QUÉT & THẨM ĐỊNH CCCD
     * ========================================================================
     * Thực hiện quy trình bóc tách thông tin thẻ Căn cước công dân qua 4 bước:
     * 1. Lưu trữ an toàn 2 mặt ảnh CCCD vào hệ thống ổ đĩa máy chủ (public/kyc).
     * 2. Gọi API Gemini AI để quét ảnh mặt trước và chuyển đổi thành định dạng JSON.
     * 3. Thực thi nghiệp vụ kiểm duyệt khắt khe:
     *    - Phát hiện ảnh lóa sáng, mờ ảo (không rõ số CCCD).
     *    - Kiểm tra Ngày sinh (DOB): Khách thuê bắt buộc phải từ đủ 18 tuổi trở lên.
     *    - Kiểm tra Ngày hết hạn (DOE): Phủ quyết thẻ đã hết hạn sử dụng.
     * 4. Chuẩn hóa chuỗi cấu trúc dữ liệu trả về cho Controller tiếp ứng.
     *
     * @param  \Illuminate\Http\UploadedFile  $frontImage  File ảnh mặt trước CCCD.
     * @param  \Illuminate\Http\UploadedFile  $backImage   File ảnh mặt sau CCCD.
     * @param  int                            $userId      ID Người dùng đang xác thực.
     * @return array                                       Mảng chứa URL ảnh và chuỗi JSON thông tin OCR.
     * @throws \Exception                                  Ngoại lệ khi lỗi kết nối hoặc vi phạm thể lệ (nhỏ hơn 18, hết hạn, ảnh mờ).
     */
    public function processIdCard($frontImage, $backImage, $userId)
    {
        // 1. Lưu 2 ảnh vào ổ cứng của Server (storage/app/public/kyc)
        $frontPath = $frontImage->storeAs('kyc', "user_{$userId}_cccd_front_" . time() . '.' . $frontImage->extension(), 'public');
        $backPath = $backImage->storeAs('kyc', "user_{$userId}_cccd_back_" . time() . '.' . $backImage->extension(), 'public');

        $frontUrl = asset('storage/' . $frontPath);
        $backUrl = asset('storage/' . $backPath);

        $frontImageAbsolute = storage_path('app/public/' . $frontPath);

        // =========================================================
        // 2. GỌI API GEMINI AI (GỬI ẢNH MẶT TRƯỚC ĐỂ LẤY THÔNG TIN)
        // =========================================================
        $base64Image = base64_encode(file_get_contents($frontImageAbsolute));
        $mimeType = mime_content_type($frontImageAbsolute) ?: 'image/jpeg';
        $apiKey = env('GEMINI_API_KEY');

        $prompt = "Bạn là hệ thống OCR bóc tách giấy tờ tùy thân của Việt Nam.\nHãy đọc bức ảnh này và trả về định dạng JSON (không dùng markdown, không giải thích). Cấu trúc JSON bắt buộc:\n{\n  \"id\": \"số CCCD/CMND\",\n  \"name\": \"Họ và tên\",\n  \"dob\": \"Ngày sinh (dd/mm/yyyy)\",\n  \"sex\": \"Giới tính\",\n  \"nationality\": \"Quốc tịch\",\n  \"home\": \"Quê quán\",\n  \"address\": \"Nơi thường trú\",\n  \"issue_date\": \"Ngày cấp\",\n  \"doe\": \"Ngày hết hạn\"\n}";

        $info = $this->callGeminiApi($prompt, $mimeType, $base64Image, $apiKey);

        $fptData = $info; // Tương thích với logic phía dưới

        // Nếu AI không đọc được số CCCD (do ảnh mờ), ép khách chụp lại
        if (empty($fptData['id']) || $fptData['id'] === 'N/A') {
            throw new Exception("Ảnh quá mờ hoặc lóa sáng. Hệ thống không thể đọc được số Căn cước, vui lòng chụp lại.");
        }

        // Kiểm tra độ tuổi (Đủ 18+ tuyệt đối theo Ngày/Tháng/Năm)
        if (!empty($fptData['dob']) && $fptData['dob'] !== 'N/A') {
            try {
                $dobString = trim($fptData['dob']);
                // Cố gắng parse theo định dạng d/m/Y hoặc d-m-Y
                if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dobString, $matches)) {
                    $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                    $year = $matches[3];
                    $dobDate = \Carbon\Carbon::createFromFormat('d/m/Y', "$day/$month/$year");
                    if ($dobDate->diffInYears(now()) < 18) {
                        throw new Exception("Bạn chưa đủ 18 tuổi để sử dụng dịch vụ thuê xe.");
                    }
                } elseif (preg_match('/^(\d{4})$/', $dobString, $matches)) {
                    // Nếu thẻ chỉ có Năm sinh (thẻ CMND cũ)
                    $year = (int)$matches[1];
                    if (now()->year - $year < 18) {
                        throw new Exception("Bạn chưa đủ 18 tuổi để sử dụng dịch vụ thuê xe.");
                    }
                }
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), "18 tuổi")) {
                    throw $e;
                }
            }
        }

        // Kiểm tra Hết hạn (DOE)
        if (!empty($fptData['doe']) && $fptData['doe'] !== 'N/A' && strtolower(trim($fptData['doe'])) !== 'không thời hạn') {
            try {
                $doeString = trim($fptData['doe']);
                if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $doeString, $matches)) {
                    $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                    $year = $matches[3];
                    $doeDate = \Carbon\Carbon::createFromFormat('d/m/Y', "$day/$month/$year")->endOfDay();
                    if ($doeDate->isPast()) {
                        throw new Exception("Thẻ Căn cước của bạn đã hết hạn sử dụng. Vui lòng cung cấp thẻ mới.");
                    }
                }
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), "hết hạn")) {
                    throw $e;
                }
            }
        }

        // 4. Chuẩn hóa lại dữ liệu cho gọn gàng để lưu Database
        $ocrData = [
            'id'          => $fptData['id'],
            'name'        => $fptData['name'] ?? '',
            'dob'         => $fptData['dob'] ?? '',
            'sex'         => $fptData['sex'] ?? '',
            'nationality' => $fptData['nationality'] ?? '',
            'home'        => $fptData['home'] ?? '',
            'address'     => $fptData['address'] ?? '',
            'issue_date'  => $fptData['issue_date'] ?? '',
            'doe'         => $fptData['doe'] ?? '', // Thêm trường ngày hết hạn
        ];

        // 5. Trả về cho KycController
        return [
            'front_url'       => $frontUrl,
            'back_url'        => $backUrl,
            'ocr_data'        => json_encode($ocrData, JSON_UNESCAPED_UNICODE), // Giữ nguyên dấu Tiếng Việt
            'document_number' => $ocrData['id']
        ];
    }

    /**
     * ========================================================================
     * 2. HÀM XỬ LÝ GIẤY PHÉP LÁI XE (PROCESS DRIVER LICENSE): QUÉT & RÀ TRONG BẰNG
     * ========================================================================
     * Thực thi bóc tách và đối soát thông tin Giấy phép lái xe (GPLX):
     * - Tải ảnh lên bộ nhớ tạm của Storage và chuyển đổi Base64 cho Gemini AI.
     * - Kiểm định nghiêm ngặt HẠNG BẰNG LÁI: Hệ thống Sàn chỉ chấp nhận phương tiện
     *   ô tô do đó đòi hỏi Khách phải sở hữu các hạng bằng có chứa mã (B, C, D, E, F).
     * - Kiểm tra hạn thi hành (DOE): Từ chối giấy phép đã vi phạm vượt hạn sử dụng.
     *
     * @param  \Illuminate\Http\UploadedFile  $frontImage  File ảnh mặt trước GPLX.
     * @param  \Illuminate\Http\UploadedFile  $backImage   File ảnh mặt sau GPLX.
     * @param  int                            $userId      ID Người dùng đang giao tiếp.
     * @return array                                       Mảng tham chiếu đường dẫn và JSON bóc tách.
     * @throws \Exception                                  Ngoại lệ khi bằng lái mờ, phi chuẩn hoặc hết thời hạn.
     */
    public function processDriverLicense($frontImage, $backImage, $userId)
    {
        // 1. Lưu tạm ảnh mặt trước & mặt sau vào thư mục storage
        $frontPath = $frontImage->storeAs('kyc', 'user_' . $userId . '_gplx_front_' . time() . '.jpg', 'public');
        $backPath  = $backImage->storeAs('kyc', 'user_' . $userId . '_gplx_back_' . time() . '.jpg', 'public');

        $frontUrl = url('storage/' . $frontPath);
        $backUrl  = url('storage/' . $backPath);

        $frontImageAbsolute = storage_path('app/public/' . $frontPath);

        // 2. Gọi API GEMINI AI (Gửi ảnh mặt trước để lấy thông tin GPLX)
        $base64Image = base64_encode(file_get_contents($frontImageAbsolute));
        $mimeType = mime_content_type($frontImageAbsolute) ?: 'image/jpeg';
        $apiKey = env('GEMINI_API_KEY');

        $prompt = "Bạn là hệ thống OCR bóc tách Giấy phép lái xe của Việt Nam.\nHãy đọc bức ảnh này và trả về định dạng JSON (không dùng markdown, không giải thích). Cấu trúc JSON bắt buộc:\n{\n  \"id\": \"Số GPLX\",\n  \"name\": \"Họ và tên\",\n  \"dob\": \"Ngày sinh (dd/mm/yyyy)\",\n  \"class\": \"Hạng bằng lái (ví dụ: B1, B2, C...). TRẢ VỀ CHUỖI RỖNG NẾU KHÔNG THẤY\",\n  \"address\": \"Nơi cư trú\",\n  \"place_issue\": \"Nơi cấp\",\n  \"date\": \"Ngày trúng tuyển/Ngày cấp\",\n  \"doe\": \"Ngày hết hạn\"\n}";

        $info = $this->callGeminiApi($prompt, $mimeType, $base64Image, $apiKey);

        $extracted = $info;
        
        // Nếu AI không đọc được số GPLX (do ảnh mờ), ép khách chụp lại
        if (empty($extracted['id']) || $extracted['id'] === 'N/A') {
            throw new Exception("Ảnh quá mờ hoặc lóa sáng. Hệ thống không thể đọc được số Giấy phép lái xe, vui lòng chụp lại.");
        }

        // KIỂM TRA HẠNG BẰNG LÁI (chỉ chấp nhận hạng ô tô: B, C, D, E, F)
        if (!empty($extracted['class']) && $extracted['class'] !== 'N/A') {
            $class = strtoupper(trim($extracted['class']));
            // Kiểm tra xem chuỗi có chứa chữ B, C, D, E, hoặc F không
            $hasCarClass = preg_match('/[BCDEF]/', $class);
            if (!$hasCarClass) {
                throw new Exception("Bằng lái xe hạng [{$class}] không đủ điều kiện thuê ô tô. Hệ thống chỉ chấp nhận hạng B1 trở lên.");
            }
        }

        // KIỂM TRA HẾT HẠN GPLX
        if (!empty($extracted['doe']) && $extracted['doe'] !== 'N/A' && strtolower(trim($extracted['doe'])) !== 'không thời hạn') {
            try {
                $doeString = trim($extracted['doe']);
                if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $doeString, $matches)) {
                    $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                    $year = $matches[3];
                    $doeDate = \Carbon\Carbon::createFromFormat('d/m/Y', "$day/$month/$year")->endOfDay();
                    if ($doeDate->isPast()) {
                        throw new Exception("Giấy phép lái xe của bạn đã hết hạn sử dụng. Vui lòng cung cấp GPLX còn hạn.");
                    }
                }
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), "hết hạn")) {
                    throw $e;
                }
            }
        }

        // Chuẩn hóa dữ liệu GPLX để lưu DB
        $ocrData = [
            'id'          => $extracted['id'],
            'name'        => $extracted['name'] ?? '',
            'dob'         => $extracted['dob'] ?? '',
            'class'       => $extracted['class'] ?? '',
            'address'     => $extracted['address'] ?? '',
            'place_issue' => $extracted['place_issue'] ?? '',
            'issue_date'  => $extracted['date'] ?? '',
            'doe'         => $extracted['doe'] ?? '',
        ];

        return [
            'document_number' => $ocrData['id'], // Số GPLX
            'front_url'       => $frontUrl,
            'back_url'        => $backUrl,
            'ocr_data'        => json_encode($ocrData, JSON_UNESCAPED_UNICODE)
        ];
    }

    /**
     * ========================================================================
     * 3. TRÌNH NẠP & TÁI KẾT NỐI GEMINI AI TỰ ĐỘNG (AUTO-RETRY ENGINE)
     * ========================================================================
     * Thực hiện gửi yêu cầu OCR tới máy chủ Google Gemini AI với thuật toán
     * Exponential Backoff (Tự động lặp lại khi nghẽn mạng).
     * - Khi gặp mã 503 (High Demand) hoặc 429 (Rate Limit): Trình ngầm kiên nhẫn
     *   chờ 2 giây, sau đó thử lại tới 3 lần trước khi bó tay.
     * - Chuyển hóa toàn bộ thông báo lỗi phức tạp tiếng Anh sang thông điệp tiếng
     *   Việt văn minh, lịch sự cho khách thuê cảm thấy an tâm.
     *
     * @param string $prompt       Câu lệnh yêu cầu bóc tách JSON.
     * @param string $mimeType     Định dạng tệp ảnh (image/jpeg, image/png).
     * @param string $base64Image  Chuỗi mã hóa ảnh Base64.
     * @param string $apiKey       Khóa bảo mật Gemini API Key.
     * @return array               Mảng dữ liệu JSON đã giải mã từ AI.
     * @throws \Exception          Ngoại lệ tiếng Việt thân thiết nếu hết lần thử.
     */
    private function callGeminiApi($prompt, $mimeType, $base64Image, $apiKey)
    {
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Image]]
                    ]
                ]
            ]
        ];

        $maxRetries = 3;
        $retryDelaySeconds = 2; // Khởi tạo thời gian đợi 2 giây
        $lastErrorBody = '';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::withoutVerifying()->timeout(35)->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}",
                    $payload
                );

                if ($response->successful()) {
                    $geminiData = $response->json();
                    $text = $geminiData['candidates'][0]['content']['parts'][0]['text'] ?? '';
                    $text = trim(preg_replace('/```json|```/', '', $text));

                    $info = json_decode($text, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $info;
                    }
                    throw new Exception("Lỗi AI OCR: Kết quả trả về không đúng định dạng JSON.");
                }

                $status = $response->status();
                $lastErrorBody = $response->body();

                // Nỗ lực thực hiện Retry cho các lỗi hệ thống nghẽn mạng từ Google (500, 502, 503, 504, 429)
                if (in_array($status, [500, 502, 503, 504, 429]) && $attempt < $maxRetries) {
                    sleep($retryDelaySeconds);
                    $retryDelaySeconds *= 2; // Tăng dần thời gian đợi: 2s -> 4s
                    continue;
                }

                // Nếu là mã lỗi sai key (400, 403) hoặc đã thử đến lượt cuối thì thoát lặp
                break;
            } catch (Exception $e) {
                if ($attempt < $maxRetries && !str_contains($e->getMessage(), "định dạng JSON")) {
                    sleep($retryDelaySeconds);
                    $retryDelaySeconds *= 2;
                    continue;
                }
                if (str_contains($e->getMessage(), "định dạng JSON")) {
                    throw $e;
                }
                $lastErrorBody = $e->getMessage();
                break;
            }
        }

        // Thay đổi lớp áo lỗi từ Anh Văn khô khan sang Tiếng Việt tường minh, thân thiện
        if (str_contains($lastErrorBody, '503') || str_contains(strtolower($lastErrorBody), 'high demand') || str_contains(strtolower($lastErrorBody), 'unavailable')) {
            throw new Exception("Máy chủ Trí Tuệ Nhân Tạo (AI) hiện đang có lượng truy cập cao đột biến. Hệ thống đã nỗ lực kết nối lại nhiều lần nhưng chưa đáp ứng kịp. Vui lòng đợi khoảng 30 giây rồi nhấn thử lại!");
        }
        if (str_contains($lastErrorBody, '429') || str_contains(strtolower($lastErrorBody), 'quota')) {
            throw new Exception("Hệ thống kiểm định tự động đang xử lý số lượng yêu cầu lớn. Vui lòng thử lại sau ít giây!");
        }
        if (str_contains($lastErrorBody, '400') || str_contains($lastErrorBody, '403') || str_contains(strtolower($lastErrorBody), 'api_key')) {
            throw new Exception("Lỗi xác thực khóa API Gemini. Vui lòng liên hệ Admin kiểm tra cấu hình khóa bảo mật.");
        }

        throw new Exception("Lỗi kết nối bộ xử lý AI: " . $lastErrorBody);
    }
}