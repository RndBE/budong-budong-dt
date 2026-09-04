<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SensorMetric;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Setting::query()->orderBy('group')->get()->mapWithKeys(
                fn (Setting $setting) => [$setting->key => $setting->value],
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['array'],
            'preferences.map_skin' => ['nullable', 'in:auto,day,night,dawn,dusk'],
            'preferences.panorama_auto_rotate' => ['nullable', 'boolean'],
            'thresholds' => ['array'],
            'thresholds.*.id' => ['required', 'integer', 'exists:sensor_metrics,id'],
            'thresholds.*.warning_threshold' => ['nullable', 'numeric'],
            'thresholds.*.alert_threshold' => ['nullable', 'numeric'],
            'thresholds.*.critical_threshold' => ['nullable', 'numeric'],
        ]);

        foreach ($data['preferences'] ?? [] as $key => $value) {
            Setting::put($key, $value, 'tampilan');
        }

        foreach ($data['thresholds'] ?? [] as $row) {
            SensorMetric::query()->whereKey($row['id'])->update(
                collect($row)->only(['warning_threshold', 'alert_threshold', 'critical_threshold'])->all(),
            );
        }

        return response()->json(['status' => 'tersimpan']);
    }
}
