<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            Cluster1UserAndAssetSeeder::class,
            Cluster2VehicleSeeder::class,
            Cluster3BookingAndPaymentSeeder::class,
            Cluster4SupportAndDisputeSeeder::class,
            Cluster5SystemAndContentSeeder::class,
        ]);

        // PostgreSQL Sequence Sync Fix
        // Khi insert data có id cứng bằng Seeder trong PostgreSQL, các sequence (bộ đếm id tự động) không được cập nhật.
        // Dẫn đến lỗi "duplicate key value violates unique constraint" khi insert data mới.
        // Đoạn code này tự động đồng bộ lại toàn bộ sequence cho tất cả các bảng.
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql') {
            $tables = \Illuminate\Support\Facades\DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
            foreach ($tables as $table) {
                $tableName = $table->table_name;
                if ($tableName !== 'migrations') {
                    try {
                        \Illuminate\Support\Facades\DB::statement("SELECT setval('{$tableName}_id_seq', COALESCE((SELECT MAX(id) FROM {$tableName}), 1), (SELECT MAX(id) IS NOT NULL FROM {$tableName}))");
                    } catch (\Exception $e) {
                        // Bỏ qua nếu bảng không có sequence _id_seq
                    }
                }
            }
        }
    }
}