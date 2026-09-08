<?php

namespace App\Support;

use App\Models\SensorStation;
use App\Models\Setting;

/**
 * How the dashboard is arranged, for everybody.
 *
 * One layout, not one per account: the dashboard is the control room's own
 * screen and is often the thing on the wall, so two operators looking at the
 * same board should be looking at the same board. Only a role holding
 * `dashboard.arrange` may change it.
 *
 * A card is a key, a width and whether it is shown at all. Nothing here knows
 * what a card contains — the keys name partials under
 * `resources/views/partials/dashboard`, and adding a card means adding it to
 * `CARDS` and dropping the partial in beside the others.
 */
class DashboardLayout
{
    /**
     * The two surfaces that can be arranged, and where each is stored.
     *
     * The board is the page's own grid; the panel is the summary column down
     * the right, which every page shows. They are arranged together because
     * they are one screen to the reader, and separately stored because the
     * panel follows them off the dashboard onto every other page.
     */
    public const SURFACES = ['board' => 'dashboard_layout', 'panel' => 'panel_layout'];

    /**
     * Every card the dashboard can show, in the order a fresh install gets
     * them, with the width each was designed at.
     *
     * Widths are columns of six, which is what reproduces the original board:
     * a third, a half, two thirds, or the whole row.
     *
     * @var array<string, array{label: string, span: int, fixed?: bool}>
     */
    public const CARDS = [
        'board' => [
            'tiles' => ['label' => 'Angka Utama', 'span' => 6, 'fixed' => true],
            'trend' => ['label' => 'Tren Muka Air & Debit', 'span' => 4],
            'stations' => ['label' => 'Status Stasiun', 'span' => 2],
            'alerts' => ['label' => 'Riwayat Peringatan', 'span' => 3],
            'maintenance' => ['label' => 'Perawatan Mendatang', 'span' => 3],
        ],
        /*
        | The summary column is one card wide, so nothing there has a span —
        | only an order and whether it is shown. `primary` is the exception it
        | always was: the dashboard prints those four figures across the top
        | already, and the panel drops them there rather than repeating them.
        */
        'panel' => [
            'primary' => ['label' => 'Parameter Utama', 'span' => 6],
            'health' => ['label' => 'Ringkasan Kesehatan Struktur', 'span' => 6],
            'recent' => ['label' => 'Riwayat Data Terakhir', 'span' => 6],
            'alerts' => ['label' => 'Peringatan Aktif', 'span' => 6],
        ],
    ];

    /** How many rows a list card may be asked to show. */
    private const LIMITS = [3, 5, 8];

    /** The most headline tiles the row will hold before it wraps badly. */
    private const MAX_TILES = 6;

    /**
     * Arrangements somebody has already thought about.
     *
     * The arranger is only worth as much as the arrangements people think of,
     * and "default or whatever you build yourself" leaves most readers on the
     * default for ever. These are two shifts' worth of board, named after what
     * they are for.
     *
     * A preset names cards; anything it leaves out keeps its designed width
     * and follows behind, which `current()` already guarantees.
     *
     * @var array<string, array{label: string, hint: string, board: list<array<string, mixed>>, panel: list<array<string, mixed>>}>
     */
    public const PRESETS = [
        'banjir' => [
            'label' => 'Pengawasan Banjir',
            'hint' => 'Muka air, pintu dan hujan besar-besar; perawatan disingkirkan.',
            'board' => [
                ['key' => 'tiles', 'options' => ['parameters' => [
                    'awlr-hulu:water_level',
                    'awlr-hulu:inflow',
                    'awgc-01:discharge',
                    'awgc-01:gate_opening',
                    'awr-01:rainfall_intensity',
                    'awlr-hilir:water_level',
                ]]],
                ['key' => 'trend', 'span' => 6, 'options' => ['station' => 'awlr-hulu']],
                ['key' => 'alerts', 'span' => 3, 'options' => ['limit' => '8']],
                ['key' => 'stations', 'span' => 3],
                ['key' => 'maintenance', 'span' => 3, 'hidden' => true],
            ],
            'panel' => [
                ['key' => 'alerts'],
                ['key' => 'recent', 'options' => ['limit' => '8']],
                ['key' => 'health'],
                ['key' => 'primary', 'hidden' => true],
            ],
        ],
        'harian' => [
            'label' => 'Harian',
            'hint' => 'Susunan bawaan: kondisi hari ini, peringatan dan pekerjaan.',
            'board' => [],
            'panel' => [],
        ],
    ];

    /** The widths a card may be given, as columns of six. */
    public const SPANS = [2 => 'Sepertiga', 3 => 'Setengah', 4 => 'Dua pertiga', 6 => 'Penuh'];

    /**
     * What each card can be asked to *show*, and what it may be given.
     *
     * Built at request time because most of it is the instrumentation
     * catalogue: the parameters a site actually has are the parameters its
     * board can put on the wall. Every value the browser sends is checked
     * against this, so a card can never be pointed at something that is not
     * there.
     *
     * @return array<string, array<string, array{label: string, multiple?: bool, max?: int, choices: array<string, string>}>>
     */
    public static function choices(string $surface = 'board'): array
    {
        $stations = SensorStation::query()
            ->whereHas('metrics')
            ->with('metrics')
            ->orderBy('name')
            ->get();

        $parameters = [];

        foreach ($stations as $station) {
            foreach ($station->metrics as $metric) {
                $parameters["{$station->code}:{$metric->key}"] =
                    ($station->short_name ?? $station->name).' · '.$metric->label;
            }
        }

        $types = $stations->pluck('type')->unique()->sort()
            ->mapWithKeys(fn (string $type) => [$type => $type])->all();

        $rows = collect(self::LIMITS)->mapWithKeys(fn (int $n) => [(string) $n => "{$n} baris"])->all();

        $offer = [
            'board' => [
                'tiles' => [
                    'parameters' => [
                        'label' => 'Parameter yang ditampilkan',
                        'multiple' => true,
                        'max' => self::MAX_TILES,
                        'choices' => $parameters,
                    ],
                ],
                'trend' => [
                    'station' => [
                        'label' => 'Stasiun',
                        'choices' => $stations->mapWithKeys(
                            fn (SensorStation $station) => [$station->code => $station->name],
                        )->all(),
                    ],
                ],
                'stations' => [
                    'type' => [
                        'label' => 'Tipe stasiun',
                        'choices' => ['' => 'Semua tipe'] + $types,
                    ],
                ],
                'alerts' => ['limit' => ['label' => 'Jumlah baris', 'choices' => $rows]],
                'maintenance' => ['limit' => ['label' => 'Jumlah baris', 'choices' => $rows]],
            ],
            'panel' => [
                'recent' => ['limit' => ['label' => 'Jumlah baris', 'choices' => $rows]],
                'alerts' => ['limit' => ['label' => 'Jumlah baris', 'choices' => $rows]],
            ],
        ];

        return $offer[$surface] ?? [];
    }

    /**
     * The headline parameters the board is set to show.
     *
     * Falls back to `dam.primary_parameters`, which is what a fresh install
     * has and what "kembalikan ke bawaan" goes back to.
     *
     * @return list<array<string, mixed>>
     */
    public static function tiles(): array
    {
        $chosen = collect(self::current('board'))->firstWhere('key', 'tiles')['options']['parameters'] ?? [];

        if ($chosen === []) {
            return (array) config('dam.primary_parameters');
        }

        return collect($chosen)
            ->map(function (string $pair) {
                [$station, $metric] = array_pad(explode(':', $pair, 2), 2, null);

                return $metric ? ['station' => $station, 'metric' => $metric, 'trend' => true] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The arrangement as it stands, always complete and always valid.
     *
     * A card added to `CARDS` after somebody saved a layout appears at the end
     * rather than vanishing, and a saved key that no longer exists is dropped.
     * The reader should never lose a panel because the catalogue moved on.
     *
     * @return list<array{key: string, span: int, hidden: bool, label: string, fixed: bool}>
     */
    public static function current(string $surface = 'board'): array
    {
        $catalogue = self::CARDS[$surface] ?? [];
        $saved = collect(Setting::get(self::SURFACES[$surface] ?? '', []))
            ->filter(fn ($card) => is_array($card) && isset($card['key']))
            ->keyBy('key');

        $ordered = $saved->keys()->filter(fn ($key) => isset($catalogue[$key]))->all();
        $missing = array_diff(array_keys($catalogue), $ordered);

        return collect([...$ordered, ...$missing])
            ->map(function (string $key) use ($saved, $surface, $catalogue) {
                $card = $catalogue[$key];
                $stored = $saved[$key] ?? [];
                $span = (int) ($stored['span'] ?? $card['span']);
                $fixed = $card['fixed'] ?? false;

                return [
                    'key' => $key,
                    'label' => $card['label'],
                    'fixed' => $fixed,
                    // A full-width card stays full width; the rest take any
                    // offered column count, falling back to their own.
                    'span' => $fixed || ! isset(self::SPANS[$span]) ? $card['span'] : $span,
                    'hidden' => ! $fixed && (bool) ($stored['hidden'] ?? false),
                    'options' => self::cleanOptions($surface, $key, $stored['options'] ?? []),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Store an arrangement, keeping only what this class recognises.
     *
     * The payload comes from a browser, so nothing in it is trusted: unknown
     * keys are dropped, widths are snapped to the offered set, and a fixed
     * card cannot be hidden or resized however the request is shaped.
     *
     * @param  list<array<string, mixed>>  $cards
     */
    public static function save(array $cards, string $surface = 'board'): void
    {
        $catalogue = self::CARDS[$surface] ?? [];
        $seen = [];

        $clean = collect($cards)
            ->map(fn ($card) => is_array($card) ? $card : [])
            ->filter(fn (array $card) => isset($catalogue[$card['key'] ?? '']))
            ->filter(function (array $card) use (&$seen) {
                if (in_array($card['key'], $seen, true)) {
                    return false;
                }

                $seen[] = $card['key'];

                return true;
            })
            ->map(function (array $card) use ($surface, $catalogue) {
                $key = $card['key'];
                $fixed = $catalogue[$key]['fixed'] ?? false;
                $span = (int) ($card['span'] ?? $catalogue[$key]['span']);

                return [
                    'key' => $key,
                    'span' => $fixed || ! isset(self::SPANS[$span]) ? $catalogue[$key]['span'] : $span,
                    'hidden' => ! $fixed && (bool) ($card['hidden'] ?? false),
                    'options' => self::cleanOptions($surface, $key, $card['options'] ?? []),
                ];
            })
            ->values()
            ->all();

        Setting::put(self::SURFACES[$surface] ?? 'dashboard_layout', $clean, 'tampilan');
    }

    /**
     * Keep only what a card was offered, and only values it was offered.
     *
     * A single-choice option that is asked for something outside its list is
     * dropped rather than corrected: an empty option means "as designed", and
     * a board falling back to its default is easier to explain than a board
     * quietly showing the wrong station.
     *
     * @param  mixed  $options
     * @return array<string, mixed>
     */
    private static function cleanOptions(string $surface, string $key, mixed $options): array
    {
        $offered = self::choices($surface)[$key] ?? [];
        $given = is_array($options) ? $options : [];
        $clean = [];

        foreach ($offered as $name => $spec) {
            if (! array_key_exists($name, $given)) {
                continue;
            }

            if ($spec['multiple'] ?? false) {
                $picked = collect(is_array($given[$name]) ? $given[$name] : [])
                    ->filter(fn ($value) => is_string($value) && isset($spec['choices'][$value]))
                    ->unique()
                    ->take($spec['max'] ?? PHP_INT_MAX)
                    ->values()
                    ->all();

                if ($picked !== []) {
                    $clean[$name] = $picked;
                }

                continue;
            }

            $value = is_scalar($given[$name]) ? (string) $given[$name] : '';

            if ($value !== '' && isset($spec['choices'][$value])) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }

    /*
    | There is no `reset()`: saving an empty list is one, because `current()`
    | rebuilds the board from `CARDS` whenever the stored arrangement has
    | nothing left in it. A second way to say the same thing is a second thing
    | to keep in step.
    */
}
