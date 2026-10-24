<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ProviderStatus;
use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\User;
use App\Services\Dunning\DunningMetrics;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function show(DunningMetrics $dunning): JsonResponse
    {
        $byStatus = Provider::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $statuses = collect(ProviderStatus::cases())->mapWithKeys(fn (ProviderStatus $status) => [$status->value => (int) ($byStatus[$status->value] ?? 0)]);
        $customers = User::role('customer', 'api');

        return ApiResponse::success([
            'providers' => ['total' => $statuses->sum(), 'by_status' => $statuses],
            'dunning' => $dunning->forPlatform(),
            'customers' => ['total' => (clone $customers)->count()],
            'recent_providers' => Provider::latest()->limit(5)->get(['id', 'name', 'slug', 'status', 'created_at'])
                ->map(fn (Provider $p) => ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'status' => $p->status->value, 'created_at' => $p->created_at->toIso8601String()]),
            'recent_customers' => (clone $customers)->latest()->limit(5)->get(['id', 'name', 'email', 'created_at'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'created_at' => $u->created_at->toIso8601String()]),
        ]);
    }
}
