<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & CÁC TRỤ GIAO THỨC HTTP (IMPORTS)
// ============================================================================
use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * BỘ ĐIỀU KHIỂN TRA CỨU ĐỊA CHỈ & HỆ TỌA ĐỘ BẢN ĐỒ (LOCATION CONTROLLER)
 * Tích hợp sâu với Google Geocoding API để giải mã địa danh thành Tọa độ GPS (Search/Forward Geocoding)
 * và chuyển đổi ngược Tọa độ (Lat/Lng) thành tên địa chỉ hành chính có thể đọc được (Reverse Geocoding).
 */
class LocationController extends Controller
{
    /**
     * Hằng số đường dẫn kết nối đến dịch vụ Geocoding của Google Maps
     */
    private const GOOGLE_GEOCODE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC TRA CỨU TỌA ĐỘ VÀ ĐỊA DANH (SEARCH & REVERSE)
    // ============================================================================

    /**
     * Tra cứu tọa độ địa dư từ từ khóa tìm kiếm (Forward Geocoding)
     * 
     * - Giới hạn phạm vi lãnh thổ tại Việt Nam (country:VN, region:vn).
     * - Chuẩn hóa và làm sạch chuỗi cấu trúc chuỗi địa chỉ trả về.
     * - Bảo an danh tính: Che mờ lỗi mạng khi mất kết nối Google và log nội bộ thông qua report().
     *
     * @param Request $request Yêu cầu HTTP chứa tham số chuỗi query (tên địa danh)
     * @return JsonResponse Mảng JSON các điểm gợi ý vị trí kèm Lat/Lng
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('query', ''));
        if ($query === '') {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $apiKey = env('GOOGLE_MAPS_API_KEY');
        if (empty($apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'GOOGLE_MAPS_API_KEY is not configured on backend.',
            ], 500);
        }

        try {
            // Thực thi yêu cầu HTTP tới máy chủ Google Geocoding
            $response = $this->googleHttp()->get(self::GOOGLE_GEOCODE_URL, [
                'address' => $query,
                'components' => 'country:VN',
                'region' => 'vn',
                'language' => 'vi',
                'key' => $apiKey,
            ]);
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Cannot connect to Google Geocoding service.',
            ]);
        }

        if (!$response->ok()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot connect to Google Geocoding service.',
            ], 502);
        }

        $payload = $response->json();
        $status = $payload['status'] ?? null;

        if ($status !== 'OK') {
            return response()->json([
                'success' => true,
                'data' => [],
                'google_status' => $status,
                'google_error_message' => $payload['error_message'] ?? null,
            ]);
        }

        // Chuyển đổi và gia cố dữ liệu Geolocation phù hợp cho Frontend Render
        $results = collect($payload['results'] ?? [])->map(function (array $item) {
            $formattedAddress = trim((string) ($item['formatted_address'] ?? ''));
            $parts = array_values(array_filter(array_map('trim', explode(',', $formattedAddress))));

            return [
                'name' => $parts[0] ?? $formattedAddress,
                'display_name' => $formattedAddress,
                'lat' => data_get($item, 'geometry.location.lat'),
                'lng' => data_get($item, 'geometry.location.lng'),
                'lon' => data_get($item, 'geometry.location.lng'),
                'source' => 'google',
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    /**
     * Tra cứu ngược địa chỉ từ bộ Tọa độ Vĩ độ & Kinh độ (Reverse Geocoding)
     * 
     * - Ứng dụng phổ biến cho định vị bản đồ GPS thời gian thực và lấy vị trí ghim hiện tại của User.
     *
     * @param Request $request Yêu cầu HTTP chứa `lat` và `lng`
     * @return JsonResponse Thông tin địa chỉ chính thức tương ứng với điểm GPS
     */
    public function reverse(Request $request): JsonResponse
    {
        $lat = $request->query('lat');
        $lng = $request->query('lng');

        if ($lat === null || $lng === null) {
            return response()->json([
                'success' => false,
                'message' => 'lat and lng are required.',
            ], 422);
        }

        $apiKey = env('GOOGLE_MAPS_API_KEY');
        if (empty($apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'GOOGLE_MAPS_API_KEY is not configured on backend.',
            ], 500);
        }

        try {
            $response = $this->googleHttp()->get(self::GOOGLE_GEOCODE_URL, [
                'latlng' => $lat . ',' . $lng,
                'language' => 'vi',
                'key' => $apiKey,
            ]);
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json([
                'success' => true,
                'data' => null,
                'message' => 'Cannot connect to Google Geocoding service.',
            ]);
        }

        if (!$response->ok()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot connect to Google Geocoding service.',
            ], 502);
        }

        $payload = $response->json();
        $status = $payload['status'] ?? null;

        if ($status !== 'OK' || empty($payload['results'])) {
            return response()->json([
                'success' => true,
                'data' => null,
                'google_status' => $status,
                'google_error_message' => $payload['error_message'] ?? null,
            ]);
        }

        // Trích xuất cấu trúc địa danh từ kết quả có độ chính xác cao nhất (vị trí index 0)
        $result = $payload['results'][0];
        $formattedAddress = trim((string) ($result['formatted_address'] ?? ''));
        $parts = array_values(array_filter(array_map('trim', explode(',', $formattedAddress))));

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $parts[0] ?? $formattedAddress,
                'display_name' => $formattedAddress,
                'lat' => data_get($result, 'geometry.location.lat'),
                'lng' => data_get($result, 'geometry.location.lng'),
                'lon' => data_get($result, 'geometry.location.lng'),
                'source' => 'google',
            ],
        ]);
    }

    // ============================================================================
    // 3. NHÓM HÀM CẤU HÌNH GIAO TIẾP VÀ BẢO TRÌ HTTP CLIENT (HTTP HELPER)
    // ============================================================================

    /**
     * Cấu hình và tạo mới phiên bản gửi Yêu cầu HTTP (HTTP Client Pending Request)
     * 
     * - Thiết lập trần thời gian trễ (timeout) là 10 giây.
     * - Tự động nhận diện cấu hình môi trường Local (Windows setups) thiếu CA bundle cho cURL
     *   để tắt xác minh chứng chỉ SSL nếu được cho phép trong biến .env.
     *
     * @return PendingRequest Đối tượng chuẩn bị thi hành kết nối HTTP
     */
    private function googleHttp(): PendingRequest
    {
        $request = Http::timeout(10)->acceptJson();

        // Useful for local Windows setups that lack a valid CA bundle for cURL.
        $disableSslVerify = filter_var(
            env('GOOGLE_MAPS_DISABLE_SSL_VERIFY', app()->environment('local')),
            FILTER_VALIDATE_BOOLEAN
        );

        if ($disableSslVerify) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }
}
