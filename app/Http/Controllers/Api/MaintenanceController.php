<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceTask;
use App\Services\MonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function index(Request $request, MonitoringService $monitoring): JsonResponse
    {
        $tasks = MaintenanceTask::query()
            ->where('dam_id', $monitoring->dam()->id)
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->with('station:id,code,name')
            ->orderBy('scheduled_for')
            ->get();

        return response()->json(['data' => $tasks]);
    }

    public function updateStatus(Request $request, MaintenanceTask $task): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:terjadwal,berjalan,tertunda,selesai'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $task->update([
            'status' => $data['status'],
            'notes' => $data['notes'] ?? $task->notes,
            'started_at' => $data['status'] === 'berjalan' ? ($task->started_at ?? now()) : $task->started_at,
            'completed_at' => $data['status'] === 'selesai' ? now() : null,
        ]);

        return response()->json(['data' => $task->fresh()->load('station:id,code,name')]);
    }
}
