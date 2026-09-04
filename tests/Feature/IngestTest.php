<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\SensorReading;
use App\Models\SensorStation;
use Database\Seeders\DamSeeder;
use Database\Seeders\StationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([DamSeeder::class, StationSeeder::class]);
        config()->set('telemetry.ingest_token', 'token-uji');
    }

    public function test_a_device_can_push_readings(): void
    {
        $response = $this->withHeader('X-Ingest-Token', 'token-uji')
            ->postJson('/api/ingest', [
                'station' => 'awlr-hulu',
                'metrics' => ['water_level' => 93.881, 'inflow' => 12.36, 'tidak_dikenal' => 5],
            ]);

        $response->assertCreated()
            ->assertJsonPath('station', 'awlr-hulu')
            ->assertJsonPath('ignored', ['tidak_dikenal']);

        $station = SensorStation::query()->where('code', 'awlr-hulu')->firstOrFail();

        $this->assertSame(2, SensorReading::query()->where('sensor_station_id', $station->id)->count());
        $this->assertEqualsWithDelta(
            93.881,
            (float) SensorReading::query()->where('metric_key', 'water_level')->value('value'),
            0.001,
        );
    }

    public function test_a_threshold_breach_raises_an_alert(): void
    {
        $this->withHeader('X-Ingest-Token', 'token-uji')
            ->postJson('/api/ingest', [
                'station' => 'awlr-hulu',
                'metrics' => ['water_level' => 97.9],
            ])
            ->assertCreated();

        $alert = Alert::query()->where('metric_key', 'water_level')->firstOrFail();

        $this->assertSame('bahaya', $alert->level);
        $this->assertNull($alert->resolved_at);
    }

    public function test_a_value_returning_to_normal_resolves_its_alert(): void
    {
        $push = fn (float $value) => $this->withHeader('X-Ingest-Token', 'token-uji')
            ->postJson('/api/ingest', ['station' => 'awlr-hulu', 'metrics' => ['water_level' => $value]]);

        $push(97.9)->assertCreated();
        $push(93.5)->assertCreated();

        $this->assertSame(0, Alert::query()->active()->count());
    }

    public function test_the_endpoint_rejects_a_wrong_token(): void
    {
        $this->withHeader('X-Ingest-Token', 'salah')
            ->postJson('/api/ingest', ['station' => 'awlr-hulu', 'metrics' => ['water_level' => 93.0]])
            ->assertForbidden();

        $this->assertSame(0, SensorReading::query()->count());
    }
}
