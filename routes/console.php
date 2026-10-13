<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tracking:archive')->hourly();       // archive finished days
Schedule::command('tracking:prune')->dailyAt('03:00');  // remove raw points older than 90 days
Schedule::command('tracking:partitions')->monthly();   // prepare partitions for the coming months
