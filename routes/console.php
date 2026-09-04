<?php

use Illuminate\Support\Facades\Schedule;

/*
| Demo telemetry. Remove this schedule once real devices post to /api/ingest
| or TELEMETRY_DRIVER is pointed at the upstream logger API.
*/
Schedule::command('telemetry:simulate --step=15')
    ->everyFiveMinutes()
    ->withoutOverlapping();
