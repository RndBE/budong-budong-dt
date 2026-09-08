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

    /** Drag-and-drop placement of a hotspot inside a station panorama. */
    public function hotspot(Request $request, int $hotspot): JsonResponse
    {
        $data = $request->validate([
            'yaw' => ['required', 'numeric', 'between:-360,360'],
            'pitch' => ['required', 'numeric', 'between:-90,90'],
        ]);

        return response()->json([
            'data' => $this->monitoring->moveHotspot($hotspot, (float) $data['yaw'], (float) $data['pitch']),
        ]);
    }

    /** Nudge one prism off the line its record lays out (degrees). */
    public function stake(Request $request, int $hotspot, int $stake): JsonResponse
    {
        $data = $request->validate([
            'offset_yaw' => ['required', 'numeric', 'between:-45,45'],
            'offset_pitch' => ['required', 'numeric', 'between:-45,45'],
        ]);

        return response()->json([
            'data' => $this->monitoring->moveStake(
                $hotspot,
                $stake,
                (float) $data['offset_yaw'],
                (float) $data['offset_pitch'],
            ),
        ]);
    }

    /**
     * Order one spillway gate to an opening, in centimetres.
     *
     * Centimetres because that is what the hoist reports and what a person
     * says when they open a gate. The upper bound is the leaf's own stroke,
     * which the service knows and clamps to; 500 here is only a guard against
     * a figure that could not be a gate at all.
     */
    public function gate(Request $request, string $code, int $gate): JsonResponse
    {
        $data = $request->validate([
            'opening' => ['required', 'numeric', 'between:0,500'],
        ]);

        return response()->json([
            'data' => $this->monitoring->orderGate($code, $gate, (float) $data['opening'], $request->user()),
        ]);
    }

    public function series(Request $request, string $code, string $metric): JsonResponse
    {
        $range = $request->string('range', '24h')->toString();

        return response()->json($this->monitoring->series($code, $metric, $range));
    }
}
