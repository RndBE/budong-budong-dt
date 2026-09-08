<?php

namespace Tests\Feature;

use App\Services\MonitoringService;
use App\Support\SkyState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reading the sky from two instruments.
 *
 * Cloud has no sensor: it is inferred from light that falls short of what a
 * clear sky would deliver at that solar elevation. These cases are the ones
 * the stage illustration has to tell apart.
 */
class SkyStateTest extends TestCase
{
    use RefreshDatabase;

    private function sky(): SkyState
    {
        return new SkyState;
    }

    public function test_a_bright_midday_reads_as_clear(): void
    {
        // 60 degrees up, a clear sky delivers about 102k lux.
        $state = $this->sky()->read(lux: 99_000, elevation: 60.0, intensity: 0.0);

        $this->assertSame('cerah', $state['code']);
        $this->assertLessThan(0.1, $state['cloud']);
        $this->assertSame(0.0, $state['rain']);
    }

    public function test_light_far_below_the_clear_sky_figure_is_overcast(): void
    {
        // A tenth of the expected light, and the gauge is dry.
        $state = $this->sky()->read(lux: 10_000, elevation: 60.0, intensity: 0.0);

        $this->assertSame('mendung', $state['code']);
        $this->assertGreaterThan(0.8, $state['cloud']);
        $this->assertStringContainsString('penakar hujan masih kering', $state['reason']);
    }

    public function test_light_down_and_the_gauge_running_is_drizzle(): void
    {
        $state = $this->sky()->read(lux: 12_000, elevation: 55.0, intensity: 3.5, rain24h: 22.0);

        $this->assertSame('rintik', $state['code']);
        $this->assertGreaterThan(0.0, $state['rain']);
        // The sentence has to name both numbers, because that pairing is the
        // whole reason the state is not merely "overcast".
        $this->assertStringContainsString('lux', $state['reason']);
        $this->assertStringContainsString('mm/jam', $state['reason']);
    }

    public function test_heavy_rain_outranks_the_light_reading(): void
    {
        $state = $this->sky()->read(lux: 60_000, elevation: 50.0, intensity: 18.0);

        $this->assertSame('hujan', $state['code']);
        $this->assertGreaterThanOrEqual(0.5, $state['rain']);
    }

    public function test_cloud_is_not_guessed_after_dark(): void
    {
        // The sun is below the horizon: there is no light to compare against.
        $state = $this->sky()->read(lux: 0.0, elevation: -8.0, intensity: 0.0);

        $this->assertSame('malam', $state['code']);
        $this->assertSame(0.0, $state['cloud']);
        $this->assertStringContainsString('tidak bisa dibaca', $state['reason']);
    }

    public function test_rain_still_shows_at_night(): void
    {
        $state = $this->sky()->read(lux: 0.0, elevation: -8.0, intensity: 6.0);

        $this->assertSame('rintik', $state['code']);
    }

    public function test_every_scenario_preset_carries_a_label_and_numbers(): void
    {
        $sky = $this->sky();

        foreach (array_keys($sky->labels()) as $code) {
            $preset = $sky->scenario($code);

            $this->assertSame($code, $preset['code']);
            $this->assertNotEmpty($preset['label']);
            $this->assertGreaterThanOrEqual(0.0, $preset['cloud']);
            $this->assertLessThanOrEqual(1.0, $preset['rain']);
        }
    }

    public function test_the_environment_payload_carries_the_sky_and_its_presets(): void
    {
        $this->seed();

        $environment = app(MonitoringService::class)->environment();

        $this->assertArrayHasKey('sky', $environment);
        $this->assertArrayHasKey('presets', $environment['sky']);
        $this->assertArrayHasKey('mendung', $environment['sky']['presets']);
        $this->assertNotEmpty($environment['sky']['label']);
    }
}
