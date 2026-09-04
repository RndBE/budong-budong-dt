<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnvironmentController extends Controller
{
    /**
     * Lighting for a whole day, sampled: lets the stage scrub or fast-forward
     * through sunrise/sunset without a request per frame.
     */
    public function curve(Request $request, MonitoringService $monitoring): JsonResponse
    {
        return response()->json($monitoring->dayCurve(
            $request->string('date')->toString() ?: null,
            (int) $request->integer('step', 10),
        ));
    }

    /**
     * Clock, sun phase, background scene and weather for the header strip.
     * `?at=` renders another moment, which the settings page uses to preview
     * how the map looks at a given time of day.
     */
    public function __invoke(Request $request, MonitoringService $monitoring): JsonResponse
    {
        $at = $request->date('at');

        return response()->json($monitoring->environment($at));
    }
}
