<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SensorMetric;
use App\Models\Setting;
use App\Support\DashboardLayout;
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

    /**
     * Store the dashboard arrangement for everybody.
     *
     * Its own endpoint rather than another key on `store()`, because that one
     * is gated on `thresholds.edit` — arranging a board and moving an alarm
     * threshold are not the same permission and must not share one.
     */
    public function dashboard(Request $request): JsonResponse
    {
        // Written out rather than generated. The generated version dropped the
        // wildcard and validated `board.key`, which no payload has ever had.
        $data = $request->validate([
            'board' => ['present', 'array'],
            'board.*.key' => ['required', 'string'],
            'board.*.span' => ['nullable', 'integer'],
            'board.*.hidden' => ['nullable', 'boolean'],
            // The option *values* are checked against the card's own offer in
            // DashboardLayout, which is the only place that knows what a card
            // can be pointed at.
            'board.*.options' => ['nullable', 'array'],
            'panel' => ['present', 'array'],
            'panel.*.key' => ['required', 'string'],
            'panel.*.span' => ['nullable', 'integer'],
            'panel.*.hidden' => ['nullable', 'boolean'],
            'panel.*.options' => ['nullable', 'array'],
        ]);

        foreach (array_keys(DashboardLayout::SURFACES) as $surface) {
            DashboardLayout::save($data[$surface], $surface);
        }

        return response()->json([
            'data' => collect(DashboardLayout::SURFACES)
                ->keys()
                ->mapWithKeys(fn (string $surface) => [$surface => DashboardLayout::current($surface)])
                ->all(),
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
