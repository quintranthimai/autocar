<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class SystemSettingController extends Controller
{
    /**
     * Get all system settings
     */
    public function index()
    {
        $settings = SystemSetting::all();
        
        // Convert to key-value object for easier frontend consumption
        $settingsObj = [];
        foreach ($settings as $setting) {
            $settingsObj[$setting->setting_key] = [
                'id' => $setting->id,
                'value' => $setting->setting_value,
                'description' => $setting->description
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $settingsObj,
            'raw' => $settings
        ], 200);
    }

    /**
     * Get all system settings (Public version, no auth needed)
     */
    public function publicIndex()
    {
        // For frontend to consume public settings like commission rate, default times
        $settings = SystemSetting::all()->pluck('setting_value', 'setting_key');
        
        return response()->json([
            'success' => true,
            'data' => $settings
        ], 200);
    }

    /**
     * Create a new setting
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'setting_key' => 'required|string|max:100|unique:system_settings,setting_key',
            'setting_value' => 'required|string',
            'description' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi xác thực dữ liệu',
                'errors' => $validator->errors()
            ], 422);
        }

        $setting = SystemSetting::create([
            'setting_key' => $request->setting_key,
            'setting_value' => $request->setting_value,
            'description' => $request->description,
            'updated_by' => Auth::id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Thêm cấu hình thành công',
            'data' => $setting
        ], 201);
    }

    /**
     * Update an existing setting
     */
    public function update(Request $request, $id)
    {
        $setting = SystemSetting::find($id);

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy cấu hình'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'setting_value' => 'required|string',
            'description' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi xác thực dữ liệu',
                'errors' => $validator->errors()
            ], 422);
        }

        $setting->update([
            'setting_value' => $request->setting_value,
            'description' => $request->has('description') ? $request->description : $setting->description,
            'updated_by' => Auth::id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật cấu hình thành công',
            'data' => $setting
        ], 200);
    }

    /**
     * Delete a setting
     */
    public function destroy($id)
    {
        $setting = SystemSetting::find($id);

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy cấu hình'
            ], 404);
        }

        $setting->delete();

        return response()->json([
            'success' => true,
            'message' => 'Xóa cấu hình thành công'
        ], 200);
    }

    /**
     * Batch update multiple settings
     */
    public function batchUpdate(Request $request)
    {
        $settingsData = $request->input('settings', []);
        
        foreach ($settingsData as $key => $value) {
            SystemSetting::updateOrCreate(
                ['setting_key' => $key],
                [
                    'setting_value' => is_bool($value) ? ($value ? 'true' : 'false') : (string)$value,
                    'updated_by' => Auth::id()
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật cấu hình thành công'
        ], 200);
    }
}
