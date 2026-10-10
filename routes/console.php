<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Schedule & Tasks
|--------------------------------------------------------------------------
| Periodically polls WESS for branch appointment updates and syncs them
| to GoHighLevel calendars every 10 minutes.
*/
Schedule::command('wess:sync')->everyTenMinutes();
