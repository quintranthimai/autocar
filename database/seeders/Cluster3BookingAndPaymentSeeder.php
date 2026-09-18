<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class Cluster3BookingAndPaymentSeeder extends Seeder
{
    public function run(): void
    {
        // 1. SEED BẢNG payment_gateways
        DB::table('payment_gateways')->insert([
            ['id' => 1, 'code' => 'wallet', 'name' => 'Ví điện tử nội bộ', 'logo_url' => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=200&auto=format&fit=crop', 'config_data' => json_encode(['environment' => 'production']), 'is_active' => true, 'display_order' => 1, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ['id' => 2, 'code' => 'vnpay', 'name' => 'Cổng thanh toán VNPAY', 'logo_url' => 'https://images.unsplash.com/photo-1559526324-4b87b5e36e44?w=200&auto=format&fit=crop', 'config_data' => json_encode(['vnp_TmnCode' => 'AUTOCAR01']), 'is_active' => true, 'display_order' => 2, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
        ]);

        // Các dữ liệu giao dịch mấu chốt (bookings, transactions, reviews) để trống cho người dùng test thực tế
    }
}