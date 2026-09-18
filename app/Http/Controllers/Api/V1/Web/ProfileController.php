<?php

namespace App\Http\Controllers\Api\V1\Web;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & HỆ TRỢ MÔ HÌNH (IMPORTS & MODELS)
// ============================================================================
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\LegalDocument;
use Illuminate\Support\Facades\Validator;

/**
 * BỘ ĐIỀU KHIỂN QUẢN TRỊ HỒ SƠ & TÀI KHOẢN CÁ NHÂN (PROFILE CONTROLLER)
 * Chuyên trách nghiệp vụ cập nhật thông tin cá nhân, thay đổi ảnh đại diện (Avatar),
 * nộp hồ sơ eKYC cơ sở, nâng cấp đặc quyền Chủ xe và xử lý hủy từ nhiệm tài khoản Vĩnh viễn.
 */
class ProfileController
{
    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC XÁC THỰC HỢP PHÁP (eKYC SUBMISSION)
    // ============================================================================

    /**
     * Nộp hồ sơ định danh điện tử (eKYC) - Phiên bản chuẩn hóa cơ sở
     *
     * @param Request $request Yêu cầu HTTP chứa tệp ảnh và loại giấy tờ
     * @return \Illuminate\Http\JsonResponse Trạng thái hồ sơ
     */
    public function submitKyc(Request $request)
    {
        $user = $request->user();

        // --------------------------------------------------------------------------
        // [Bước 1]: Kiểm soát bảo mật ranh giới Quyền Hạn (RBAC)
        // --------------------------------------------------------------------------
        // Chặn nếu tài khoản không sở hữu vai trò Khách thuê (renter)
        if (!$user->roles->contains('slug', 'renter')) {
            return response()->json([
                'success' => false, 
                'message' => 'Lỗi phân quyền: Chỉ Khách thuê mới được nộp hồ sơ eKYC!'
            ], 403);
        }

        // --------------------------------------------------------------------------
        // [Bước 2]: Thẩm định cú pháp biểu mẫu đầu vào (Input Validation)
        // --------------------------------------------------------------------------
        $request->validate([
            'document_type' => 'required|string|in:id_card,driving_license', // Danh mục cho phép: CCCD hoặc GPLX
            'document_number' => 'required|string|max:100', // Số CCCD hoặc Số GPLX hợp lệ
            'front_image' => 'required|image|mimes:jpeg,png,jpg|max:5120', // Giám sát dung lượng tối đa 5MB
            'back_image' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        DB::beginTransaction();
        try {
            // --------------------------------------------------------------------------
            // [Bước 3]: Ký gửi và phân phối ảnh vào không gian đĩa Storage
            // --------------------------------------------------------------------------
            // Phân tách thư mục lưu giữ theo phân loại tài liệu (kyc/id_card hoặc kyc/driving_license)
            $folder = 'kyc/' . $request->document_type;
            $frontPath = $request->file('front_image')->store($folder, 'public');
            $backPath = $request->file('back_image')->store($folder, 'public');

            // --------------------------------------------------------------------------
            // [Bước 4]: Ghi nhận hoặc gia cố dữ liệu vào bảng legal_documents
            // --------------------------------------------------------------------------
            // Dùng updateOrCreate: Nếu khách hàng tải lại giấy tờ sau khi bị từ chối/lỗi, hệ thống sẽ ghi đè lên hồ sơ cũ
            $document = LegalDocument::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'document_type' => $request->document_type, // Tra cứu duy nhất đối sánh User và Loại chứng từ
                ],
                [
                    'document_number' => $request->document_number,
                    'front_image_url' => $frontPath,
                    'back_image_url' => $backPath,
                    'status' => 'pending', // Gắn mác chờ Bộ phận Quản trị CSKH duyệt
                ]
            );

            // --------------------------------------------------------------------------
            // [Bước 5]: Đồng bộ hóa trạng thái Định danh tổng thể của Khách hàng
            // --------------------------------------------------------------------------
            $user->update(['kyc_status' => 'pending']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Nộp hồ sơ định danh thành công! Vui lòng chờ CSKH phê duyệt.',
                'data' => [
                    'kyc_status' => $user->kyc_status,
                    'document_details' => [
                        'type' => $document->document_type,
                        'number' => $document->document_number,
                        'front_image_url' => asset('storage/' . $document->front_image_url),
                        'back_image_url' => asset('storage/' . $document->back_image_url),
                        'status' => $document->status
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false, 
                'message' => 'Lỗi hệ thống khi lưu hồ sơ: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============================================================================
    // 3. NHÓM PHƯƠNG THỨC HIỆU CHÍNH HỒ SƠ & HÌNH TRÌNH BÀY (PERSONAL INFO & AVATAR)
    // ============================================================================

    /**
     * Cập nhật thông tin văn bản thuần túy của Người dùng (Họ tên, Số điện thoại, Email)
     *
     * @param Request $request Yêu cầu HTTP chứa (`name`, `phone`, `email`)
     * @return \Illuminate\Http\JsonResponse Trạng thái thực hiện và Hồ sơ sau cập nhật
     */
    public function update(Request $request)
    {
        $user = $request->user();

        // --------------------------------------------------------------------------
        // [Bước 1]: Bộ lọc Thẩm định ngoại lệ (Custom Validator & Unique Check)
        // --------------------------------------------------------------------------
        $validator = Validator::make($request->all(), [
            'name'  => 'sometimes|required|string|max:255',
            // Kiểm tra rào cản độc quyền: Số điện thoại và Email không được trùng, ngoại trừ của chính tài khoản hiện tại
            'phone' => 'sometimes|nullable|string|max:20|unique:users,phone,' . $user->id,
            'email' => 'sometimes|required|email|max:255|unique:users,email,' . $user->id,
        ], [
            'phone.unique' => 'Số điện thoại này đã được sử dụng bởi tài khoản khác.',
            'email.unique' => 'Email này đã được sử dụng bởi tài khoản khác.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu không hợp lệ.',
                'errors'  => $validator->errors()
            ], 422);
        }

        // --------------------------------------------------------------------------
        // [Bước 2]: Lưu Thay Đổi - Chỉ ghi nhận các trường cho phép (Mass Assignment Protection)
        // --------------------------------------------------------------------------
        $user->update($request->only(['name', 'phone', 'email']));

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật thông tin cá nhân thành công!',
            'data'    => $user->load('roles')
        ]);
    }

    /**
     * Xử lý tải lên và thay thế tệp tin Ảnh đại diện (Avatar) cho Người dùng
     *
     * @param Request $request Yêu cầu HTTP chứa file ảnh `avatar`
     * @return \Illuminate\Http\JsonResponse Trạng thái xử lý kèm đường dẫn URL Avatar mới
     */
    public function updateAvatar(Request $request)
    {
        // --------------------------------------------------------------------------
        // [Bước 1]: Kiểm duyệt mở rộng danh mục đuôi ảnh mimes và trần dung lượng 10MB
        // --------------------------------------------------------------------------
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp,bmp,avif,svg|max:10240',
        ], [
            'avatar.required' => 'Vui lòng chọn một file ảnh.',
            'avatar.image'    => 'File tải lên phải là định dạng hình ảnh hợp lệ.',
            'avatar.mimes'    => 'Định dạng ảnh không được hỗ trợ (chấp nhận jpeg, png, jpg, gif, webp, bmp, avif).',
            'avatar.max'      => 'Kích thước ảnh tối đa cho phép là 10MB.'
        ]);

        $user = $request->user();

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');

            // --------------------------------------------------------------------------
            // [Bước 2]: Giải phóng Bộ nhớ vật lý (Xóa tệp ảnh cũ nếu không phải Avatar mặc định)
            // --------------------------------------------------------------------------
            if ($user->avatar && !str_contains($user->avatar, 'default')) {
                $oldPath = str_replace(asset('storage/'), '', $user->avatar);
                Storage::disk('public')->delete($oldPath);
            }

            // Ký gửi vào folder `avatars` trên Public Storage
            $path = $file->store('avatars', 'public');

            // --------------------------------------------------------------------------
            // [Bước 3]: Ghi chuỗi đường dẫn mới vào trường avatar của bảng users
            // --------------------------------------------------------------------------
            $user->avatar = asset('storage/' . $path);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật ảnh đại diện thành công!',
                'avatar' => $user->avatar,
                'data' => $user->load('roles')
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Không tìm thấy file ảnh gửi lên.'
        ], 400);
    }

    // ============================================================================
    // 4. NHÓM PHƯƠNG THỨC CHUYỂN B ĐỔI VAI TRÒ & XÓA TRẢ TÀI KHOẢN (ACCOUNT LIFECYCLE)
    // ============================================================================

    /**
     * Hủy bỏ và Xóa Tài khoản Vĩnh Viễn ra khỏi hệ sinh thái Sàn Giao dịch
     * 
     * Rào cản An ninh Bảo bối:
     * - CẤM TUYỆT ĐỐI hành vi xóa tài khoản Quản trị viên (`admin`, `master_admin`, hoặc `admin@autocar.vn`).
     * - Dọn dẹp rác bộ nhớ Đĩa: Xóa ảnh đại diện trong Storage nếu có trước khi Thu hồi Database.
     * - Xóa sổ toàn diện Token chứng thực đang hiệu lực của thiết bị.
     *
     * @param Request $request Yêu cầu HTTP
     * @return \Illuminate\Http\JsonResponse Kết quả hủy tài khoản
     */
    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        // Kiểm tra Lớp Bảo an Tối cao - Ngăn chặn Vô tình Xóa Tài khoản Quản trị
        if ($user->roles->contains(fn($r) => in_array($r->slug, ['admin', 'master_admin'], true)) || $user->email === 'admin@autocar.vn') {
            return response()->json([
                'success' => false,
                'message' => 'Tài khoản Quản trị viên tối cao của hệ thống không thể bị xóa vĩnh viễn!'
            ], 403);
        }

        // Tùy chọn: Dọn dẹp tệp tin ảnh đại diện hiện diện trên ổ lưu trữ
        if ($user->avatar && !str_contains($user->avatar, 'default')) {
            $oldPath = str_replace(asset('storage/'), '', $user->avatar);
            Storage::disk('public')->delete($oldPath);
        }

        // Thu hồi toàn bộ token xác lập phiên đăng nhập trong bảng personal_access_tokens
        $user->tokens()->delete();

        // Loại bỏ chính thức bản ghi ra khỏi cơ sở dữ liệu (Soft/Hard Delete tùy thuộc cấu hình User Model)
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Tài khoản đã được xóa vĩnh viễn.'
        ]);
    }

    /**
     * Kích hoạt thăng tiến Tài khoản từ Khách thuê (Renter) lên thành Đối tác Chủ xe (Owner)
     *
     * @param Request $request Yêu cầu HTTP chứa Token Khách hàng
     * @return \Illuminate\Http\JsonResponse Quyền lực tài khoản sau cập nhật
     */
    public function upgradeToOwner(Request $request)
    {
        $user = $request->user();
        
        $ownerRole = \App\Models\Role::where('slug', 'owner')->first();

        if (!$ownerRole) {
            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống: Không tìm thấy role chủ xe.'], 500);
        }

        // Kiểm định: Nếu User chưa nắm giữ đặc quyền Chủ xe, tiến hành thêm mới quan hệ vào bảng role_user
        if (!$user->roles->contains('slug', 'owner')) {
            $user->roles()->attach($ownerRole->id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Chúc mừng! Bạn đã trở thành Đối tác Chủ xe.',
            'data' => $user->load('roles') // Nạp lại danh bạ Quyền để Frontend lập tức hiển thị Trung tâm Quản lý xe
        ]);
    }
}