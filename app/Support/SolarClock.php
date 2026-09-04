<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Sun position for a fixed coordinate, used to decide which time-of-day
 * background the map stage shows and how it is colour graded.
 *
 * Implements the NOAA solar position equations; accurate to well under a
 * minute for sunrise/sunset which is far beyond what the UI needs.
 */
class SolarClock
{
    public function __construct(
        private readonly float $latitude,
        private readonly float $longitude,
        private readonly string $timezone,
    ) {}

    public function state(?CarbonInterface $at = null): array
    {
        $now = CarbonImmutable::instance($at ?? CarbonImmutable::now())->setTimezone($this->timezone);

        $position = $this->position($now);
        $sunrise = $this->eventTime($now, true);
        $sunset = $this->eventTime($now, false);
        $scene = $this->scene($position['elevation'], $position['is_morning']);

        return [
            'now' => $now,
            'timezone' => $this->timezone,
            'elevation' => round($position['elevation'], 3),
            'azimuth' => round($position['azimuth'], 3),
            'is_morning' => $position['is_morning'],
            'sunrise' => $sunrise,
            'sunset' => $sunset,
            'day_length_minutes' => $sunrise && $sunset ? $sunrise->diffInMinutes($sunset) : null,
            'phase' => $scene['phase'],
            'phase_label' => $scene['phase_label'],
            'scene' => $scene,
        ];
    }

    /** Solar elevation/azimuth in degrees. */
    public function position(CarbonInterface $at): array
    {
        $moment = CarbonImmutable::instance($at)->setTimezone($this->timezone);
        $offsetHours = $moment->utcOffset() / 60;

        $julianDay = $this->julianDay($moment) - $offsetHours / 24;
        $t = ($julianDay - 2451545.0) / 36525.0;

        $geomMeanLong = $this->normalizeDegrees(280.46646 + $t * (36000.76983 + $t * 0.0003032));
        $geomMeanAnom = 357.52911 + $t * (35999.05029 - 0.0001537 * $t);
        $eccentricity = 0.016708634 - $t * (0.000042037 + 0.0000001267 * $t);

        $sunEqOfCentre = sin(deg2rad($geomMeanAnom)) * (1.914602 - $t * (0.004817 + 0.000014 * $t))
            + sin(deg2rad(2 * $geomMeanAnom)) * (0.019993 - 0.000101 * $t)
            + sin(deg2rad(3 * $geomMeanAnom)) * 0.000289;

        $trueLong = $geomMeanLong + $sunEqOfCentre;
        $omega = 125.04 - 1934.136 * $t;
        $appLong = $trueLong - 0.00569 - 0.00478 * sin(deg2rad($omega));

        $meanObliquity = 23 + (26 + ((21.448 - $t * (46.815 + $t * (0.00059 - $t * 0.001813)))) / 60) / 60;
        $obliquity = $meanObliquity + 0.00256 * cos(deg2rad($omega));

        $declination = rad2deg(asin(sin(deg2rad($obliquity)) * sin(deg2rad($appLong))));

        $y = tan(deg2rad($obliquity / 2)) ** 2;
        $equationOfTime = 4 * rad2deg(
            $y * sin(2 * deg2rad($geomMeanLong))
            - 2 * $eccentricity * sin(deg2rad($geomMeanAnom))
            + 4 * $eccentricity * $y * sin(deg2rad($geomMeanAnom)) * cos(2 * deg2rad($geomMeanLong))
            - 0.5 * $y * $y * sin(4 * deg2rad($geomMeanLong))
            - 1.25 * $eccentricity * $eccentricity * sin(2 * deg2rad($geomMeanAnom))
        );

        $minutesOfDay = $moment->hour * 60 + $moment->minute + $moment->second / 60;
        $trueSolarTime = fmod($minutesOfDay + $equationOfTime + 4 * $this->longitude - 60 * $offsetHours, 1440);
        $hourAngle = $trueSolarTime / 4 - 180;
        if ($hourAngle < -180) {
            $hourAngle += 360;
        }

        $latRad = deg2rad($this->latitude);
        $declRad = deg2rad($declination);
        $haRad = deg2rad($hourAngle);

        $zenith = rad2deg(acos(min(1, max(-1,
            sin($latRad) * sin($declRad) + cos($latRad) * cos($declRad) * cos($haRad)
        ))));

        $elevation = 90 - $zenith + $this->refraction(90 - $zenith);

        $azimuth = 0.0;
        if (abs($zenith) > 0.0001) {
            $denominator = cos($latRad) * sin(deg2rad($zenith));
            if (abs($denominator) > 0.0001) {
                $cosAzimuth = (sin($latRad) * cos(deg2rad($zenith)) - sin($declRad)) / $denominator;
                $azimuth = rad2deg(acos(min(1, max(-1, $cosAzimuth))));
                $azimuth = $hourAngle > 0 ? $this->normalizeDegrees($azimuth + 180) : $this->normalizeDegrees(540 - $azimuth);
            }
        }

        return [
            'elevation' => $elevation,
            'azimuth' => $azimuth,
            'declination' => $declination,
            'equation_of_time' => $equationOfTime,
            'is_morning' => $hourAngle <= 0,
        ];
    }

    /** Sunrise (or sunset) for the calendar day of `$at`, in local time. */
    public function eventTime(CarbonInterface $at, bool $sunrise): ?CarbonImmutable
    {
        $day = CarbonImmutable::instance($at)->setTimezone($this->timezone)->startOfDay();
        $offsetHours = $day->utcOffset() / 60;

        $julianDay = $this->julianDay($day) - $offsetHours / 24;
        $t = ($julianDay - 2451545.0) / 36525.0;

        $geomMeanLong = $this->normalizeDegrees(280.46646 + $t * (36000.76983 + $t * 0.0003032));
        $geomMeanAnom = 357.52911 + $t * (35999.05029 - 0.0001537 * $t);
        $eccentricity = 0.016708634 - $t * (0.000042037 + 0.0000001267 * $t);
        $sunEqOfCentre = sin(deg2rad($geomMeanAnom)) * (1.914602 - $t * (0.004817 + 0.000014 * $t))
            + sin(deg2rad(2 * $geomMeanAnom)) * (0.019993 - 0.000101 * $t)
            + sin(deg2rad(3 * $geomMeanAnom)) * 0.000289;
        $trueLong = $geomMeanLong + $sunEqOfCentre;
        $omega = 125.04 - 1934.136 * $t;
        $appLong = $trueLong - 0.00569 - 0.00478 * sin(deg2rad($omega));
        $meanObliquity = 23 + (26 + ((21.448 - $t * (46.815 + $t * (0.00059 - $t * 0.001813)))) / 60) / 60;
        $obliquity = $meanObliquity + 0.00256 * cos(deg2rad($omega));
        $declination = rad2deg(asin(sin(deg2rad($obliquity)) * sin(deg2rad($appLong))));

        $y = tan(deg2rad($obliquity / 2)) ** 2;
        $equationOfTime = 4 * rad2deg(
            $y * sin(2 * deg2rad($geomMeanLong))
            - 2 * $eccentricity * sin(deg2rad($geomMeanAnom))
            + 4 * $eccentricity * $y * sin(deg2rad($geomMeanAnom)) * cos(2 * deg2rad($geomMeanLong))
            - 0.5 * $y * $y * sin(4 * deg2rad($geomMeanLong))
            - 1.25 * $eccentricity * $eccentricity * sin(2 * deg2rad($geomMeanAnom))
        );

        $latRad = deg2rad($this->latitude);
        $declRad = deg2rad($declination);
        $cosHourAngle = cos(deg2rad(90.833)) / (cos($latRad) * cos($declRad)) - tan($latRad) * tan($declRad);

        if ($cosHourAngle < -1 || $cosHourAngle > 1) {
            return null; // polar day / night — never happens for Indonesia
        }

        $hourAngle = rad2deg(acos($cosHourAngle));
        $solarNoonMinutes = 720 - 4 * $this->longitude - $equationOfTime + $offsetHours * 60;
        $minutes = $sunrise ? $solarNoonMinutes - 4 * $hourAngle : $solarNoonMinutes + 4 * $hourAngle;

        return $day->addMinutes((int) round($minutes));
    }

    /**
     * Pick the background pair and colour grading for the current sun height.
     *
     * `primary` is cross-faded into `secondary` by `mix` (0..1) so the stage
     * moves continuously instead of snapping between four stills.
     */
    /**
     * Atmospheric refraction in degrees (NOAA's piecewise fit).
     *
     * The naive `1 arcmin / tan(elevation)` form explodes at the horizon — it
     * returned +19 degrees for a sun sitting on it, which flashed full daylight
     * across the stage for one sample at sunrise and sunset.
     */
    private function refraction(float $elevation): float
    {
        if ($elevation > 85.0) {
            return 0.0;
        }

        $te = tan(deg2rad($elevation));

        if ($elevation > 5.0) {
            $seconds = 58.1 / $te - 0.07 / $te ** 3 + 0.000086 / $te ** 5;
        } elseif ($elevation > -0.575) {
            $seconds = 1735.0 + $elevation * (-518.2 + $elevation * (103.4 + $elevation * (-12.79 + $elevation * 0.711)));
        } else {
            $seconds = -20.772 / $te;
        }

        return $seconds / 3600.0;
    }

    public function scene(float $elevation, bool $isMorning): array
    {
        $twilight = $isMorning ? 'dawn' : 'dusk';

        if ($elevation >= 14) {
            [$primary, $secondary, $mix, $phase] = ['day', 'day', 0.0, 'day'];
        } elseif ($elevation >= 2) {
            $mix = ($elevation - 2) / 12;
            [$primary, $secondary, $phase] = [$twilight, 'day', $isMorning ? 'sunrise' : 'sunset'];
        } elseif ($elevation >= -7) {
            $mix = ($elevation + 7) / 9;
            [$primary, $secondary, $phase] = ['night', $twilight, $isMorning ? 'dawn' : 'dusk'];
        } else {
            [$primary, $secondary, $mix, $phase] = ['night', 'night', 0.0, 'night'];
        }

        // Fine grading on top of the still: keeps midday punchy and dusk soft.
        $daylight = max(0.0, min(1.0, ($elevation + 6) / 24));
        $grade = [
            'brightness' => round(0.86 + 0.2 * $daylight, 3),
            'contrast' => round(0.96 + 0.12 * $daylight, 3),
            'saturate' => round(0.82 + 0.32 * $daylight, 3),
            'warmth' => round($primary === 'night' ? 0.05 : (1 - $daylight) * 0.75, 3),
        ];

        return [
            'phase' => $phase,
            'phase_label' => [
                'day' => 'Siang',
                'sunrise' => 'Pagi',
                'sunset' => 'Sore',
                'dawn' => 'Fajar',
                'dusk' => 'Senja',
                'night' => 'Malam',
            ][$phase] ?? 'Siang',
            'primary' => $primary,
            'secondary' => $secondary,
            'mix' => round(max(0.0, min(1.0, $mix)), 4),
            'grade' => $grade,
            'daylight' => round($daylight, 4),
        ];
    }

    private function julianDay(CarbonInterface $moment): float
    {
        $year = $moment->year;
        $month = $moment->month;
        $day = $moment->day + ($moment->hour + $moment->minute / 60 + $moment->second / 3600) / 24;

        if ($month <= 2) {
            $year -= 1;
            $month += 12;
        }

        $a = floor($year / 100);
        $b = 2 - $a + floor($a / 4);

        return floor(365.25 * ($year + 4716)) + floor(30.6001 * ($month + 1)) + $day + $b - 1524.5;
    }

    private function normalizeDegrees(float $degrees): float
    {
        $value = fmod($degrees, 360);

        return $value < 0 ? $value + 360 : $value;
    }
}
