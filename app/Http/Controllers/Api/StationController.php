<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationController extends Controller
{
    public function __construct(
        private readonly MonitoringService $monitoring,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->monitoring->markers()]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $range = $request->string('range', '24h')->toString();

        return response()->json($this->monitoring->station($code, $range));
    }

    /** Drag-and-drop marker positioning from the digital twin stage. */
    public function move(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'x' => ['required', 'numeric', 'between:0,100'],
            'y' => ['required', 'numeric', 'between:0,100'],
        ]);

        return response()->json([
            'data' => $this->monitoring->moveStation($code, (float) $data['x'], (float) $data['y']),
        ]);
    }

    /** Drag-and-drop pin placement inside the base panorama (degrees). */
    public function sphere(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'yaw' => ['required', 'numeric', 'between:-360,360'],
            'pitch' => ['required', 'numeric', 'between:-90,90'],
        ]);

        return response()->json([
            'data' => $this->monitoring->moveStationSphere($code, (float) $data['yaw'], (float) $data['pitch']),
        ]);
    }

    public function series(Request $request, string $code, string $metric): JsonResponse
    {
        $range = $request->string('range', '24h')->toString();

        return response()->json($this->monitoring->series($code, $metric, $range));
    }
}
