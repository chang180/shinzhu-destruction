<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 開放資料更新排程
|--------------------------------------------------------------------------
|
| 這是「抓取頻率」，不是把月報／年度資料改稱每日或每週更新：
| 水庫水情上游每小時更新，園區用水與國土利用是不定期發布的年度統計，
| 只是定期檢查是否換版。
|
| Hostinger 只需要一條 cron 呼叫 `artisan schedule:run`，不需要常駐 worker。
| 每個來源獨立鎖，避免上一輪還沒結束就重疊執行。
|
*/

Schedule::command('opendata:refresh --schedule=hourly')
    ->hourly()
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('opendata-hourly');

Schedule::command('opendata:refresh --schedule=daily')
    ->dailyAt('04:10')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('opendata-daily');

Schedule::command('opendata:refresh --schedule=weekly')
    ->weeklyOn(1, '04:40')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->name('opendata-weekly');
