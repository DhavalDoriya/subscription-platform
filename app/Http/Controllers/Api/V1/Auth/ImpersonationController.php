<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use App\Support\Auth\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    /** Called with the impersonation token itself: audits the end and kills the token. */
    public function end(Request $request): JsonResponse
    {
        $adminId = Impersonation::adminId();

        if (! $adminId) {
            throw new ApiException(__('api.admin.not_impersonating'), 409, 'not_impersonating');
        }

        activity()->causedBy(User::find($adminId))->performedOn($request->user('api'))->log('impersonation.ended');
        Auth::guard('api')->logout();

        return ApiResponse::noContent();
    }
}
