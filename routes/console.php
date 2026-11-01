<?php

use Illuminate\Support\Facades\Schedule;

// Abandoned rooms and long-gone guests (runs in the "scheduler" container)
Schedule::command('bombbusters:prune')->dailyAt('04:00');
