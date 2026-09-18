<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaticPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StaticPageController extends Controller
{
    /**
     * Get all static pages (Admin)
     */
    public function index()
    {
        $pages = StaticPage::all();
        return response()->json([
            'success' => true,
            'data' => $pages
        ], 200);
    }

    /**
     * Get a specific page by slug (Public)
     */
    public function getBySlug($slug)
    {
        $page = StaticPage::where('slug', $slug)->first();

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy trang'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $page
        ], 200);
    }

    /**
     * Create a new static page
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100|unique:static_pages,slug',
            'content' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi xác thực dữ liệu',
                'errors' => $validator->errors()
            ], 422);
        }

        // Auto-generate slug from title if not provided
        $slug = $request->slug ? Str::slug($request->slug) : Str::slug($request->title);
        
        // Ensure slug uniqueness
        $originalSlug = $slug;
        $counter = 1;
        while (StaticPage::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        $page = StaticPage::create([
            'title' => $request->title,
            'slug' => $slug,
            'content' => $request->content,
            'updated_by_admin_id' => Auth::id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Thêm trang tĩnh thành công',
            'data' => $page
        ], 201);
    }

    /**
     * Get a specific page (Admin)
     */
    public function show($id)
    {
        $page = StaticPage::find($id);

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy trang'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $page
        ], 200);
    }

    /**
     * Update an existing static page
     */
    public function update(Request $request, $id)
    {
        $page = StaticPage::find($id);

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy trang'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100|unique:static_pages,slug,'.$id,
            'content' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi xác thực dữ liệu',
                'errors' => $validator->errors()
            ], 422);
        }

        $slug = $request->slug ? Str::slug($request->slug) : Str::slug($request->title);

        $page->update([
            'title' => $request->title,
            'slug' => $slug,
            'content' => $request->content,
            'updated_by_admin_id' => Auth::id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật trang tĩnh thành công',
            'data' => $page
        ], 200);
    }

    /**
     * Delete a static page
     */
    public function destroy($id)
    {
        $page = StaticPage::find($id);

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy trang'
            ], 404);
        }

        $page->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa trang tĩnh thành công'
        ], 200);
    }
}
