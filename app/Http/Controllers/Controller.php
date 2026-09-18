<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * ============================================================================
 * LỚP BỘ ĐIỀU KHIỂN CƠ HẠI TỔNG NỐI HỆ THỐNG (BASE CONTROLLER)
 * ============================================================================
 * Lớp điều khiển gốc (Abstract Base Controller) kế thừa từ tiêu chuẩn Laravel,
 * đóng vai trò là nền tảng khởi tạo chung cho mọi Controller thuộc các phân hệ
 * (Admin, Web, Auth) trong toàn bộ dự án Sàn Thuê Xe.
 */
class Controller extends BaseController
{
    // Tích hợp Trait hỗ trợ: Phân quyền truy cập (Authorize) & Xác thực form rà soát (Validate)
    use AuthorizesRequests, ValidatesRequests;
}
