<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingEvidence;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Exception;

/**
 * ============================================================================
 * LỚP DỊCH VỤ BẰNG CHỨNG HỒ SƠ CHI TRÌNH (BOOKING EVIDENCE SERVICE)
 * ============================================================================
 * Dịch vụ lõi chịu trách nhiệm quản lý quy trình nộp tệp tin minh chứng (Hình
 * ảnh 360 độ hoặc Video hiện trạng xe) trong 2 giai đoạn nhạy cảm: Bàn giao xe
 * (pickup) và Thu hồi trả xe (dropoff), bảo bọc tối đa tính xác thực chống gian
 * lận bằng dấu mốc thời gian máy chủ và thẩm tra đặc quyền Khách/Chủ xe.
 */
class EvidenceService
{
    /**
     * ========================================================================
     * 1. HÀM TẢI BẰNG CHỨNG GIAO NHẬN (UPLOAD EVIDENCE): LƯU V TIỂU TRÌNH
     * ========================================================================
     * Kiểm tra điều kiện thực thi và lưu trữ chứng cứ hợp pháp:
     * - Đối chứng Chủ thể: Bắt buộc người gửi là Renter (Khách) hoặc Owner (Chủ xe).
     * - Đối chứng Tình trạng Đơn: Với giai đoạn `pickup`, đơn hàng nhất thiết phải
     *   ở trạng thái `confirmed` (Đã được Chủ xe xác nhận cho phép ban giao).
     * - Nén vào Disk `public` với tên định danh duy nhất và tạo bản ghi SQL kèm
     *   mốc thời gian thực `Carbon::now()` vô hiệu hóa khả năng gian dối thời gian.
     *
     * @param  \App\Models\Booking            $booking  Đối tượng Đơn đặt xe liên hợp.
     * @param  \App\Models\User               $user     Tài khoản Người gửi bằng chứng.
     * @param  string                         $phase    Giai đoạn chụp ('pickup' hoặc 'dropoff').
     * @param  \Illuminate\Http\UploadedFile  $file     Tệp hình ảnh hoặc video chứng cứ.
     * @return \App\Models\BookingEvidence              Bản ghi chứng cứ giao dịch mới được lưu trữ.
     * @throws \Exception                               Ngoại lệ khi sai thẩm quyền hoặc chệch bước đơn.
     */
    public function uploadEvidence(Booking $booking, $user, $phase, $file)
    {
        // 1. Kiểm tra quyền: Người upload phải là Renter hoặc Owner của chuyến đi này
        if ($booking->renter_id !== $user->id && $booking->vehicle->owner_id !== $user->id) {
            throw new Exception("Bạn không có quyền tải bằng chứng cho chuyến đi này.");
        }

        // 2. Kiểm tra trạng thái Booking hợp lệ để thao tác
        if ($phase === 'pickup' && $booking->status !== 'confirmed') {
            throw new Exception("Chỉ được chụp ảnh giao xe khi chuyến đi đã được xác nhận.");
        }

        // 3. Xử lý lưu file vào Storage (Mặc định lưu vào storage/app/public/evidences)
        $extension = $file->getClientOriginalExtension();
        $fileName = "evidences/booking_{$booking->id}_{$phase}_" . time() . ".{$extension}";
        
        // Lưu file vào disk 'public'
        Storage::disk('public')->put($fileName, file_get_contents($file));

        // Tạo đường dẫn public URL
        $fileUrl = asset('storage/' . $fileName);

        // 4. Lưu vào Database với Timestamp được tạo bởi Server
        $evidence = BookingEvidence::create([
            'booking_id'  => $booking->id,
            'uploaded_by' => $user->id,
            'phase'       => $phase,
            'file_url'    => $fileUrl,
            'recorded_at' => Carbon::now(), // Đóng dấu thời gian thực chống gian lận
        ]);

        return $evidence;
    }
}