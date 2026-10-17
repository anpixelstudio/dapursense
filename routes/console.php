<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('analysis:watchdog')->everyMinute()->withoutOverlapping();
Schedule::command('devices:check')->everyMinute()->withoutOverlapping();
Schedule::command('reports:run-schedules')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('retention:cleanup')->dailyAt('02:30')->withoutOverlapping();
