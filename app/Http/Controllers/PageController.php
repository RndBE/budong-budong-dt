<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\MaintenanceTask;
use App\Models\Report;
use App\Models\SensorStation;
use App\Models\Setting;
use App\Support\DashboardLayout;
use App\Services\MonitoringService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function __construct(
        private readonly MonitoringService $monitoring,
    ) {}

    public function dashboard(): View
    {
        $damId = $this->monitoring->dam()->id;
        $layouts = [
            'board' => DashboardLayout::current('board'),
            'panel' => DashboardLayout::current('panel'),
        ];
        $cards = collect($layouts['board'])->keyBy('key');

        // The trend card opens on the station the board was set to, and on
        // the reservoir gauge until somebody sets one.
        $lead = $cards['trend']['options']['station'] ?? 'awlr-hulu';

        return view('pages.dashboard', [
            'layouts' => $layouts,
            'choices' => [
                'board' => DashboardLayout::choices('board'),
                'panel' => DashboardLayout::choices('panel'),
            ],
            'boot' => $this->boot(),
            'dashboard' => $this->monitoring->dashboard(),
            // Chart defaults to the reservoir level series.
            'stationsForChart' => SensorStation::query()
                ->where('dam_id', $damId)
                ->whereIn('code', array_unique([$lead, 'awlr-hulu', 'awgc-01', 'awr-01']))
                ->with('metrics')
                ->get()
                ->sortBy(fn (SensorStation $station) => $station->code === $lead ? 0 : 1)
                ->map(fn (SensorStation $station) => [
                    'code' => $station->code,
                    'name' => $station->name,
                    'metrics' => $station->metrics->map->only(['key', 'label', 'unit', 'chart_type'])->values()->all(),
                ])
                ->values()
                ->all(),
            'upcoming' => MaintenanceTask::query()
                ->where('dam_id', $damId)
                ->where('status', '!=', 'selesai')
                ->with('station:id,code,name,short_name')
                ->orderBy('scheduled_for')
                // As many rows as the card was set to show.
                ->limit((int) ($cards['maintenance']['options']['limit'] ?? 4))
                ->get(),
        ]);
    }

    public function twin(?string $station = null): View
    {
        return view('pages.twin', [
            'boot' => $this->boot(),
            'dashboard' => $this->monitoring->dashboard(),
            'openStation' => $station,
        ]);
    }

    public function sensors(Request $request): View
    {
        $stations = $this->monitoring->markers();
        $type = $request->string('tipe')->toString();

        return view('pages.sensors', [
            'boot' => $this->boot(),
            'stations' => $type ? array_values(array_filter($stations, fn ($s) => $s['type'] === $type)) : $stations,
            'types' => collect($stations)->pluck('type_label', 'type')->sort()->all(),
            // The menu shows how many stations each type holds, counted before
            // the filter narrows the list.
            'typeCounts' => collect($stations)->countBy('type')->all(),
            'totalStations' => count($stations),
            'activeType' => $type,
        ]);
    }

    public function analytics(): View
    {
        /*
        | Only stations that have something to chart. The overview panorama
        | carries no parameters at all, and offering it left the reader on a
        | screen with nothing on it and no way to tell whether that was the
        | station or the app — worse, the choice is remembered, so every later
        | visit opened blank too.
        */
        $stations = SensorStation::query()
            ->where('dam_id', $this->monitoring->dam()->id)
            ->whereHas('metrics')
            ->with('metrics')
            ->orderBy('name')
            ->get()
            ->map(fn (SensorStation $station) => [
                'code' => $station->code,
                'name' => $station->name,
                'metrics' => $station->metrics->map->only(['key', 'label', 'unit', 'chart_type'])->values()->all(),
            ])
            ->values()
            ->all();

        return view('pages.analytics', [
            'boot' => $this->boot(),
            'stations' => $stations,
        ]);
    }

    public function maintenance(Request $request): View
    {
        $side = $request->user()?->deskSide() ?? 'operator';

        $tasks = MaintenanceTask::query()
            ->where('dam_id', $this->monitoring->dam()->id)
            ->with('station:id,code,name')
            ->orderBy('scheduled_for')
            ->get();

        return view('pages.maintenance', [
            'boot' => $this->boot(),
            'tasks' => $tasks,
            'columns' => ['terjadwal' => 'Terjadwal', 'berjalan' => 'Berjalan', 'tertunda' => 'Tertunda', 'selesai' => 'Selesai'],
            'tickets' => $this->monitoring->maintenanceTickets('semua', $side),
            'history' => $this->monitoring->maintenanceHistory(),
            'desk' => [
                'role' => $side,
                'role_label' => $request->user()?->roleLabel(),
                'side_label' => $request->user()?->sideLabel(),
                'other_label' => config('access.sides')[$this->monitoring->otherSide($side)],
                'sides' => config('access.sides'),
                'user_id' => $request->user()?->id,
                'name' => $request->user()?->name,
                'unread' => $this->monitoring->maintenanceUnread($side),
                'stations' => $this->monitoring->markers(),
                'can' => [
                    'request' => (bool) $request->user()?->can('maintenance.request'),
                    'reply' => (bool) $request->user()?->can('maintenance.reply'),
                    'status' => (bool) $request->user()?->can('maintenance.status'),
                ],
            ],
        ]);
    }

    public function alerts(Request $request): View
    {
        $level = $request->string('level')->toString();

        $alerts = Alert::query()
            ->where('dam_id', $this->monitoring->dam()->id)
            ->when($level, fn ($query) => $query->where('level', $level))
            ->with('station:id,code,name')
            ->orderByDesc('triggered_at')
            ->paginate(20)
            ->withQueryString();

        return view('pages.alerts', [
            'boot' => $this->boot(),
            'alerts' => $alerts,
            'level' => $level,
        ]);
    }

    public function reports(): View
    {
        return view('pages.reports', [
            'boot' => $this->boot(),
            'reports' => Report::query()
                ->where('dam_id', $this->monitoring->dam()->id)
                ->orderByDesc('created_at')
                ->limit(25)
                ->get(),
        ]);
    }

    public function settings(): View
    {
        $stations = SensorStation::query()
            ->where('dam_id', $this->monitoring->dam()->id)
            ->with('metrics')
            ->orderBy('name')
            ->get();

        return view('pages.settings', [
            'boot' => $this->boot(),
            'dam' => $this->monitoring->dam(),
            'stations' => $stations,
            'preferences' => [
                'map_skin' => Setting::get('map_skin', 'auto'),
                'auto_rotate' => Setting::get('panorama_auto_rotate', true),
                'refresh' => config('dam.refresh'),
            ],
        ]);
    }

    /** Shared bootstrap payload handed to Alpine on every page. */
    private function boot(): array
    {
        return $this->monitoring->bootPayload(auth()->user());
    }
}
