<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Schedule & Tasks
|--------------------------------------------------------------------------
| Periodically polls WESS for branch appointment updates and syncs them
| to CRM calendars every 30 minutes.
*/
Schedule::command('wess:sync')->everyThirtyMinutes()->withoutOverlapping();
