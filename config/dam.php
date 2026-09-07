<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active dam
    |--------------------------------------------------------------------------
    | The dashboard is single-site: this code selects which row of the `dams`
    | table the UI renders. Adding a second dam only means seeding another row
    | and switching this value (or overriding it per request).
    */

    'code' => env('DAM_CODE', 'budong-budong'),

    /*
    |--------------------------------------------------------------------------
    | Map stage
    |--------------------------------------------------------------------------
    | The interactive map is an orthographic render of the dam rather than a
    | tile map, so marker positions are stored as percentages of this image.
    | Assets are built by `python tools/build_map_assets.py`.
    */

    /*
    |--------------------------------------------------------------------------
    | Stage sphere
    |--------------------------------------------------------------------------
    | The digital twin stage is the 360 panorama of the dam body; station pins
    | live inside that sphere. Their angles come from `sphere_yaw`/`sphere_pitch`
    | and, while those are empty, from a bearing derived out of the plan-view
    | map percentages below. Drag a pin on the stage to store the real angle.
    */

    'stage' => [
        'base_station' => 'base-dam',
        // The stage opens at its widest framing; the stepper zooms in from
        // there. Every other zoom in the choreography is relative to this one.
        'default_zoom' => 0,

        /*
        | Where north sits in the panorama, in degrees. The renders are not
        | oriented, so this rotates the compass until its needle matches the
        | real bearing; a station can override it with `panorama_north_offset`.
        */
        'north_offset' => 0.0,
        'default_pitch' => -12,  // most pins hang below the horizon

        /*
        | Compass bearing the base panorama opens on, in degrees from north:
        | 36 is north-east, looking across the dam. It is a bearing, not a raw
        | yaw, so it stays right when `north_offset` changes.
        */
        'default_bearing' => 36.0,

        /*
        | How far the idle drift sweeps to each side of a panorama's own
        | framing, in degrees. It sweeps back and forth rather than going the
        | whole way round: a full turn eventually reaches the seam where the
        | render was joined, and that is the one part of the picture nobody
        | should be shown.
        */
        'drift_arc' => 55.0,

        'sphere' => [
            'yaw_offset' => 0.0,    // rotate every derived bearing, degrees
            'pitch_near' => -32.0,  // a pin right below the camera
            'pitch_far' => -4.0,    // a pin out at the horizon
            'falloff' => 9.0,       // map-percent distance where the two meet
        ],
    ],

    'map' => [
        'asset_path' => 'assets/map',
        'phases' => ['night', 'dawn', 'day', 'dusk'],
        'width' => 4565,
        'height' => 2597,

        /*
        | The render is full-bleed 16:9, but only the middle holds real pixels
        | from the reference photo — the margins are mirrored terrain that lives
        | behind the rail, header and summary panel. Marker percentages refer to
        | that inner region, so the stage maps them through this inset.
        */
        'content' => [
            'left' => 0.147317,
            'right' => 0.287514,
            'top' => 0.115496,
            'bottom' => 0.071222,
        ],

        'min_zoom' => 1.0,
        'max_zoom' => 4.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Status vocabulary
    |--------------------------------------------------------------------------
    */

    'statuses' => [
        'normal' => ['label' => 'Normal', 'color' => '#34d399', 'bucket' => 'aman'],
        'waspada' => ['label' => 'Waspada', 'color' => '#fbbf24', 'bucket' => 'waspada'],
        'siaga' => ['label' => 'Siaga', 'color' => '#fb923c', 'bucket' => 'siaga'],
        'bahaya' => ['label' => 'Bahaya', 'color' => '#f87171', 'bucket' => 'bahaya'],
        'offline' => ['label' => 'Offline', 'color' => '#94a3b8', 'bucket' => 'waspada'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Headline tiles ("Parameter Utama")
    |--------------------------------------------------------------------------
    | Each entry pins one station/metric pair to a tile in the right panel.
    | `trend` adds the change against the previous reading.
    */

    'primary_parameters' => [
        ['station' => 'awlr-hulu', 'metric' => 'water_level', 'label' => 'Muka Air Waduk', 'trend' => true],
        ['station' => 'awlr-hulu', 'metric' => 'inflow', 'label' => 'Inflow (Qin)'],
        ['station' => 'awgc-01', 'metric' => 'discharge', 'label' => 'Outflow (Qout)'],
        ['station' => 'awr-01', 'metric' => 'rainfall_24h', 'label' => 'Curah Hujan', 'note' => '24 Jam Terakhir'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Recent readings list ("Riwayat Data Terakhir")
    |--------------------------------------------------------------------------
    */

    'recent_readings' => [
        ['station' => 'awlr-hulu', 'metric' => 'water_level', 'label' => 'Muka Air Waduk', 'icon' => 'water-level'],
        ['station' => 'awlr-hulu', 'metric' => 'inflow', 'label' => 'Inflow (Qin)', 'icon' => 'inflow'],
        ['station' => 'awgc-01', 'metric' => 'discharge', 'label' => 'Outflow (Qout)', 'icon' => 'outflow'],
        ['station' => 'awr-01', 'metric' => 'rainfall_24h', 'label' => 'Curah Hujan', 'icon' => 'rain'],
        ['station' => 'avwr-01', 'metric' => 'pore_pressure', 'label' => 'Tekanan Air Pori', 'icon' => 'pressure'],
        ['station' => 'gnss-tilt', 'metric' => 'displacement_h', 'label' => 'Deformasi Horizontal', 'icon' => 'deformation'],
        ['station' => 'gnss-tilt', 'metric' => 'displacement_v', 'label' => 'Deformasi Vertikal', 'icon' => 'deformation'],
        ['station' => 'gnss-tilt', 'metric' => 'tilt', 'label' => 'Kemiringan Lereng', 'icon' => 'tilt'],
        ['station' => 'v-notch', 'metric' => 'seepage_flow', 'label' => 'Debit Rembesan', 'icon' => 'seepage'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Refresh cadence (milliseconds) used by the browser polling loop
    |--------------------------------------------------------------------------
    */

    'refresh' => [
        'dashboard' => env('DAM_REFRESH_DASHBOARD', 30000),
        'environment' => env('DAM_REFRESH_ENVIRONMENT', 60000),
        'station' => env('DAM_REFRESH_STATION', 20000),
    ],

];
