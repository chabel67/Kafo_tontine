<?php

namespace App\Modules\Tontine\Http\Controllers;

use App\Modules\Tontine\Infrastructure\Models\MarketCalendar;
use App\Shared\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AdminMarketCalendarController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(MarketCalendar::orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:100'],
            'cadence_type'  => ['required', 'string', 'in:every_n_days,named_weekdays,monthly'],
            'cadence_value' => ['required', 'array'],
            'timezone'      => ['sometimes', 'string', 'timezone'],
            'description'   => ['nullable', 'string'],
        ]);

        $calendar = MarketCalendar::create($data);

        return ApiResponse::created($calendar);
    }

    public function show(MarketCalendar $marketCalendar): JsonResponse
    {
        return ApiResponse::success($marketCalendar);
    }

    public function update(Request $request, MarketCalendar $marketCalendar): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
        ]);

        $marketCalendar->update($data);

        return ApiResponse::success($marketCalendar->fresh());
    }
}
