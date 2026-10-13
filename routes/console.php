<?php

use Illuminate\Support\Facades\Schedule;

// Keep empty monthly partitions ready ahead of time.
Schedule::command('tracking:partitions')->dailyAt('00:30')->withoutOverlapping();

// Archive finished rep-days (waits `archive_grace_hours` after local midnight for late uploads).
Schedule::command('tracking:archive')->hourlyAt(15)->withoutOverlapping();

// Drop raw partitions older than the hot retention window, once verified as archived.
Schedule::command('tracking:prune')->dailyAt('03:00')->withoutOverlapping();
