<?php

namespace App\Http\Controllers\Api\V1\PublicApi;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use App\Support\Tenancy\SlugRules;
use Illuminate\Http\JsonResponse;

class SlugController extends Controller
{
    public function availability(string $slug, SlugRules $rules): JsonResponse
    {
        $reason = $rules->check($slug);

        return ApiResponse::success(['available' => $reason === null, 'reason' => $reason]);
    }
}
