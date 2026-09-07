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

    /** The desk: every job with its conversation. */
    public function tickets(Request $request, MonitoringService $monitoring): JsonResponse
    {
        $scope = $request->string('scope', 'aktif')->toString();
        $side = $request->user()->deskSide();

        return response()->json([
            'data' => $monitoring->maintenanceTickets(in_array($scope, ['aktif', 'riwayat', 'semua'], true) ? $scope : 'aktif', $side),
            'unread' => $monitoring->maintenanceUnread($side),
        ]);
    }

    /** Someone in the control room asks for work on an instrument. */
    public function request(Request $request, MonitoringService $monitoring): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'station' => ['nullable', 'string', 'exists:sensor_stations,code'],
            'type' => ['nullable', 'in:preventif,korektif,kalibrasi,inspeksi'],
            'priority' => ['nullable', 'in:rendah,normal,tinggi'],
            'scheduled_for' => ['nullable', 'date'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $monitoring->openMaintenanceRequest($request->user(), $data)], 201);
    }

    /** One line of the conversation, from whichever side is signed in. */
    public function message(Request $request, MaintenanceTask $task, MonitoringService $monitoring): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $monitoring->postMaintenanceMessage($task, $request->user(), $data['body']),
        ], 201);
    }

    /** Opening a thread clears its unread badge for this side. */
    public function read(Request $request, MaintenanceTask $task, MonitoringService $monitoring): JsonResponse
    {
        return response()->json([
            'read' => $monitoring->readMaintenanceThread($task, $request->user()),
            'unread' => $monitoring->maintenanceUnread($request->user()->deskSide()),
        ]);
    }

    /** What has already been done, per instrument. */
    public function history(Request $request, MonitoringService $monitoring): JsonResponse
    {
        return response()->json([
            'data' => $monitoring->maintenanceHistory($request->string('station')->toString() ?: null),
        ]);
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
