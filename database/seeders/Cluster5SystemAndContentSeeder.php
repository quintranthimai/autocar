<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Cluster5SystemAndContentSeeder extends Seeder
{
    public function run(): void
    {
        // 1. BẢNG system_settings: Cấu hình tham số hệ thống động [cite: 517, 518]
        DB::table('system_settings')->insert([
            ['id' => 1, 'setting_key' => 'platform_fee_percent', 'setting_value' => '15', 'description' => 'Tỷ lệ chiết khấu hoa hồng Sàn thu từ Chủ xe (%)', 'updated_by' => 1],
            ['id' => 2, 'setting_key' => 'cancel_penalty_rate_owner', 'setting_value' => '10', 'description' => 'Tỷ lệ phạt chủ xe tự hủy chuyến vô cớ dựa trên tổng tiền ngày thuê (%)', 'updated_by' => 1],
            ['id' => 3, 'setting_key' => 'insurance_fee', 'setting_value' => '70000', 'description' => 'Phí bảo hiểm chuyến đi mặc định (VNĐ/ngày) - Dùng trong hàm tính toán Booking', 'updated_by' => 1],
            ['id' => 4, 'setting_key' => 'min_withdrawal_amount', 'setting_value' => '200000', 'description' => 'Hạn mức rút tiền tối thiểu từ ví nội bộ về ngân hàng (VNĐ)', 'updated_by' => 1],
            ['id' => 5, 'setting_key' => 'auto_approve_credit_score', 'setting_value' => '4.8', 'description' => 'Điểm tín nhiệm (Credit Score) tối thiểu để kích hoạt luồng Đặt xe nhanh (Duyệt tự động)', 'updated_by' => 1],
            ['id' => 6, 'setting_key' => 'customer_service_hotline', 'setting_value' => '1900 9999', 'description' => 'Số điện thoại tổng đài CSKH hiển thị ở Footer và các màn hình lỗi', 'updated_by' => 1],
        ]);

        // 2. BẢNG banners: Banner quảng cáo đa dạng vị trí và trạng thái [cite: 519, 520, 521]
        DB::table('banners')->insert([
            ['id' => 1, 'title' => 'Chương trình hợp tác xe điện VinFast 2026', 'image_url' => 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?w=1200&auto=format&fit=crop', 'redirect_url' => '/promotions/vinfast', 'position' => 'home_main', 'is_active' => true, 'display_order' => 1],
            ['id' => 2, 'title' => 'Trải nghiệm Thuê xe không cọc', 'image_url' => 'https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?w=1200&auto=format&fit=crop', 'redirect_url' => '/huong-dan/thue-xe-khong-coc', 'position' => 'home_main', 'is_active' => true, 'display_order' => 2],
            ['id' => 3, 'title' => 'Vivu du lịch hè - Nhận mã giảm giá', 'image_url' => 'https://images.unsplash.com/photo-1469854523086-cc02fe5d8800?w=1200&auto=format&fit=crop', 'redirect_url' => '/promotions/summer', 'position' => 'home_banner_bottom', 'is_active' => true, 'display_order' => 1],
            ['id' => 4, 'title' => 'Chúc mừng năm mới - Tết 2025', 'image_url' => 'https://images.unsplash.com/photo-1511919884226-fd3cad34687c?w=1200&auto=format&fit=crop', 'redirect_url' => '/promotions/tet', 'position' => 'home_main', 'is_active' => false, 'display_order' => 3], // Banner cũ đã được tắt đi (is_active = false)
        ]);

        // 3. BẢNG static_pages: Trang văn bản pháp lý tĩnh và Hướng dẫn [cite: 522, 523]
        DB::table('static_pages')->insert([
            [
                'id' => 1, 'slug' => 'chinh-sach-huy-chuyen', 'title' => 'Quy định và Chính sách hủy chuyến', 
                'content' => '<h1>Điều khoản hủy chuyến áp dụng từ năm 2026</h1><p>Khách hàng hủy xe trước 24h được hoàn 100% cọc. Chủ xe hủy sát giờ sẽ bị trừ thẳng tiền từ ví Ký quỹ để đền bù voucher cho khách.</p>', 
                'updated_by_admin_id' => 1
            ],
            [
                'id' => 2, 'slug' => 'dieu-khoan-su-dung', 'title' => 'Điều khoản sử dụng dịch vụ (TOS)', 
                'content' => '<h1>Điều khoản chung</h1><p>Bằng việc đăng ký tài khoản và định danh eKYC, bạn đồng ý tuân thủ các quy định về việc thuê và cho thuê tài sản trên nền tảng.</p>', 
                'updated_by_admin_id' => 1
            ],
            [
                'id' => 3, 'slug' => 'chinh-sach-bao-mat', 'title' => 'Chính sách bảo mật thông tin', 
                'content' => '<h1>Bảo mật Dữ liệu</h1><p>Chúng tôi cam kết mã hóa toàn bộ hình ảnh giấy phép lái xe, CCCD và không lưu trữ trực tiếp thông tin thẻ tín dụng của bạn trên máy chủ.</p>', 
                'updated_by_admin_id' => 1
            ],
            [
                'id' => 4, 'slug' => 'huong-dan-thue-xe', 'title' => 'Hướng dẫn thuê xe cho người mới', 
                'content' => '<h1>3 Bước thuê xe đơn giản</h1><ul><li>Bước 1: Tìm xe gần bạn.</li><li>Bước 2: Thanh toán cọc qua VNPAY hoặc Ví nội bộ.</li><li>Bước 3: Nhận xe, quay video 360 độ và vi vu.</li></ul>', 
                'updated_by_admin_id' => 1
            ],
        ]);
    }
}