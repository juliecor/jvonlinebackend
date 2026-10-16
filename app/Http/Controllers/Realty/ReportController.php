<?php

namespace App\Http\Controllers\Realty;

use App\Http\Controllers\Controller;
use App\Support\Reports\SalesReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** The sales report page (and its PDF): a period by name, or two dates. */
class ReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(array_keys(SalesReport::PERIODS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $report = SalesReport::for($request->user(), $data['period'] ?? null, $data['from'] ?? null, $data['to'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['from' => [$e->getMessage()]]], 422);
        }

        return response()->json($report->toArray());
    }
}
