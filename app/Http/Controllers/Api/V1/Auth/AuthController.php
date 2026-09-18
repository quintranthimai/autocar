<?php

namespace App\Http\Controllers\Api\V1\Auth;

// ============================================================================
// 1. KHÔNG GIAN NHẬP THƯ VIỆN & CÁC LỚP MÔ HÌNH HỆ THỐNG (IMPORTS & MODELS)
// ============================================================================
use App\Mail\ResetPasswordMail; // Lớp cấu hình giao diện Email gửi mã OTP khôi phục mật khẩu
use Illuminate\Support\Facades\Mail; // Thư viện điều phối gửi thư của Laravel
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // Thư viện xác thực Auth của Laravel
use Illuminate\Support\Facades\Hash; // Thư viện mã hóa mật khẩu Bcrypt
use Illuminate\Support\Facades\DB;   // Thư viện tương tác cơ sở dữ liệu (Transaction)
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Role;

/**
 * BỘ TIẾP NHẬN & ĐIỀU CHÍNH TỔNG NỐI XÁC THỰC (AUTHENTICATION CONTROLLER)
 * Quản lý trọn gói quy trình: Đăng ký, Đăng nhập, Đăng xuất và Khôi phục Mật khẩu toàn Hệ sinh thái.
 */
class AuthController
{
    // ============================================================================
    // 2. NHÓM PHƯƠNG THỨC XÁC NHẬN ĐĂNG KÝ VÀ GÁN QUYỀN TRUY BỘ (REGISTER LOGIC)
    // ============================================================================

    /**
     * Phương thức xử lý ĐĂNG KÝ tài khoản mới
     * - Yêu cầu nghiệp vụ: Chỉ cho phép tự do đăng ký làm Khách thuê (renter) hoặc Chủ xe (owner/partner).
     * - Bảo vệ an ninh: Chặn tuyệt đối hành vi tự động đăng ký làm Quản trị viên (admin, master_admin...).
     */
    public function register(Request $request)
    {
        // [Bước 1]: Thẩm định dữ liệu đầu vào (Validation)
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'phone' => 'required|string|max:15',
            'role_slug' => 'required|in:renter,owner,partner', // Thiết lập ranh giới an toàn: Chỉ cho thuê hoặc cho thuê
        ]);

        // [Bước 2]: Khởi tạo giao dịch CSDL (Transaction) để đảm bảo toàn vẹn dữ liệu
        DB::beginTransaction();
        try {
            // Tạo bản ghi Người dùng mới trong bảng `users`
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password), // Mã hóa an toàn Bcrypt cho Mật khẩu
                'phone' => $request->phone,
            ]);

            // Phân bổ Vai trò (Role Mapping): Nếu là Chủ xe (owner), gán luôn quyền Renter để họ có thể tự trải nghiệm đi thuê xe của người khác
            $roleSlugs = ($request->role_slug === 'owner') ? ['renter', 'owner'] : ['renter'];
            
            $roles = Role::whereIn('slug', $roleSlugs)->pluck('id');
            
            if ($roles->isEmpty()) {
                throw new \Exception('Role không hợp lệ trong hệ thống.');
            }
            
            // Gắn danh sách vai trò vào bảng quan hệ nhiều-nhiều `role_user`
            $user->roles()->attach($roles); 

            // Khởi tạo Ví tiền Sàn (Wallet) với số dư ban đầu bằng 0
            $user->wallet()->create([
                'available_balance' => 0,
                'deposit_balance' => 0,
            ]);

            // Xác nhận hoàn tất lưu trữ dữ liệu vào CSDL
            DB::commit();

            // [Bước 3]: Tạo Token chứng thực (Sanctum Token) để hỗ trợ Khách hàng đăng nhập tự động ngay sau khi đăng ký
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Đăng ký tài khoản thành công',
                'data' => [
                    'user' => $user->load('roles'),
                    'access_token' => $token,
                ]
            ], 201);

        } catch (\Exception $e) {
            // Hủy giao dịch nếu xảy ra bất kỳ biến cố nào trong lúc khởi tạo
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }

    // ============================================================================
    // 3. NHÓM PHƯƠNG THỨC XÁC HOẠT ĐĂNG NHẬP, ĐĂNG XUẤT VÀ KIỂM DUYỆT AN NINH
    // ============================================================================

    /**
     * Phương thức xử lý ĐĂNG NHẬP (Dành cho tất cả các nhóm quyền trên hệ thống)
     * - Kiểm tra thông tin tài khoản hợp lệ.
     * - Chặn người dùng nếu họ đang bị liệt vào Danh sách đen (Blacklist).
     * - Cấp Token chứng thực quyền lực tương ứng.
     */
    public function login(Request $request)
    {
        // [Bước 1]: Kiểm tra cú pháp Email và Mật khẩu gửi lên
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // [Bước 2]: Thử nghiệm khớp Mật khẩu với cơ sở dữ liệu thông qua Auth::attempt
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['success' => false, 'message' => 'Email hoặc mật khẩu không chính xác'], 401);
        }

        $user = User::where('email', $request->email)->first();

        // [Bước 3]: Thẩm định An ninh Danh sách đen (Blacklisted Accounts Check)
        if ($user->is_blacklisted) {
            Auth::logout();
            return response()->json(['success' => false, 'message' => 'Tài khoản của bạn đã bị khóa. Vui lòng liên hệ bộ phận CSKH.'], 403);
        }

        // Tạo Token chứng thực bằng thư viện Laravel Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        // [Bước 4]: Trả kết quả kèm chi tiết Vai trò và trạng thái Ví tiền
        return response()->json([
            'success' => true,
            'message' => 'Đăng nhập thành công',
            'data' => [
                'user' => $user->load(['roles', 'wallet']),
                'access_token' => $token,
            ]
        ]);
    }

    /**
     * Phương thức xử lý ĐĂNG XUẤT (Hủy Token chứng thực hiện tại của thiết bị)
     */
    public function logout(Request $request)
    {
        // Thu hồi và xóa bỏ Token đang được sử dụng cho phiên thao tác này
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Đăng xuất thành công']);
    }

    // ============================================================================
    // 4. NHÓM PHƯƠNG THỨC ĐỔI MẬT KHẨU VÀ KHÔI PHỤC MẬT KHẨU BẰNG OTP (PASSWORD MGT)
    // ============================================================================

    /**
     * Phương thức xử lý ĐỔI MẬT KHẨU CHỦ ĐỘNG (Cho tài khoản đang đăng nhập trong app)
     */
    public function changePassword(Request $request)
    {
        // [Bước 1]: Thẩm định mật khẩu cũ và xác nhận mật khẩu mới
        $request->validate([
            'old_password' => 'required',
            'new_password' => 'required|min:6|confirmed',
        ]);

        $user = $request->user();

        // [Bước 2]: Đối chiếu Mật khẩu cũ có đúng không
        if (!Hash::check($request->old_password, $user->password)) {
            return response()->json(['success' => false, 'message' => 'Mật khẩu cũ không chính xác'], 400);
        }

        // [Bước 3]: Mã hóa và cập nhật Mật khẩu mới vào CSDL
        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return response()->json(['success' => true, 'message' => 'Đổi mật khẩu thành công']);
    }

    /**
     * Phương thức xử lý QUÊN MẬT KHẨU (Khởi tạo mã OTP 6 chữ số & Gửi thư điện tử)
     */
    public function forgotPassword(Request $request)
    {
        // [Bước 1]: Xác minh xem Email có tồn tại trong hệ thống hay chưa
        $request->validate(['email' => 'required|email|exists:users,email']);

        // [Bước 2]: Sinh mã số xác nhận (OTP Token) ngẫu nhiên gồm 6 chữ số
        $token = (string) random_int(100000, 999999); 

        // [Bước 3]: Ghi nhận hoặc gia hạn mã OTP vào bảng chuyên biệt `password_reset_tokens`
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => $token, 'created_at' => Carbon::now()]
        );

        // [Bước 4]: Thực thi Gửi Email thật (Sử dụng cấu hình Mail Server trong môi trường .env)
        Mail::to($request->email)->send(new ResetPasswordMail($token));

        return response()->json([
            'success' => true, 
            'message' => 'Mã xác nhận đã được gửi đến email của bạn.'
        ]);
    }

    /**
     * Phương thức xử lý ĐẶT LẠI MẬT KHẨU (Xác minh mã OTP và cập nhật Mật khẩu mới)
     */
    public function resetPassword(Request $request)
    {
        // [Bước 1]: Kiểm duyệt các trường yêu cầu
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'token' => 'required|numeric|digits:6',
            'password' => 'required|min:6|confirmed',
        ]);

        // [Bước 2]: Đối chiếu Email và Mã OTP trong bảng lưu trữ tạm
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->token)
            ->first();

        if (!$resetRecord) {
            return response()->json(['success' => false, 'message' => 'Mã xác nhận không hợp lệ hoặc đã hết hạn.'], 400);
        }

        // [Bước 3]: Cập nhật Mật khẩu mới vào hồ sơ Người dùng hợp lệ
        User::where('email', $request->email)->update([
            'password' => Hash::make($request->password)
        ]);

        // [Bước 4]: Thu dọn và xóa bỏ chuỗi OTP đã được sử dụng thành công
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['success' => true, 'message' => 'Đặt lại mật khẩu thành công. Vui lòng đăng nhập lại.']);
    }
}