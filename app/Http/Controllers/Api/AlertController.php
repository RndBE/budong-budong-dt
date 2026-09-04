<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Services\MonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request, MonitoringService $monitoring): JsonResponse
    {
        $alerts = Alert::query()
            ->where('dam_id', $monitoring->dam()->id)
            ->when($request->boolean('active', true), fn ($query) => $query->active())
            ->when($request->string('level')->toString(), fn ($query, $level) => $query->where('level', $level))
            ->with('station:id,code,name')
            ->orderByDesc('triggered_at')
            ->limit((int) $request->integer('limit', 25))
            ->get();

        return response()->json(['data' => $alerts]);
    }

    public function acknowledge(Request $request, Alert $alert): JsonResponse
    {
        $alert->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()?->name,
        ]);

        return response()->json(['data' => $alert->fresh()]);
    }

    public function resolve(Alert $alert): JsonResponse
    {
        $alert->update(['resolved_at' => now()]);

        return response()->json(['data' => $alert->fresh()]);
    }
}
