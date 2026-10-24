<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListCustomersRequest;
use App\Http\Resources\Admin\CustomerResource;
use App\Models\User;
use App\Services\Auth\RefreshTokenService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(ListCustomersRequest $request): JsonResponse
    {
        $customers = User::role('customer', 'api')
            ->when($request->has('blocked') && $request->validated('blocked') !== null, fn ($query) => $request->boolean('blocked') ? $query->whereNotNull('blocked_at') : $query->whereNull('blocked_at'))
            ->when($request->search(), function ($query, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn ($q) => $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like]));
            })
            ->orderBy($request->sortColumn(ListCustomersRequest::SORTABLE, 'created_at'), $request->sortDirection())
            ->paginate($request->perPage());

        return ApiResponse::paginated($customers, CustomerResource::class);
    }

    public function show(string $customer): JsonResponse
    {
        return ApiResponse::success(new CustomerResource($this->find($customer)->load('providerLinks.provider')));
    }

    public function block(Request $request, string $customer, RefreshTokenService $tokens): JsonResponse
    {
        $user = $this->find($customer);

        if (! $user->isBlocked()) {
            $user->forceFill(['blocked_at' => now()])->save();
            $tokens->revokeAllFor($user);
            activity()->causedBy($request->user('api'))->performedOn($user)->log('customer.blocked');
        }

        return ApiResponse::success(new CustomerResource($user));
    }

    public function unblock(Request $request, string $customer): JsonResponse
    {
        $user = $this->find($customer);

        if ($user->isBlocked()) {
            $user->forceFill(['blocked_at' => null])->save();
            activity()->causedBy($request->user('api'))->performedOn($user)->log('customer.unblocked');
        }

        return ApiResponse::success(new CustomerResource($user));
    }

    private function find(string $id): User
    {
        return User::role('customer', 'api')->findOrFail($id);
    }
}
