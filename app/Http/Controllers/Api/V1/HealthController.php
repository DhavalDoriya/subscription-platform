<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /** Liveness: the app answers. */
    public function __invoke(): JsonResponse
    {
        return ApiResponse::success(['status' => 'ok']);
    }

    /** Readiness for the load balancer: the database and the cache respond. Reports only ok or down, never details. */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'cache' => $this->check(fn () => Cache::put('health', true, 10) && Cache::get('health') === true),
        ];
        $ok = ! in_array('down', $checks, true);

        return response()->json(['data' => ['status' => $ok ? 'ok' : 'down', 'checks' => $checks]], $ok ? 200 : 503);
    }

    private function check(callable $probe): string
    {
        try {
            return $probe() === false ? 'down' : 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'down';
        }
    }
}
