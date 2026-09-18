<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class BannerController extends Controller
{
    /**
     * Get all banners (Admin)
     */
    public function index()
    {
        $banners = Banner::orderBy('display_order', 'asc')->get();
        return response()->json([
            'success' => true,
            'data' => $banners
        ], 200);
    }

    /**
     * Get active banners (Public)
     */
    public function publicIndex()
    {
        $banners = Banner::where('is_active', true)
            ->orderBy('display_order', 'asc')
            ->get();
            
        return response()->json([
            'success' => true,
            'data' => $banners
        ], 200);
    }

    /**
     * Create a new banner
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120', // Max 5MB
            'redirect_url' => 'nullable|url|max:255',
            'position' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'display_order' => 'integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi xác thực dữ liệu',
                'errors' => $validator->errors()
            ], 422);
        }

        // Upload image to local storage
        $imageUrl = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('banners', 'public');
            $imageUrl = '/storage/' . $path;
        }

        $banner = Banner::create([
            'title' => $request->title,
            'image_url' => $imageUrl,
            'redirect_url' => $request->redirect_url,
            'position' => $request->position ?? 'home_main',
            'is_active' => $request->has('is_active') ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN) : true,
            'display_order' => $request->display_order ?? 0
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Thêm banner thành công',
            'data' => $banner
        ], 201);
    }

    /**
     * Get a specific banner
     */
    public function show($id)
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy banner'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $banner
        ], 200);
    }

    /**
     * Update an existing banner
     */
    public function update(Request $request, $id)
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy banner'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
            'redirect_url' => 'nullable|url|max:255',
            'position' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'display_order' => 'integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi xác thực dữ liệu',
                'errors' => $validator->errors()
            ], 422);
        }

        // Handle image upload if provided
        $imageUrl = $banner->image_url;
        if ($request->hasFile('image')) {
            // Optional: Delete old image
            if ($banner->image_url && str_starts_with($banner->image_url, '/storage/')) {
                $oldPath = str_replace('/storage/', '', $banner->image_url);
                Storage::disk('public')->delete($oldPath);
            }
            
            $path = $request->file('image')->store('banners', 'public');
            $imageUrl = '/storage/' . $path;
        }

        $banner->update([
            'title' => $request->title,
            'image_url' => $imageUrl,
            'redirect_url' => $request->redirect_url ?? $banner->redirect_url,
            'position' => $request->position ?? $banner->position,
            'is_active' => $request->has('is_active') ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN) : $banner->is_active,
            'display_order' => $request->display_order ?? $banner->display_order
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật banner thành công',
            'data' => $banner
        ], 200);
    }

    /**
     * Delete a banner
     */
    public function destroy($id)
    {
        $banner = Banner::find($id);

        if (!$banner) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy banner'
            ], 404);
        }

        // Optional: Delete image
        if ($banner->image_url && str_starts_with($banner->image_url, '/storage/')) {
            $oldPath = str_replace('/storage/', '', $banner->image_url);
            Storage::disk('public')->delete($oldPath);
        }

        $banner->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa banner thành công'
        ], 200);
    }
}
