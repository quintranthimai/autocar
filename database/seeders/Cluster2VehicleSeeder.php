<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class Cluster2VehicleSeeder extends Seeder
{
    public function run(): void
    {
        // ====================================================================
        // 1. SEED BẢNG categories (Phân khúc xe) [cite: 18, 19]
        // ====================================================================
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'sedan', 'display_name' => 'Xe gầm thấp đô thị'], 
            ['id' => 2, 'name' => 'suv', 'display_name' => 'Xe gầm cao đi tỉnh'], 
            ['id' => 3, 'name' => 'mpv', 'display_name' => 'Xe đa dụng gia đình'], 
            ['id' => 4, 'name' => 'hatchback', 'display_name' => 'Xe nhỏ gọn linh hoạt'], 
        ]);

        // ====================================================================
        // 2. SEED BẢNG fuels (Danh mục Nhiên liệu) [cite: 20, 21]
        // ====================================================================
        DB::table('fuels')->insert([
            ['id' => 1, 'name' => 'gasoline', 'display_name' => 'Xăng'], 
            ['id' => 2, 'name' => 'electricity', 'display_name' => 'Điện'], 
            ['id' => 3, 'name' => 'diesel', 'display_name' => 'Dầu Diesel'], 
        ]);

        // ====================================================================
        // 3. SEED BẢNG transmissions (Danh mục Hộp số) [cite: 22, 23]
        // ====================================================================
        DB::table('transmissions')->insert([
            ['id' => 1, 'name' => 'automatic', 'display_name' => 'Số tự động'], 
            ['id' => 2, 'name' => 'manual', 'display_name' => 'Số sàn'], 
        ]);

        // ====================================================================
        // 4. SEED BẢNG car_models (Dòng xe & Thông số cố định) [cite: 24, 25, 26]
        // ====================================================================
        DB::table('car_models')->insert([
            ['id' => 1, 'category_id' => 1, 'fuel_id' => 1, 'transmission_id' => 1, 'brand_name' => 'Toyota', 'model_name' => 'Vios', 'seat_count' => 5, 'fuel_consumption' => '5.8L/100km'], 
            ['id' => 2, 'category_id' => 2, 'fuel_id' => 2, 'transmission_id' => 1, 'brand_name' => 'VinFast', 'model_name' => 'VF8', 'seat_count' => 5, 'fuel_consumption' => '9L/100km'], 
            ['id' => 3, 'category_id' => 3, 'fuel_id' => 1, 'transmission_id' => 1, 'brand_name' => 'Mitsubishi', 'model_name' => 'Xpander', 'seat_count' => 7, 'fuel_consumption' => '6.9L/100km'],
            ['id' => 4, 'category_id' => 4, 'fuel_id' => 1, 'transmission_id' => 2, 'brand_name' => 'Hyundai', 'model_name' => 'Grand i10', 'seat_count' => 5, 'fuel_consumption' => '5.4L/100km'], 
            ['id' => 5, 'category_id' => 2, 'fuel_id' => 3, 'transmission_id' => 1, 'brand_name' => 'Ford', 'model_name' => 'Ranger Wildtrak', 'seat_count' => 5, 'fuel_consumption' => '8.0L/100km'], 
        ]);

        // ====================================================================
        // 5. SEED BẢNG TIỆN ÍCH XE (amenity_types, amenities) [cite: 39, 42]
        // ====================================================================
        // Phân loại tiện ích [cite: 39]
        DB::table('amenity_types')->insert([
            ['id' => 1, 'name' => 'safety', 'display_name' => 'Tính năng an toàn'],
            ['id' => 2, 'name' => 'comfort', 'display_name' => 'Tiện nghi khoang lái'],
        ]);

        // Chi tiết danh mục tiện ích [cite: 42]
        DB::table('amenities')->insert([
            ['id' => 1, 'amenity_type_id' => 1, 'name' => 'camera_360', 'display_name' => 'Camera 360 độ', 'icon' => 'icon-cam-360'], 
            ['id' => 2, 'amenity_type_id' => 1, 'name' => 'dashcam', 'display_name' => 'Camera hành trình', 'icon' => 'icon-dashcam'], 
            ['id' => 3, 'amenity_type_id' => 2, 'name' => 'bluetooth', 'display_name' => 'Kết nối Bluetooth', 'icon' => 'icon-bluetooth'], 
            ['id' => 4, 'amenity_type_id' => 2, 'name' => 'sunroof', 'display_name' => 'Cửa sổ trời toàn cảnh', 'icon' => 'icon-sunroof'], 
        ]);
    }
}