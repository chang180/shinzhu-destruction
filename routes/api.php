<?php

use App\Http\Controllers\Api\V1\DataStatusController;
use App\Http\Controllers\Api\V1\LevelController;
use App\Http\Controllers\Api\V1\ReplayController;
use App\Http\Controllers\Api\V1\RunActionController;
use App\Http\Controllers\Api\V1\RunController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 同源 JSON 介面 v1
|--------------------------------------------------------------------------
|
| 這些路由由 bootstrap/app.php 掛在 `web` middleware group 底下。網址雖然是
| /api/v1，仍然走工作階段與 CSRF 保護（TECHNICAL-SPEC §6）——匿名存檔綁在
| HttpOnly session，沒有 middleware 就等於誰都能改別人的局。
|
*/

Route::get('levels', [LevelController::class, 'index'])->name('levels.index');
Route::get('runs', [RunController::class, 'index'])->name('runs.index');
Route::post('runs/{run}/retry', [RunController::class, 'retry'])->middleware('throttle:game-actions')->name('runs.retry');
Route::post('runs', [RunController::class, 'store'])->name('runs.store');
Route::get('runs/{run}', [RunController::class, 'show'])->name('runs.show');
/*
 * 施招端點限流。回合制遊戲一回合只有一個決策，正常玩家遠遠打不到上限；
 * 這是防止腳本洗結算，不是拿來卡人。超過時 Laravel 會回 429 並附上
 * Retry-After，前端據此等待再重送**原本的** action_id（TECHNICAL-SPEC §6）。
 */
Route::post('runs/{run}/actions', [RunActionController::class, 'store'])
    ->middleware('throttle:game-actions')
    ->name('runs.actions.store');
Route::get('runs/{run}/replay', [ReplayController::class, 'show'])->name('runs.replay');
Route::get('data-status', [DataStatusController::class, 'show'])->name('data-status');
