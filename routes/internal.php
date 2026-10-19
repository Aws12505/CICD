<?php

use App\Http\Controllers\TerminalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Internal endpoints
|--------------------------------------------------------------------------
|
| Called by the terminal gateway, never by a browser. No session or CSRF;
| each request carries the gateway's shared secret, checked in the
| controller, and anything else gets a 404.
|
*/

Route::post('/terminal/redeem', [TerminalController::class, 'redeem'])->middleware('throttle:60,1')->name('internal.terminal.redeem');
Route::post('/terminal/finish', [TerminalController::class, 'finish'])->name('internal.terminal.finish');
