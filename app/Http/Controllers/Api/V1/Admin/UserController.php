<?php

namespace App\Http\Controllers\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;
use App\Models\User;
use App\Models\Role;

/**
 * ============================================================================
 * LỚP USER CONTROLLER (QUẢN CHỊU, TRUYỀN HẠN VÀ ĐIỀU PHỐI HỆ THỐNG NGƯỜI DÙNG)
 * ============================================================================
 * Controller phân hệ Quản trị tối cao (CMS Admin) phụ trách toàn diện vòng đời
 * Tài khoản Người dùng trên Sàn: Tra cứu, cập nhật thông tin cá nhân, định đoạt
 * vai trò quyền hạn (Roles), vô hiệu hóa (Blacklisting), thiết lập Ví nội bộ và
 * bảo bọc chốt chặn an toàn cho các Tài khoản Root/Master Admin bất khả xâm phạm.
 */
class UserController
{
    /**
     * ========================================================================
     * CÁC HẰNG SỐ CẤU HÌNH BẢO MẬT & PHẠM VI TRUY HÀNH QUYỀN VAI TRÒ
     * ========================================================================
     */
    
    /** @var array Các quyền được hiển thị và can thiệp trên TOÀN TRƯỜNG danh bạ người dùng */
    private const READ_ALL_USER_ROLES = [
        'master_admin',
        'admin',
        'coordinator',
    ];

    /** @var array Các quyền hỗ trợ hạn chế (Chỉ được phép xem danh sách người dùng đầu cuối) */
    private const READ_LIMITED_USER_ROLES = [
        'ops_admin',
        'support',
    ];

    /** @var array Phạm vi tài khoản mục tiêu mà nhóm quyền hạn chế được phép tra cứu */
    private const LIMITED_READ_TARGET_ROLE_SLUGS = [
        'renter',
        'owner',
    ];

    /** @var array Danh sách vai trò TUYỆT ĐỐI KHÔNG ĐƯỢC CẤP PHÁT qua API khởi tạo hoặc cập nhật thông thường */
    private const FORBIDDEN_ROLE_SLUGS = [
        'admin',
        'master_admin',
    ];

    /** @var array Cụm vai trò được phép cấp phát cho Đội ngũ Nhân viên nội bộ (Staff/Support) */
    private const STAFF_ASSIGNABLE_ROLES = [
        'coordinator',
        'staff',
        'ops_admin',
        'support',
    ];

    /**
     * ========================================================================
     * 1. HÀM TRỢ GIÚP PHẦN TRUNG TÍNH (PRIVATE HELPER) & CHỐT CHẶN BẢO MẬT
     * ========================================================================
     */

    /**
     * Chốt chặn xác định đặc quyền Quản trị viên tối cao (Master Admin hoặc tài khoản gốc).
     *
     * @param  \Illuminate\Http\Request  $request  Đối tượng Request hiện hành.
     * @return \Illuminate\Http\JsonResponse|null  Phản hồi lỗi 403 nếu thiếu đặc quyền hoặc null nếu hợp lệ.
     */
    private function ensureMasterAdmin(Request $request)
    {
        $user = $request->user();
        $isMasterAdmin = $user->roles->contains(fn ($role) => in_array($role->slug, ['master_admin', 'admin'], true)) || $user->email === 'admin@autocar.vn';

        if (!$isMasterAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi phân quyền: Chỉ Master Admin mới được phép thao tác chức năng này!'
            ], 403);
        }

        return null;
    }

    /**
     * Trích xuất danh bạ mã chuỗi (slug) quyền hạn của tài khoản đang thao tác.
     *
     * @param  \Illuminate\Http\Request  $request  Đối tượng Request.
     * @return array                               Mảng chứa slug các quyền của người dùng.
     */
    private function getCurrentUserRoleSlugs(Request $request): array
    {
        return $request->user()->roles->pluck('slug')->all();
    }

    /**
     * Đối soát giao thoa mảng để kiểm định sự tồn tại của ít nhất một quyền hợp lệ.
     *
     * @param  array  $currentRoles  Danh sách quyền hiện sở hữu.
     * @param  array  $allowedRoles  Danh sách quyền cho phép truy cập.
     * @return bool                  True nếu khớp quyền, False nếu trái phép.
     */
    private function hasAnyRole(array $currentRoles, array $allowedRoles): bool
    {
        return !empty(array_intersect($currentRoles, $allowedRoles));
    }

    /**
     * Kiểm chứng quyền tự tin tra cứu danh sách người dùng (Nhóm quyền Đầy đủ hoặc Hạn chế).
     *
     * @param  \Illuminate\Http\Request  $request  Đối tượng Request hiện hành.
     * @return \Illuminate\Http\JsonResponse|null  Phản hồi lỗi 403 hoặc null nếu vượt qua chốt chặn.
     */
    private function ensureAdminCanReadUsers(Request $request)
    {
        $allowedReadRoles = array_merge(self::READ_ALL_USER_ROLES, self::READ_LIMITED_USER_ROLES);
        $currentRoleSlugs = $this->getCurrentUserRoleSlugs($request);

        $canRead = $this->hasAnyRole($currentRoleSlugs, $allowedReadRoles);

        if (!$canRead) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi phân quyền: Bạn không có quyền xem danh sách người dùng.'
            ], 403);
        }

        return null;
    }

    /**
     * Đánh giá xem Quản trị viên có được quyền quan sát toàn bộ người dùng hay không.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    private function canReadAllUsers(Request $request): bool
    {
        $currentRoleSlugs = $this->getCurrentUserRoleSlugs($request);
        return $this->hasAnyRole($currentRoleSlugs, self::READ_ALL_USER_ROLES);
    }

    /**
     * Kiểm định cụ thể Quyền đọc của Admin đối với một Tài khoản mục tiêu cá biệt.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\User          $targetUser
     * @return bool
     */
    private function canReadTargetUser(Request $request, User $targetUser): bool
    {
        if ($this->canReadAllUsers($request)) {
            return true;
        }

        $currentRoleSlugs = $this->getCurrentUserRoleSlugs($request);
        $isLimitedReader = $this->hasAnyRole($currentRoleSlugs, self::READ_LIMITED_USER_ROLES);

        if (!$isLimitedReader) {
            return false;
        }

        $targetRoleSlugs = $targetUser->roles->pluck('slug')->all();

        return $this->hasAnyRole($targetRoleSlugs, self::LIMITED_READ_TARGET_ROLE_SLUGS);
    }

    /**
     * ========================================================================
     * 2. HÀM DANH SÁCH: TRUY VẤN VÀ HẠN TRỊ BẢNG THÔNG TIN TOÀN BỘ SÀN
     * ========================================================================
     * Liệt kê danh bạ tài khoản theo đúng thẩm quyền được cấp phát:
     * - Quyền hạn chế (Ops/Support) bị tự động bó hẹp chỉ xem Khách thuê & Chủ xe.
     * - Tích hợp cụm bộ lọc theo Mã quyền (role_slug), từ khóa đa năng (Tên/Email/SĐT).
     * - Tải đi kèm quan hệ quyền hạn và trạng thái Ví tiền để hiển thị đầy đủ trên giao diện.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số bộ lọc và quy mô phân trang per_page.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON danh sách phân trang trang bị trọn gói.
     */
    public function index(Request $request)
    {
        $unauthorized = $this->ensureAdminCanReadUsers($request);
        if ($unauthorized) {
            return $unauthorized;
        }

        $query = User::with(['roles', 'wallet'])
            ->orderByDesc('created_at');

        // Bó hẹp phạm vi quét truy vấn nếu Người xem chỉ mang quyền hạn chế (Limited Reader)
        if (!$this->canReadAllUsers($request)) {
            $query->whereHas('roles', function ($roleQuery) {
                $roleQuery->whereIn('slug', self::LIMITED_READ_TARGET_ROLE_SLUGS);
            });
        }

        // Lọc danh bạ theo một quyền cụ thể do giao diện yêu cầu
        if ($request->filled('role_slug') && $request->role_slug !== 'all') {
            $roleSlug = $request->role_slug;
            $query->whereHas('roles', function ($roleQuery) use ($roleSlug) {
                $roleQuery->where('slug', $roleSlug);
            });
        }

        // Tìm kiếm tự do khớp một phần trường thông tin cá nhân
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        $users = $query->paginate((int) $request->input('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * ========================================================================
     * 3. HÀM CHI TIẾT: XEM TOÀN TRƯỜNG HỒ SƠ VÀ LỊCH SỬ HOẠT ĐỘNG
     * ========================================================================
     * Hiển thị đầy đủ tiểu sử Tài khoản, cơ quan Ví tài trợ kèm phân trang phụ động:
     * - Khi đối tượng là `renter`: Truy thu mảng lịch sử Đơn đặt xe (Bookings).
     * - Khi đối tượng là `owner`: Truy thu mảng tài sản Phương tiện niêm yết (Vehicles).
     * Cung cấp tùy chọn điều phối trạng thái và giới hạn phân trang từng cụm dữ liệu phụ.
     *
     * @param  \Illuminate\Http\Request  $request  Tham số phân trang phụ (bookings_page, vehicles_page...).
     * @param  int                       $id       ID của Tài khoản Khách hàng cần rà soát.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON trọn bộ hồ sơ đa nhiệm.
     */
    public function show(Request $request, int $id)
    {
        $unauthorized = $this->ensureAdminCanReadUsers($request);
        if ($unauthorized) {
            return $unauthorized;
        }

        $user = User::with(['roles', 'wallet'])->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy người dùng.'
            ], 404);
        }

        if (!$this->canReadTargetUser($request, $user)) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi phân quyền: Bạn không có quyền xem chi tiết người dùng này.'
            ], 403);
        }

        $roleSlugs = $user->roles->pluck('slug')->all();

        $bookingsPerPage = max(1, min((int) $request->input('bookings_per_page', 10), 50));
        $vehiclesPerPage = max(1, min((int) $request->input('vehicles_per_page', 10), 50));
        $includeBookings = $request->boolean('include_bookings', true);
        $includeVehicles = $request->boolean('include_vehicles', true);

        $bookingsStatus = $request->input('bookings_status');
        $vehiclesStatus = $request->input('vehicles_status');

        // Phân nhóm 1: Trì trích danh bạ Đơn đặt xe nếu người dùng đóng vai trò Renter
        $bookings = null;
        if ($includeBookings && in_array('renter', $roleSlugs, true)) {
            $bookingsQuery = $user->bookings()
                ->with([
                    'vehicle:id,car_model_id,license_plate,status',
                    'vehicle.carModel:id,brand_name,model_name',
                ])
                ->orderByDesc('created_at');

            if (!empty($bookingsStatus) && $bookingsStatus !== 'all') {
                $bookingsQuery->where('status', $bookingsStatus);
            }

            $bookings = $bookingsQuery->paginate(
                $bookingsPerPage,
                ['*'],
                'bookings_page',
                (int) $request->input('bookings_page', 1)
            );
        }

        // Phân nhóm 2: Trì trích danh bạ Phương tiện kinh doanh nếu người dùng đóng vai trò Owner
        $vehicles = null;
        if ($includeVehicles && in_array('owner', $roleSlugs, true)) {
            $vehiclesQuery = $user->vehicles()
                ->with(['carModel:id,brand_name,model_name'])
                ->orderByDesc('created_at');

            if (!empty($vehiclesStatus) && $vehiclesStatus !== 'all') {
                $vehiclesQuery->where('status', $vehiclesStatus);
            }

            $vehicles = $vehiclesQuery->paginate(
                $vehiclesPerPage,
                ['*'],
                'vehicles_page',
                (int) $request->input('vehicles_page', 1)
            );
        }

        $payload = $user->toArray();
        $payload['bookings'] = $bookings;
        $payload['vehicles'] = $vehicles;

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    /**
     * ========================================================================
     * 4. HÀM KHỞI TẠO TÀI KHOẢN (STORE): TẠO MỚI NGƯỜI DÙNG T BỀ MẶT CMS
     * ========================================================================
     * Hỗ trợ Master Admin khởi tạo tài khoản, chỉ định quyền và tạo đồng thời
     * Ví tài sản (Wallet 0 VND) dưới sự giám sát của DB Transaction. Cực kỳ thắt
     * chặt bảo mật: NGĂN CHẶN tuyệt đối mọi âm mưu tạo tài khoản thuộc nhóm
     * FORBIDDEN_ROLE_SLUGS (Admin tối cao) nhằm hạn chế rò rỉ phân quyền vô đơn.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu biểu mẫu đăng ký người dùng.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON tài khoản tạo thành công (201).
     */
    public function store(Request $request)
    {
        $unauthorized = $this->ensureMasterAdmin($request);
        if ($unauthorized) {
            return $unauthorized;
        }

        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'email'          => 'required|string|email|max:255|unique:users,email',
            'password'       => 'required|string|min:6',
            'phone'          => 'nullable|string|max:15|unique:users,phone',
            'address'        => 'nullable|string|max:500',
            'role_slug'      => 'required|string|exists:roles,slug',
            'is_blacklisted' => 'nullable|boolean',
        ]);

        // Chốt chặn bảo mật không cho tạo trực tiếp tài khoản quyền Quản trị Tối cao
        if (in_array($validated['role_slug'], self::FORBIDDEN_ROLE_SLUGS, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Không được phép tạo tài khoản quyền cao nhất.'
            ], 403);
        }

        DB::beginTransaction();
        try {
            $user = User::create([
                'name'           => $validated['name'],
                'email'          => $validated['email'],
                'password'       => Hash::make($validated['password']),
                'phone'          => $validated['phone'] ?? null,
                'address'        => $validated['address'] ?? null,
                'is_blacklisted' => $validated['is_blacklisted'] ?? false,
                'kyc_status'     => 'approved',
            ]);

            $role = Role::where('slug', $validated['role_slug'])->first();
            $user->roles()->sync([$role->id]);

            // Khởi tạo tài sản gốc Ví nội bộ (Wallet) đi kèm theo mặc định
            $user->wallet()->create([
                'available_balance' => 0,
                'deposit_balance' => 0,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Thêm người dùng thành công.',
                'data'    => $user->load(['roles', 'wallet']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ========================================================================
     * 5. HÀM CẬP NHẬT (UPDATE): SỬA HẢI BẮT HỒ SƠ & BẢO DIỄN VƯƠNG TRUYỀN
     * ========================================================================
     * Cập nhật lý lịch, mật khẩu hoặc khóa danh tính (Blacklist) của Người dùng.
     * Các quy tắc bảo toàn được thi hành cực kỳ khắt khe:
     * - KHÔNG THỂ can thiệp sang quyền tối cao FORBIDDEN_ROLE_SLUGS.
     * - Tài khoản Master Admin Gốc (ID=1 / Email admin@autocar.vn) là BẤT HẢ XÂM PHẠM
     *   (nghiêm cấm bóp méo quyền hạn hoặc thắt khóa vô hiệu hóa).
     * - Nghiêm cấm tráo đổi quyền hạn của nhóm Khách hàng đầu cuối (Owner <-> Renter).
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu cần sửa đổi (đã được Rule::unique loại trừ ID).
     * @param  int                       $id       ID Người dùng cần điều chỉnh.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON thông báo xác nhận cập nhật thành công.
     */
    public function update(Request $request, int $id)
    {
        $unauthorized = $this->ensureMasterAdmin($request);
        if ($unauthorized) {
            return $unauthorized;
        }

        $user = User::with('roles')->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy người dùng.'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => 'sometimes|required|string|min:6',
            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:15',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
            'address' => 'sometimes|nullable|string|max:500',
            'role_slug' => 'sometimes|required|string|exists:roles,slug',
            'is_blacklisted' => 'sometimes|boolean',
        ]);

        if (
            array_key_exists('role_slug', $validated)
            && in_array($validated['role_slug'], self::FORBIDDEN_ROLE_SLUGS, true)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Không được phép gán quyền cao nhất.'
            ], 403);
        }

        // Chốt chặn bảo vệ: Tài khoản Root Gốc là bất khả xâm phạm
        $isTargetProtected = $user->email === 'admin@autocar.vn' || $user->id === 1;

        if ($isTargetProtected) {
            if (array_key_exists('role_slug', $validated) || (array_key_exists('is_blacklisted', $validated) && $validated['is_blacklisted'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tài khoản Master Admin gốc là bất khả xâm phạm (không thể đổi quyền hay vô hiệu hóa)!'
                ], 403);
            }
        }

        // Ngăn cấm tự do tráo vai trò đối với tài khoản kinh doanh thực của Khách/Chủ xe
        $isCustomer = $user->roles->contains(fn($r) => in_array($r->slug, ['owner', 'renter'], true));
        if ($isCustomer && array_key_exists('role_slug', $validated) && !empty($validated['role_slug'])) {
            return response()->json([
                'success' => false,
                'message' => 'Không được phép thay đổi vai trò của tài khoản Khách thuê và Chủ xe.'
            ], 403);
        }

        DB::beginTransaction();
        try {
            $payload = [];
            foreach (['name', 'email', 'phone', 'address', 'is_blacklisted'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $payload[$field] = $validated[$field];
                }
            }

            if (array_key_exists('password', $validated)) {
                $payload['password'] = Hash::make($validated['password']);
            }

            if (!empty($payload)) {
                $user->update($payload);
            }

            if (array_key_exists('role_slug', $validated)) {
                $role = Role::where('slug', $validated['role_slug'])->first();
                $user->roles()->sync([$role->id]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật người dùng thành công.',
                'data'    => $user->fresh()->load(['roles', 'wallet']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ========================================================================
     * 6. HÀM XÓA BẢN GHI (DESTROY): HỦY TÀI KHOẢN KÈM BẢO HIỂM RÀNG BUỘC
     * ========================================================================
     * Thu hồi tài khoản Người dùng khỏi Sàn giao dịch với 3 tầng cảnh vệ:
     * 1. Không thể tự thiêu hủy tài khoản của chính Quản trị viên đang thao tác.
     * 2. Bảo tạng tài khoản Master Admin Gốc không bao giờ bị xóa.
     * 3. Xử lý ngoại lệ QueryException: Nếu tài khoản đang chứa dữ liệu giao dịch
     *    hoặc đơn đặt xe (Khóa ngoại), từ chối gỡ bỏ để giữ vẹn toàn bộ sổ sách.
     *
     * @param  \Illuminate\Http\Request  $request  Đối tượng Request hiện hành.
     * @param  int                       $id       ID Tài khoản mục tiêu.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON xác nhận hay từ chối thao tác.
     */
    public function destroy(Request $request, int $id)
    {
        $unauthorized = $this->ensureMasterAdmin($request);
        if ($unauthorized) {
            return $unauthorized;
        }

        if ((int) $request->user()->id === $id) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể tự xóa tài khoản của chính bạn.'
            ], 422);
        }

        $user = User::with('roles')->find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy người dùng.'
            ], 404);
        }

        $isRootAccount = $user->email === 'admin@autocar.vn' || $user->id === 1;

        if ($isRootAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Không được phép xóa tài khoản Master Admin gốc của hệ thống.'
            ], 403);
        }

        try {
            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'Xóa người dùng thành công.',
            ]);
        } catch (QueryException $e) {
            // Chốt chặn cơ sở dữ liệu: Tránh đứt gãy khóa ngoại tài chính hoặc đơn đặt xe
            return response()->json([
                'success' => false,
                'message' => 'Không thể xóa người dùng vì còn dữ liệu liên quan.',
            ], 422);
        }
    }

    /**
     * ========================================================================
     * 7. HÀM TRA CỨU QUYỀN HÀNH (GET ASSIGNABLE ROLES): DANH MỤC VAI TRÒ HỢP LỆ
     * ========================================================================
     * Trích xuất danh sách các quyền hạn hợp pháp có thể gán khi Quản trị viên
     * tiến hành khởi tạo mới tài khoản cán bộ, nhân viên (Staff/Ops/Support).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAssignableRoles(Request $request)
    {
        $unauthorized = $this->ensureMasterAdmin($request);
        if ($unauthorized) {
            return $unauthorized;
        }

        $roles = Role::query()
            ->whereIn('slug', self::STAFF_ASSIGNABLE_ROLES)
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'description']);

        return response()->json([
            'success' => true,
            'data'    => $roles,
        ]);
    }

    /**
     * ========================================================================
     * 8. HÀM TẠO NHÂN VIÊN (CREATE STAFF): BẮC ĐẨU NHƠN SU TIỂU CỤC
     * ========================================================================
     * Chức năng độc quyền của Master Admin giúp gia tăng nguồn lực vận hành nội bộ:
     * - Xác thực danh tính biểu mẫu, kiểm rà cấm các quyền tối cao FORBIDDEN_ROLE_SLUGS.
     * - Tạo hồ sơ Người dùng với `kyc_status` lập tức được phán chuẩn `approved`.
     * - Khởi tạo tài sản Ví liên đới và gắn kết đúng mã quy mô nhân viên.
     *
     * @param  \Illuminate\Http\Request  $request  Dữ liệu hồ sơ nhân sự mới.
     * @return \Illuminate\Http\JsonResponse       Phản hồi JSON tạo mới thành công (201).
     */
    public function createStaff(Request $request)
    {
        $unauthorized = $this->ensureMasterAdmin($request);
        if ($unauthorized) {
            return $unauthorized;
        }

        // 1 & 2. Kiểm tra thẩm định tính chính xác từ tham số đầu vào
        $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'required|string|email|max:255|unique:users',
            'password'  => 'required|string|min:6',
            'phone'     => 'required|string|max:15',
            'role_slug' => 'required|string|exists:roles,slug',
        ]);

        // Chốt chặn bảo mật: Tuyệt đối ngăn chặn tạo tài khoản quyền Quản trị Tối cao qua luồng này
        if (in_array($request->role_slug, self::FORBIDDEN_ROLE_SLUGS, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Không được phép tạo tài khoản Admin tối cao từ chức năng này.'
            ], 403);
        }

        DB::beginTransaction();
        try {
            // 3. Khởi tạo tài khoản nghiệp vụ nhân viên
            $user = User::create([
                'name'       => $request->name,
                'email'      => $request->email,
                'password'   => Hash::make($request->password),
                'phone'      => $request->phone,
                'kyc_status' => 'approved',
            ]);

            // 4. Định đoạt và ràng buộc vai trò nghiệp vụ tương ứng (ops_admin/support/...)
            $role = Role::where('slug', $request->role_slug)->first();
            $user->roles()->attach($role->id);

            // 5. Đồng bộ cấu trúc Ví nội bộ đi kèm theo quy chuẩn hệ thống (số dư = 0)
            $user->wallet()->create([
                'available_balance' => 0, 
                'deposit_balance'   => 0
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tạo tài khoản nhân viên thành công!',
                'data'    => $user->load('roles')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
        }
    }
}