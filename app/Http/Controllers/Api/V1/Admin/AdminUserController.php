<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminRequest;
use App\Http\Requests\ListRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\RefreshTokenService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super admin only (FR-AD9). New admins get the `admin` role; super admins are created from the CLI. */
class AdminUserController extends Controller
{
    public function index(ListRequest $request): JsonResponse
    {
        $admins = User::role(['admin', 'super_admin'], 'api')->with('roles')
            ->orderBy($request->sortColumn(['name', 'email', 'created_at'], 'created_at'), $request->sortDirection())
            ->paginate($request->perPage());

        return ApiResponse::paginated($admins, UserResource::class);
    }

    public function store(StoreAdminRequest $request): JsonResponse
    {
        $admin = User::create($request->validated());
        $admin->forceFill(['email_verified_at' => now()])->save();
        $admin->assignRole('admin');

        activity()->causedBy($request->user('api'))->performedOn($admin)->log('admin.created');

        return ApiResponse::created(new UserResource($admin->load('roles')));
    }

    public function destroy(Request $request, string $admin, RefreshTokenService $tokens): JsonResponse
    {
        $target = User::role(['admin', 'super_admin'], 'api')->findOrFail($admin);

        if ($target->is($request->user('api'))) {
            throw new ApiException(__('api.admin.cannot_remove_self'), 409, 'cannot_remove_self');
        }

        if ($target->hasRole('super_admin')) {
            throw new ApiException(__('api.admin.cannot_remove_super_admin'), 409, 'cannot_remove_super_admin');
        }

        $tokens->revokeAllFor($target);
        $target->delete();
        activity()->causedBy($request->user('api'))->performedOn($target)->log('admin.removed');

        return ApiResponse::noContent();
    }
}
