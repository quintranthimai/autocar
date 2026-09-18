<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ============================================================================
 * LỚP MÔ HÌNH VAI TRÒ HỆ THỐNG (ROLE MODEL)
 * ============================================================================
 * Quản lý danh bạ quyền hạn, phạm vi truy cập và nhiệm vụ chuyên môn của tài
 * khoản trên cơ cấu Sàn (Ví dụ: Khách thuê xe, Chủ xe đối tác, Quản trị viên).
 */
class Role extends Model
{
    /**
     * Danh sách thuộc tính cấu hình danh xưng vai trò
     * @var array<string>
     */
    protected $fillable = ['name', 'slug', 'description'];

    /**
     * ========================================================================
     * 1. QUAN HỆ THAM CHIẾU DANH SÁCH TÀI KHOẢN (BELONGS TO MANY USERS)
     * ========================================================================
     * 1 Role thuộc về nhiều Users (Quan hệ nhiều-nhiều n-n qua bảng `role_user`).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id');
    }
}