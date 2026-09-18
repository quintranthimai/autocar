<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class Cluster1UserAndAssetSeeder extends Seeder
{
    public function run(): void
    {
        // ====================================================================
        // 1. SEED BẢNG roles (Vai trò / Quyền hạn) [cite: 5, 6]
        // ====================================================================
        $roles = [
            ['id' => 1, 'name' => 'Admin tối cao', 'slug' => 'admin', 'description' => 'Toàn quyền điều hành, cấu hình hệ thống động.', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()], 
            ['id' => 2, 'name' => 'Chủ xe', 'slug' => 'owner', 'description' => 'Đối tác đăng ký kinh doanh và cho thuê phương tiện.', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()], 
            ['id' => 3, 'name' => 'Khách thuê xe', 'slug' => 'renter', 'description' => 'Người dùng tìm kiếm, đặt và trải nghiệm xe tự lái.', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()], 
            ['id' => 4, 'name' => 'Nhân viên CSKH', 'slug' => 'staff', 'description' => 'Hỗ trợ giải đáp thắc mắc, chăm sóc khách hàng vãng lai.', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()], 
            ['id' => 5, 'name' => 'Điều phối viên', 'slug' => 'coordinator', 'description' => 'Thẩm định hồ sơ OCR, xử lý tranh chấp bồi thường tài chính.', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()], 
        ];
        DB::table('roles')->insert($roles);

        // ====================================================================
        // 2. SEED BẢNG users (Hồ sơ Người dùng / Tài khoản) [cite: 9, 10, 11]
        // ====================================================================
        $password = Hash::make('password123');
        $users = [
            [
                'name' => 'Master Admin', 'email' => 'admin@autocar.vn', 'password' => $password, 
                'phone' => '0901111111', 'address' => 'Thành phố Hồ Chí Minh', 'avatar' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=250&auto=format&fit=crop', 
                'kyc_status' => 'approved', 'credit_score' => 5.0, 'is_blacklisted' => false, 
                'avg_rental_spend' => 0, 'avg_partner_revenue' => 0, 'membership_tier' => 'bronze', 'membership_tier_name' => 'Đồng', 
                'response_rate' => 100, 'response_time_minutes' => 0 
            ],
        ];
        DB::table('users')->insert($users);

        // ====================================================================
        // 3. SEED BẢNG role_user (Trung gian Vai trò - Người dùng) [cite: 7, 8]
        // ====================================================================
        $roleUser = [
            ['user_id' => 1, 'role_id' => 1], // Admin
        ];
        DB::table('role_user')->insert($roleUser);

        // ====================================================================
        // 4. SEED BẢNG legal_documents (Quản lý Hồ sơ Pháp lý & OCR)
        // ====================================================================
        // Trống - Dành cho người dùng thực thao tác thử nghiệm

        // ====================================================================
        // 5. SEED BẢNG wallets (Quản lý Ví nội bộ) [cite: 15, 16]
        // ====================================================================
        $wallets = [
            ['user_id' => 1, 'available_balance' => 0.00, 'deposit_balance' => 0.00, 'status' => 'active'],
        ];
        DB::table('wallets')->insert($wallets);
    }
}