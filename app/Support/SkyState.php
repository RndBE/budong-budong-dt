<?php

namespace App\Support;

/**
 * What the sky is doing, read from two instruments rather than a forecast.
 *
 * The weather station measures illuminance and rainfall. Cloud has no sensor
 * of its own, but it has a shadow: at a given solar elevation a clear sky
 * delivers a known amount of light, so light that is far below that figure is
 * cloud. Put the rain gauge next to it and the pair separates the cases an
 * operator actually cares about — a dark morning that stays dry, and a dark
 * morning that is already raining.
 */
class SkyState
{
    /** Illuminance a cloudless sky delivers with the sun overhead, in lux. */
    private const CLEAR_SKY_LUX = 118000.0;

    /** Below this the sun is too low to read cloud from light at all. */
    private const READABLE_LUX = 1500.0;

    /**
     * @return array{code: string, label: string, cloud: float, rain: float,
     *               lux: float|null, expected_lux: float, reason: string}
     */
    public function read(?float $lux, float $elevation, ?float $intensity = null, ?float $rain24h = null): array
    {
        $expected = self::CLEAR_SKY_LUX * max(0.0, sin(deg2rad($elevation)));
        $readable = $expected >= self::READABLE_LUX && $lux !== null;

        // How much of the expected light is missing. Unreadable at night, so
        // the sky is reported as dark rather than as cloudy.
        $cloud = $readable
            ? max(0.0, min(1.0, 1 - ($lux / $expected)))
            : 0.0;

        $rain = max(0.0, min(1.0, ($intensity ?? 0.0) / 25.0));

        return [
            'code' => $code = $this->classify($cloud, $rain, $elevation),
            'label' => $this->labels()[$code],
            'cloud' => round($cloud, 3),
            'rain' => round($rain, 3),
            'lux' => $lux,
            'expected_lux' => round($expected),
            'reason' => $this->reason($code, $cloud, $lux, $expected, $intensity, $rain24h, $readable),
        ];
    }

    /** The presets behind the what-if buttons on the stage. */
    public function scenario(string $code): array
    {
        $presets = [
            'cerah' => ['cloud' => 0.0, 'rain' => 0.0],
            'berawan' => ['cloud' => 0.35, 'rain' => 0.0],
            'mendung' => ['cloud' => 0.78, 'rain' => 0.0],
            'rintik' => ['cloud' => 0.62, 'rain' => 0.3],
            'hujan' => ['cloud' => 0.88, 'rain' => 0.85],
        ];

        $preset = $presets[$code] ?? $presets['cerah'];

        return $preset + [
            'code' => $code,
            'label' => $this->labels()[$code] ?? $this->labels()['cerah'],
        ];
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return [
            'cerah' => 'Cerah',
            'berawan' => 'Berawan',
            'mendung' => 'Mendung',
            'rintik' => 'Rintik hujan',
            'hujan' => 'Hujan',
            'malam' => 'Malam',
        ];
    }

    private function classify(float $cloud, float $rain, float $elevation): string
    {
        // Rain is rain whatever the hour: it is measured, not inferred.
        if ($rain >= 0.5) {
            return 'hujan';
        }

        if ($rain > 0.04) {
            return 'rintik';
        }

        if ($elevation < -2.0) {
            return 'malam';
        }

        if ($cloud >= 0.55) {
            return 'mendung';
        }

        return $cloud >= 0.25 ? 'berawan' : 'cerah';
    }

    /** One sentence an operator can check the reading against. */
    private function reason(
        string $code,
        float $cloud,
        ?float $lux,
        float $expected,
        ?float $intensity,
        ?float $rain24h,
        bool $readable,
    ): string {
        $number = fn (?float $value, int $decimals = 0) => $value === null
            ? '—'
            : number_format($value, $decimals, ',', '.');

        if (! $readable) {
            return $code === 'malam'
                ? 'Matahari di bawah ufuk — tutupan awan tidak bisa dibaca dari cahaya.'
                : 'Cahaya terlalu rendah untuk membaca tutupan awan; penilaian memakai data hujan.';
        }

        $drop = round($cloud * 100);
        $light = "Iluminasi {$number($lux)} lux dari perkiraan langit cerah {$number($expected)} lux (turun {$drop}%)";

        if (($intensity ?? 0) > 0) {
            return $light.", sementara hujan {$number($intensity, 1)} mm/jam"
                .($rain24h !== null ? " dan {$number($rain24h, 1)} mm dalam 24 jam." : '.');
        }

        return $light.', dan penakar hujan masih kering.';
    }
}
