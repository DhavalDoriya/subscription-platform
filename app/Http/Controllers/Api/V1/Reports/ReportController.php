<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Services\Reports\CsvExport;
use App\Services\Reports\ReportService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports for admins (every provider) and providers (their own business): the routes decide who calls it, and the
 * tenant scope narrows a provider's queries. Only an admin's `provider_id` filter is honoured.
 */
class ReportController extends Controller
{
    private const COLUMNS = [
        'revenue' => ['period', 'currency', 'payments', 'gross', 'refunded', 'net_revenue', 'commission'],
        'churn' => ['period', 'new', 'canceled'],
        'failures' => ['reason', 'count', 'amount'],
        'payments' => ['date', 'provider', 'status', 'currency', 'amount', 'refunded', 'failure_code'],
        'commission' => ['date', 'provider', 'currency', 'gross', 'refunded', 'rate_percent', 'commission', 'commission_refunded', 'net_commission'],
    ];

    public function __construct(private ReportService $reports, private CsvExport $csv) {}

    public function revenue(ReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->rows('revenue', $request));
    }

    public function churn(ReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->rows('churn', $request));
    }

    public function failures(ReportRequest $request): JsonResponse
    {
        return ApiResponse::success($this->rows('failures', $request));
    }

    public function export(ReportRequest $request): StreamedResponse
    {
        $type = $request->validated('type') ?? 'revenue';
        $filename = "{$type}-{$request->from()->toDateString()}-to-{$request->to()->toDateString()}.csv";

        return $this->csv->download($filename, self::COLUMNS[$type], $this->rows($type, $request));
    }

    /** @return iterable<int, array<string, mixed>> */
    private function rows(string $type, ReportRequest $request): iterable
    {
        $provider = $request->user('api')->hasRole(['admin', 'super_admin'], 'api') ? $request->providerId() : null;

        return match ($type) {
            'revenue' => $this->reports->revenue($request->from(), $request->to(), $request->group(), $provider),
            'churn' => $this->reports->churn($request->from(), $request->to(), $provider),
            'failures' => $this->reports->failures($request->from(), $request->to(), $provider),
            'payments' => $this->reports->payments($request->from(), $request->to(), $provider),
            'commission' => $this->reports->commissions($request->from(), $request->to(), $provider),
        };
    }
}
