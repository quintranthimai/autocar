<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Cluster4SupportAndDisputeSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Danh mục sự cố
        DB::table('ticket_categories')->insert([
            ['id' => 1, 'name' => 'owner_cancel_incident', 'display_name' => 'Chủ xe báo cáo sự cố kỹ thuật đột xuất'],
            ['id' => 2, 'name' => 'refund_request', 'display_name' => 'Yêu cầu xử lý hoàn bồi tiền cọc'],
        ]);

        // 2. Vé hỗ trợ mẫu để trống cho người dùng thực test
    }
}