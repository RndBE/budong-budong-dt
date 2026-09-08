<?php

namespace Tests\Feature;

use App\Models\Dam;
use App\Models\PanoramaHotspot;
use App\Models\SensorStation;
use App\Models\User;
use App\Services\MonitoringService;
use App\Support\DashboardLayout;
use App\Services\Telemetry\ReadingSimulator;
use Carbon\CarbonImmutable;
use Database\Seeders\DamSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles carry the abilities the write endpoints ask for.
        $this->seed([RoleSeeder::class, DamSeeder::class, StationSeeder::class]);

        // A short slice of readings is enough to exercise the payloads.
        $simulator = app(ReadingSimulator::class);
        $now = CarbonImmutable::now();

        SensorStation::query()->with('metrics')->get()->each(
            fn (SensorStation $station) => $simulator->fill($station, $now->subHours(3), $now, 30),
        );
    }

    public function test_environment_endpoint_reports_the_solar_scene(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/environment');

        $response->assertOk()
            ->assertJsonPath('dam.code', 'budong-budong')
            ->assertJsonStructure([
                'offset_minutes',
                'clock' => ['iso', 'time', 'date', 'zone_label'],
                'sun' => ['elevation', 'azimuth', 'phase', 'sunrise', 'sunset'],
                'scene' => ['primary', 'secondary', 'mix', 'grade', 'assets'],
                'weather' => ['temperature_c', 'humidity', 'condition_label'],
            ]);

        $scene = $response->json('scene');
        $this->assertContains($scene['primary'], ['night', 'dawn', 'day', 'dusk']);
        $this->assertGreaterThanOrEqual(0, $scene['mix']);
        $this->assertLessThanOrEqual(1, $scene['mix']);
    }

    public function test_dashboard_endpoint_summarises_every_station(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('dam.code', 'budong-budong')
            ->assertJsonCount(4, 'primary')
            ->assertJsonCount(4, 'health.buckets');

        $this->assertSame(
            SensorStation::query()->count(),
            $response->json('system.stations_total'),
        );

        $waterLevel = collect($response->json('primary'))->firstWhere('metric', 'water_level');
        $this->assertNotNull($waterLevel['value'], 'Muka air waduk harus terbaca.');
        $this->assertSame('mdpl', $waterLevel['unit']);
    }

    public function test_station_endpoint_returns_panorama_metrics_and_series(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/stations/awlr-hulu');

        $response->assertOk()
            ->assertJsonPath('code', 'awlr-hulu')
            ->assertJsonPath('type', 'water_level');

        $this->assertStringContainsString('assets/panorama/awlr-hulu.webp', $response->json('panorama.url'));

        $metrics = collect($response->json('metrics'));
        $this->assertTrue($metrics->contains('key', 'water_level'));
        $this->assertNotEmpty($metrics->firstWhere('key', 'water_level')['series']);
        $this->assertNotEmpty($response->json('hotspots'));
    }

    public function test_guests_cannot_read_monitoring_data(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_map_markers_carry_positions_for_every_panorama(): void
    {
        $markers = $this->actingAs(User::factory()->create())->getJson('/api/stations')->json('data');

        $this->assertCount(SensorStation::query()->count(), $markers);

        foreach ($markers as $marker) {
            $this->assertGreaterThan(0, $marker['x'], "{$marker['code']} tidak punya koordinat peta.");
            $this->assertGreaterThan(0, $marker['y'], "{$marker['code']} tidak punya koordinat peta.");
            $this->assertTrue($marker['has_panorama'], "{$marker['code']} belum punya panorama.");
        }
    }

    /**
     * Every station panorama opens on a compass bearing, not on a raw yaw:
     * correcting where north sits in a render must not swing the framing the
     * reader was given. The base panorama is the exception — it takes the
     * dam's own `dam.stage.default_bearing`.
     */
    public function test_every_station_panorama_opens_on_a_bearing(): void
    {
        $user = User::factory()->create();

        $codes = SensorStation::query()
            ->whereNotNull('panorama')
            ->where('code', '!=', 'base-dam')
            ->pluck('code');

        $this->assertNotEmpty($codes);

        foreach ($codes as $code) {
            $panorama = $this->actingAs($user)->getJson("/api/stations/{$code}")->json('panorama');

            $this->assertNotNull($panorama['bearing'], "{$code} belum punya arah bukaan.");
            $this->assertGreaterThanOrEqual(0, $panorama['bearing'], "{$code} arah bukaan di luar 0-360.");
            $this->assertLessThan(360, $panorama['bearing'], "{$code} arah bukaan di luar 0-360.");
        }
    }

    public function test_the_day_curve_covers_a_whole_day_of_lighting(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/environment/curve?step=30');

        $response->assertOk()
            ->assertJsonPath('step_minutes', 30)
            ->assertJsonStructure(['date', 'sunrise', 'sunset', 'assets', 'samples']);

        $samples = collect($response->json('samples'));

        $this->assertSame(49, $samples->count(), 'Setiap 30 menit dari 00:00 sampai 24:00.');
        $this->assertSame(0, $samples->first()['minute']);
        $this->assertSame(1440, $samples->last()['minute']);

        // Midday must be brighter than midnight, whichever way the year turns.
        $noon = $samples->firstWhere('minute', 720);
        $midnight = $samples->first();
        $this->assertGreaterThan($midnight['elevation'], $noon['elevation']);
        $this->assertSame('night', $midnight['primary']);
        $this->assertContains($noon['phase'], ['day', 'sunrise', 'sunset']);
    }

    public function test_a_marker_can_be_moved_and_the_position_is_stored(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/stations/cctv-01/position', ['x' => 42.5, 'y' => 61.25])
            ->assertOk()
            ->assertJsonPath('data.x', 42.5)
            ->assertJsonPath('data.y', 61.25);

        $station = SensorStation::query()->where('code', 'cctv-01')->firstOrFail();

        $this->assertEqualsWithDelta(42.5, $station->map_x, 0.001);
        $this->assertEqualsWithDelta(61.25, $station->map_y, 0.001);
    }

    public function test_a_marker_position_outside_the_render_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/stations/cctv-01/position', ['x' => 140, 'y' => -3])
            ->assertStatus(422);
    }

    public function test_the_stage_describes_the_base_panorama_it_opens_on(): void
    {
        $stage = $this->actingAs(User::factory()->create())
            ->getJson('/api/environment')
            ->assertOk()
            ->json('stage');

        $this->assertSame(config('dam.stage.base_station'), $stage['base']['code']);
        $this->assertNotEmpty($stage['base']['panorama']['url']);
        $this->assertNotEmpty($stage['base']['panorama']['preview']);
    }

    public function test_every_marker_has_a_place_inside_the_base_panorama(): void
    {
        $markers = $this->actingAs(User::factory()->create())->getJson('/api/stations')->json('data');

        foreach ($markers as $marker) {
            $this->assertIsNumeric($marker['sphere']['yaw'], "{$marker['code']} tidak punya sudut panorama.");
            $this->assertGreaterThanOrEqual(-180, $marker['sphere']['yaw']);
            $this->assertLessThanOrEqual(180, $marker['sphere']['yaw']);
            $this->assertLessThanOrEqual(0, $marker['sphere']['pitch']);
            $this->assertFalse($marker['sphere']['placed'], "{$marker['code']} seharusnya masih berupa perkiraan.");
        }
    }

    public function test_a_pin_can_be_placed_inside_the_base_panorama(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/stations/cctv-01/sphere', ['yaw' => 214.5, 'pitch' => -18.25])
            ->assertOk()
            ->assertJsonPath('data.sphere.yaw', -145.5)   // normalised to -180..180
            ->assertJsonPath('data.sphere.pitch', -18.25)
            ->assertJsonPath('data.sphere.placed', true);

        $station = SensorStation::query()->where('code', 'cctv-01')->firstOrFail();

        $this->assertEqualsWithDelta(-145.5, $station->sphere_yaw, 0.001);
        $this->assertEqualsWithDelta(-18.25, $station->sphere_pitch, 0.001);
    }

    public function test_a_pin_outside_the_sphere_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/stations/cctv-01/sphere', ['yaw' => 900, 'pitch' => -120])
            ->assertStatus(422);
    }

    /**
     * The monitoring plots belong to ADR-02 alone. ADR-01 watches the same dam
     * body from the other side, but its prisms are not drawn on the stage —
     * two sets of stakes over one structure is a picture nobody can read.
     */
    public function test_only_the_left_total_station_draws_the_plots(): void
    {
        $hotspots = $this->actingAs(User::factory()->create())
            ->getJson('/api/stations/adr-01')
            ->assertOk()
            ->json('hotspots');

        $this->assertEmpty(collect($hotspots)->where('type', 'plot'));
        $this->assertNotEmpty($hotspots, 'ADR-01 kehilangan seluruh titiknya.');
    }

    /**
     * A gate order is a record of what somebody asked for, not a claim that
     * the leaf has moved: the target jumps to the figure straight away and the
     * opening stays where it is until the gate has had time to travel.
     */
    public function test_ordering_a_spillway_gate_records_the_order_and_moves_the_leaf(): void
    {
        $user = User::factory()->create(['role' => 'operator']);
        $before = $this->actingAs($user)->getJson('/api/stations/awgc-01')->json('metrics');
        $opening = collect($before)->firstWhere('key', 'gate_opening_2')['value'];

        $this->actingAs($user)
            ->postJson('/api/stations/awgc-01/gates/2', ['opening' => 82.5])
            ->assertOk()
            ->assertJsonPath('data.gate', 2)
            // Centimetres, which is what the hoist reports; the stroke is
            // 100 cm here, so the derived percentage happens to match.
            ->assertJsonPath('data.opening', 82.5)
            ->assertJsonPath('data.stroke_cm', 100)
            ->assertJsonPath('data.opening_percent', 82.5);

        $this->assertDatabaseHas('gate_commands', [
            'gate' => 2,
            'opening' => 82.5,
            'user_id' => $user->id,
        ]);

        $after = collect($this->actingAs($user)->getJson('/api/stations/awgc-01')->json('metrics'));

        $this->assertSame(82.5, round($after->firstWhere('key', 'gate_target_2')['value'], 2));
        $this->assertEqualsWithDelta($opening, $after->firstWhere('key', 'gate_opening_2')['value'], 0.5);

        // Four minutes of travel later the leaf is where it was sent.
        $moved = app(ReadingSimulator::class);
        $station = SensorStation::query()->where('code', 'awgc-01')->with('metrics')->firstOrFail();

        $this->assertEqualsWithDelta(82.5, $moved->value(
            $station,
            $station->metrics->firstWhere('key', 'gate_opening_2'),
            CarbonImmutable::now()->addMinutes(5),
        ), 0.01);
    }

    public function test_a_gate_order_needs_the_ability(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'teknisi']))
            ->postJson('/api/stations/awgc-01/gates/2', ['opening' => 40])
            ->assertForbidden();
    }

    public function test_an_opening_outside_the_stroke_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']))
            ->postJson('/api/stations/awgc-01/gates/2', ['opening' => 140])
            ->assertStatus(422);
    }

    public function test_a_gate_that_does_not_exist_is_not_ordered(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']))
            ->postJson('/api/stations/awgc-01/gates/7', ['opening' => 40])
            ->assertNotFound();
    }

    /**
     * The board is arranged once for the whole control room, so the payload
     * that arranges it comes from a browser and none of it is trusted: unknown
     * cards, duplicates and impossible widths are all dropped, and a card the
     * request forgot comes back at the end rather than disappearing.
     */
    public function test_the_dashboard_arrangement_is_saved_and_sanitised(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']))
            ->postJson('/api/dashboard/layout', ['panel' => [], 'board' => [
                ['key' => 'alerts', 'span' => 6],
                ['key' => 'nonsense', 'span' => 3],
                ['key' => 'alerts', 'span' => 2],
                ['key' => 'stations', 'span' => 99, 'hidden' => true],
                ['key' => 'tiles', 'span' => 2, 'hidden' => true],
            ]])
            ->assertOk();

        $layout = collect(DashboardLayout::current())->keyBy('key');

        $this->assertSame(6, $layout['alerts']['span']);
        // Snapped back to the width it was designed at.
        $this->assertSame(2, $layout['stations']['span']);
        $this->assertTrue($layout['stations']['hidden']);
        // The headline row is fixed: it cannot be narrowed or hidden.
        $this->assertSame(6, $layout['tiles']['span']);
        $this->assertFalse($layout['tiles']['hidden']);
        // Nothing invented, nothing lost: the same set of cards, whatever
        // order the request put them in.
        $this->assertEqualsCanonicalizing(
            array_keys(DashboardLayout::CARDS['board']),
            $layout->keys()->all(),
        );

        // The duplicate was dropped rather than moved: one `alerts`, and it
        // keeps the place its first mention gave it. Cards the request left
        // out follow behind the ones it named.
        $this->assertSame(
            ['alerts', 'stations', 'tiles', 'trend', 'maintenance'],
            array_column(DashboardLayout::current(), 'key'),
        );
    }

    /**
     * A card can be pointed at something else, but only at something the site
     * actually has: the choices are the instrumentation catalogue, and a value
     * outside them is dropped rather than corrected. An empty option means
     * "as designed", which is easier to explain than a board quietly showing
     * the wrong station.
     */
    public function test_a_card_can_be_pointed_at_other_parameters(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']))
            ->postJson('/api/dashboard/layout', ['panel' => [], 'board' => [
                ['key' => 'tiles', 'options' => ['parameters' => [
                    'awgc-01:gate_opening',
                    'v-notch:seepage_flow',
                    'tidak-ada:apa-pun',
                    'awgc-01:gate_opening',
                ]]],
                ['key' => 'trend', 'options' => ['station' => 'bukan-stasiun']],
            ]])
            ->assertOk();

        $tiles = collect(DashboardLayout::current())->firstWhere('key', 'tiles');
        $trend = collect(DashboardLayout::current())->firstWhere('key', 'trend');

        // The unknown pair and the duplicate are gone; the rest stand.
        $this->assertSame(
            ['awgc-01:gate_opening', 'v-notch:seepage_flow'],
            $tiles['options']['parameters'],
        );
        $this->assertSame([], $trend['options']);

        // And the board really draws them, rather than the configured four.
        $this->assertSame(
            ['gate_opening', 'seepage_flow'],
            array_column(DashboardLayout::tiles(), 'metric'),
        );
    }

    /**
     * The board renders, arranged or not.
     *
     * A saved arrangement changes which partials are included, in what order,
     * and what each is pointed at — every one of those is a chance to render
     * a card that no longer exists or read an option that is not there. This
     * walks the page with an arrangement in place and asks only that it comes
     * back whole.
     */
    public function test_the_dashboard_renders_with_a_saved_arrangement(): void
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        DashboardLayout::save([
            ['key' => 'alerts', 'span' => 6, 'options' => ['limit' => '3']],
            ['key' => 'stations', 'span' => 3, 'options' => ['type' => 'cctv']],
            ['key' => 'maintenance', 'span' => 3, 'hidden' => true],
            ['key' => 'trend', 'span' => 6, 'options' => ['station' => 'adr-02']],
            ['key' => 'tiles', 'options' => ['parameters' => ['awgc-01:gate_opening']]],
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            // Arranged, so the cards come out in the order that was saved.
            ->assertSeeInOrder(['Riwayat Peringatan', 'Status Stasiun', 'Angka Utama'], false);
    }

    /**
     * The summary column is arranged by the same control and stored on its
     * own, because it follows the reader off the dashboard onto every other
     * page. Arranging one surface must not disturb the other.
     */
    public function test_the_summary_column_is_arranged_separately(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']))
            ->postJson('/api/dashboard/layout', [
                'board' => [],
                'panel' => [
                    ['key' => 'alerts', 'options' => ['limit' => '3']],
                    ['key' => 'health', 'hidden' => true],
                ],
            ])
            ->assertOk();

        $panel = DashboardLayout::current('panel');

        $this->assertSame(['alerts', 'health', 'primary', 'recent'], array_column($panel, 'key'));
        $this->assertTrue(collect($panel)->firstWhere('key', 'health')['hidden']);
        $this->assertSame('3', collect($panel)->firstWhere('key', 'alerts')['options']['limit']);

        // The board went back to its designed order and stayed there.
        $this->assertSame(
            array_keys(DashboardLayout::CARDS['board']),
            array_column(DashboardLayout::current('board'), 'key'),
        );
    }

    public function test_arranging_the_dashboard_needs_the_ability(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'teknisi']))
            ->postJson('/api/dashboard/layout', ['panel' => [], 'board' => []])
            ->assertForbidden();
    }

    public function test_an_empty_arrangement_puts_the_board_back(): void
    {
        $user = User::factory()->create(['role' => 'operator']);

        $this->actingAs($user)->postJson('/api/dashboard/layout', ['panel' => [], 'board' => [
            ['key' => 'maintenance', 'span' => 6],
        ]])->assertOk();

        $this->assertSame('maintenance', DashboardLayout::current()[0]['key']);

        $this->actingAs($user)->postJson('/api/dashboard/layout', ['panel' => [], 'board' => []])->assertOk();

        $this->assertSame(
            array_keys(DashboardLayout::CARDS['board']),
            array_column(DashboardLayout::current(), 'key'),
        );
    }

    /**
     * A fresh install comes up with the survey, not with the estimate.
     *
     * Placement lives in the database — where a pin sits in the base panorama,
     * where a line of prisms sits in a station panorama, and where each prism
     * was nudged to. Pushing code carries none of that, so an afternoon of
     * placing prisms on the real bays would be lost on every new environment.
     * `placements:export` writes it into the repository and the seeder reads it
     * when it creates a row. This asserts the round trip, against the exported
     * file itself so it stays true after the next export.
     */
    public function test_a_fresh_seed_comes_up_with_the_exported_placements(): void
    {
        $exported = database_path('seeders/data/placements.php');

        if (! is_file($exported)) {
            $this->markTestSkipped('Belum ada berkas penempatan yang diekspor.');
        }

        $placements = (array) require $exported;
        $checkedPins = 0;
        $checkedPlaces = 0;

        foreach ($placements as $code => $placed) {
            $station = SensorStation::query()->where('code', $code)->with('hotspots')->first();

            if (! $station) {
                continue;
            }

            if (isset($placed['sphere'])) {
                $this->assertEqualsWithDelta($placed['sphere']['yaw'], $station->sphere_yaw, 0.001, $code);
                $checkedPins++;
            }

            foreach ($placed['hotspots'] ?? [] as $label => $spot) {
                $hotspot = $station->hotspots->firstWhere('label', $label);

                if (! $hotspot) {
                    continue;
                }

                $this->assertEqualsWithDelta($spot['yaw'], $hotspot->yaw, 0.001, "{$code}/{$label}");

                if (isset($spot['places'])) {
                    // The slow part to redo by hand: prism by prism.
                    $this->assertSame($spot['places'], $hotspot->meta['places'] ?? null, "{$code}/{$label}");
                    $checkedPlaces++;
                }
            }
        }

        $this->assertGreaterThan(0, $checkedPins, 'Tidak ada penanda stasiun yang terperiksa.');
        $this->assertGreaterThan(0, $checkedPlaces, 'Tidak ada nudge patok yang terperiksa.');
    }

    /**
     * The shape a real deploy has: placed pins, and a feature that is new.
     *
     * The server's base-dam markers were dragged onto the structures months
     * before the prisms existed as records at all. Pushing therefore has to do
     * two opposite things in one run - leave every station pin exactly where
     * somebody put it, and bring the plot lines up on the angles that were
     * exported from the machine they were placed on, nudges and all. The two
     * halves are one rule (`placements.php` is read only when a row is
     * created), but the halves are what people ask about, so both are asserted
     * against the same run.
     */
    public function test_a_deploy_keeps_placed_pins_and_brings_new_plots_with_it(): void
    {
        $file = database_path('seeders/data/placements.php');

        if (! is_file($file)) {
            $this->markTestSkipped('Belum ada berkas penempatan yang diekspor.');
        }

        $placements = (array) require $file;
        $monitoring = app(MonitoringService::class);

        // The server: pins placed by hand, on angles that are not the file's.
        $pins = [];

        foreach (SensorStation::query()->pluck('code') as $index => $code) {
            $pins[$code] = [7.5 + $index, -3.25 - ($index / 10)];
            $monitoring->moveStationSphere($code, $pins[$code][0], $pins[$code][1]);
        }

        // The server: the prism lines do not exist there yet.
        PanoramaHotspot::query()->where('type', 'plot')->delete();

        $this->seed(StationSeeder::class);

        foreach ($pins as $code => [$yaw, $pitch]) {
            $station = SensorStation::query()->where('code', $code)->first();

            if (! $station) {
                continue; // Retired by the catalogue; nothing to keep.
            }

            $this->assertEqualsWithDelta($yaw, $station->sphere_yaw, 0.001, "Penanda {$code} bergeser.");
            $this->assertEqualsWithDelta($pitch, $station->sphere_pitch, 0.001, "Penanda {$code} bergeser.");
        }

        $plots = PanoramaHotspot::query()->where('type', 'plot')->with('station')->get();
        $this->assertNotEmpty($plots, 'Baris petak tidak dibuat ulang.');

        $checked = 0;

        foreach ($plots as $plot) {
            $spot = $placements[$plot->station->code]['hotspots'][$plot->label] ?? null;

            if (! $spot) {
                continue;
            }

            $this->assertEqualsWithDelta($spot['yaw'], $plot->yaw, 0.001, $plot->label);
            $this->assertEqualsWithDelta($spot['pitch'], $plot->pitch, 0.001, $plot->label);
            $this->assertSame($spot['places'] ?? null, $plot->meta['places'] ?? null, $plot->label);
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'Tidak ada baris petak yang terperiksa.');
    }

    /**
     * Re-seeding an install that already has placements keeps them.
     *
     * This is the question every deploy asks: the markers on the server were
     * dragged onto the real structures, and pushing code re-runs the seeder.
     * The catalogue may rename a label, add a parameter or drop a station, but
     * it may never move something somebody placed — angles are written when a
     * row is created and never again, and a per-prism nudge is carried across
     * the refreshed copy.
     */
    public function test_re_seeding_does_not_move_what_was_placed(): void
    {
        $monitoring = app(MonitoringService::class);

        // Place three different kinds of thing, the way the stage does.
        $monitoring->moveStationSphere('cctv-01', 128.5, -7.25);

        $plot = PanoramaHotspot::query()
            ->whereHas('station', fn ($query) => $query->where('code', 'adr-02'))
            ->where('type', 'plot')
            ->firstOrFail();

        $monitoring->moveHotspot($plot->id, -42.125, -11.5);
        $monitoring->moveStake($plot->id, 3, 6.25, -2.75);

        $placed = $plot->fresh();

        // What a deploy does.
        $this->seed(StationSeeder::class);

        $station = SensorStation::query()->where('code', 'cctv-01')->firstOrFail();
        $after = PanoramaHotspot::query()->findOrFail($plot->id);

        $this->assertEqualsWithDelta(128.5, $station->sphere_yaw, 0.001, 'Penanda stasiun bergeser.');
        $this->assertEqualsWithDelta(-7.25, $station->sphere_pitch, 0.001, 'Penanda stasiun bergeser.');
        $this->assertEqualsWithDelta(-42.125, $after->yaw, 0.001, 'Baris petak bergeser.');
        $this->assertEqualsWithDelta(-11.5, $after->pitch, 0.001, 'Baris petak bergeser.');
        $this->assertSame($placed->meta['places'], $after->meta['places'], 'Nudge patok hilang.');
        $this->assertSame([6.25, -2.75], $after->meta['places']['3'], 'Nudge patok berubah.');

        // And the row is the same row, not a fresh one beside it.
        $this->assertSame($plot->id, $after->id);
    }

    public function test_a_robotic_total_station_marks_its_plots_on_both_faces(): void
    {
        $hotspots = $this->actingAs(User::factory()->create())
            ->getJson('/api/stations/adr-02')
            ->assertOk()
            ->json('hotspots');

        $plots = collect($hotspots)->where('type', 'plot');

        // Three plots per face, five stakes in each.
        $this->assertCount(3, $plots->where('meta.side', 'hulu'));
        $this->assertCount(3, $plots->where('meta.side', 'hilir'));

        /*
         * A face where every prism reads the same band is a face nobody needs
         * thirty prisms for. The point of the plot is that some points have
         * crept further than others, so the field has to *show* more than one
         * status or the picture is telling the reader nothing.
         */
        $bands = $plots->flatMap(fn (array $plot) => array_column($plot['stakes'], 'status'))->unique();

        $this->assertGreaterThan(1, $bands->count(), 'Seluruh patok membaca status yang sama.');

        $plots->each(function (array $plot) {
            $this->assertNotEmpty($plot['description']);
            $this->assertCount(5, $plot['stakes'], "{$plot['label']} tidak punya lima patok.");

            $codes = array_column($plot['stakes'], 'code');

            // The stake names come from the row's code, so they have to be
            // distinct — five prisms are five points, not one repeated.
            $this->assertCount(5, array_unique($codes));

            // Every prism carries its own displacement, and the figures may
            // not all be the same number: the whole point of a plot is that
            // the movement has a shape across the face.
            $this->assertGreaterThan(1, count(array_unique(array_column($plot['stakes'], 'linear'))));

            foreach ($plot['stakes'] as $stake) {
                $this->assertNotNull($stake['linear'], "{$stake['code']} tanpa pergeseran linier.");
                $this->assertNotEmpty($stake['formatted']);
                /*
                 * A prism reports a status only once it has been measured, and
                 * never `offline`: that is a state a logger can be in, and a
                 * prism is a piece of glass on a stake — it is the total
                 * station that goes off the air.
                 */
                $this->assertContains($stake['status'], ['normal', 'waspada', 'siaga', 'bahaya']);

                // The resultant cannot be smaller than either component.
                $this->assertGreaterThanOrEqual(abs($stake['horizontal']) - 0.01, $stake['linear']);
                $this->assertGreaterThanOrEqual(abs($stake['vertical']) - 0.01, $stake['linear']);

                // The arrow is drawn at the slope's aspect, give or take: a
                // prism does not creep in a direction of its own choosing.
                $this->assertLessThanOrEqual(14.0, abs($stake['aspect'] - $plot['meta']['aspect']));
            }

            // Every stake stands in a petak of its own, so the row needs a
            // spacing and a petak size — without them there is nothing to draw.
            $this->assertGreaterThan(0, $plot['meta']['stake_gap']);
            $this->assertGreaterThan(0, $plot['meta']['cell_yaw']);
            $this->assertGreaterThan(0, $plot['meta']['cell_pitch']);
            $this->assertIsNumeric($plot['meta']['aspect']);

            // The petak may not touch each other, or a line of five reads as
            // one long box again. What matters is the petak's extent *along
            // the line*, because the line no longer runs across the picture.
            $along = deg2rad($plot['meta']['line'] ?? 0);
            $extent = abs(cos($along)) * $plot['meta']['cell_yaw']
                + abs(sin($along)) * $plot['meta']['cell_pitch'];

            $this->assertLessThan($plot['meta']['stake_gap'], $extent, "Petak {$plot['label']} bersentuhan.");
        });
    }

    public function test_a_hotspot_can_be_placed_inside_its_panorama(): void
    {
        $hotspot = PanoramaHotspot::query()
            ->whereRelation('station', 'code', 'adr-02')
            ->where('type', 'plot')
            ->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/hotspots/{$hotspot->id}/position", ['yaw' => 192.5, 'pitch' => -12.25])
            ->assertOk()
            ->assertJsonPath('data.station', 'adr-02')
            ->assertJsonPath('data.yaw', -167.5)    // normalised to -180..180
            ->assertJsonPath('data.pitch', -12.25);

        $this->assertEqualsWithDelta(-167.5, $hotspot->fresh()->yaw, 0.001);
    }

    public function test_re_seeding_keeps_a_hotspot_where_it_was_placed(): void
    {
        $hotspot = PanoramaHotspot::query()
            ->whereRelation('station', 'code', 'adr-02')
            ->where('type', 'plot')
            ->firstOrFail();

        app(MonitoringService::class)->moveHotspot($hotspot->id, -21.5, -13.5);

        // Placing a plot is survey work, not a preference: running the
        // catalogue again must not put it back on its estimated angle.
        $this->seed(StationSeeder::class);

        $this->assertEqualsWithDelta(-21.5, $hotspot->fresh()->yaw, 0.001);
        $this->assertEqualsWithDelta(-13.5, $hotspot->fresh()->pitch, 0.001);
    }

    public function test_one_prism_can_be_nudged_off_its_line(): void
    {
        $plot = PanoramaHotspot::query()->where('type', 'plot')->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/hotspots/{$plot->id}/stakes/2", ['offset_yaw' => 1.25, 'offset_pitch' => -0.5])
            ->assertOk()
            ->assertJsonPath('data.offset', [1.25, -0.5]);

        $fresh = $plot->fresh();

        $this->assertSame([1.25, -0.5], $fresh->meta['places']['2']);

        // A nudge is an offset, so the line keeps its own centre and the other
        // four stakes keep their places.
        $this->assertEqualsWithDelta($plot->yaw, $fresh->yaw, 0.001);
        $this->assertCount(1, $fresh->meta['places']);
    }

    public function test_a_prism_outside_the_line_is_rejected(): void
    {
        $plot = PanoramaHotspot::query()->where('type', 'plot')->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/hotspots/{$plot->id}/stakes/9", ['offset_yaw' => 1, 'offset_pitch' => 1])
            ->assertNotFound();
    }

    public function test_re_seeding_keeps_a_nudged_prism(): void
    {
        $plot = PanoramaHotspot::query()->where('type', 'plot')->firstOrFail();

        app(MonitoringService::class)->moveStake($plot->id, 3, 0.75, -1.5);

        $this->seed(StationSeeder::class);

        $this->assertSame([0.75, -1.5], $plot->fresh()->meta['places']['3']);
    }

    public function test_placing_a_hotspot_needs_the_move_ability(): void
    {
        $hotspot = PanoramaHotspot::query()->where('type', 'plot')->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => 'pengawas']))
            ->postJson("/api/hotspots/{$hotspot->id}/position", ['yaw' => 4, 'pitch' => -4])
            ->assertForbidden();
    }

    public function test_the_sun_climbs_without_jumping_over_the_horizon(): void
    {
        $samples = $this->actingAs(User::factory()->create())
            ->getJson('/api/environment/curve?step=2')
            ->assertOk()
            ->json('samples');

        $previous = null;

        foreach ($samples as $sample) {
            if ($previous !== null) {
                // Refraction is worth half a degree at most; a bigger step
                // means the horizon correction is blowing up again.
                $this->assertLessThan(
                    1.5,
                    abs($sample['elevation'] - $previous),
                    "Elevasi melompat di menit {$sample['minute']}."
                );
            }

            $previous = $sample['elevation'];
        }
    }

    public function test_the_base_panorama_has_a_texture_for_every_phase(): void
    {
        $phases = $this->actingAs(User::factory()->create())
            ->getJson('/api/environment')
            ->assertOk()
            ->json('stage.base.phases');

        $this->assertSame(config('dam.map.phases'), array_keys($phases));

        foreach ($phases as $phase => $asset) {
            $this->assertNotEmpty($asset['url'], "Fase {$phase} tidak punya tekstur.");
            $this->assertNotEmpty($asset['preview']);
        }

        $this->assertStringContainsString('base-dam-night', $phases['night']['url']);
        $this->assertStringContainsString('base-dam-dawn', $phases['dawn']['url']);
        $this->assertStringContainsString('base-dam-dusk', $phases['dusk']['url']);
    }

    public function test_readings_are_reported_in_central_indonesian_time(): void
    {
        $dam = Dam::query()->firstOrFail();
        $this->assertSame('Asia/Makassar', $dam->timezone);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/environment');

        $response->assertOk()->assertJsonPath('clock.zone_label', 'WITA');
        $this->assertSame(480, $response->json('offset_minutes'));
    }
}
