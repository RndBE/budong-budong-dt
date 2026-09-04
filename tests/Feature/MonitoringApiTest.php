<?php

namespace Tests\Feature;

use App\Models\Dam;
use App\Models\SensorStation;
use App\Models\User;
use App\Services\Telemetry\ReadingSimulator;
use Carbon\CarbonImmutable;
use Database\Seeders\DamSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([DamSeeder::class, StationSeeder::class]);

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
