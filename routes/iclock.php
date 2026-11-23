<?php

use App\Http\Controllers\Iclock\IclockController;
use Illuminate\Support\Facades\Route;

// ZKTeco ADMS push protocol. Loaded without the web middleware group:
// no session, no CSRF, no cookies.
Route::get('/iclock/cdata', [IclockController::class, 'handshake'])->name('iclock.handshake');
Route::post('/iclock/cdata', [IclockController::class, 'upload'])->name('iclock.upload');
Route::get('/iclock/getrequest', [IclockController::class, 'getrequest'])->name('iclock.getrequest');
Route::post('/iclock/devicecmd', [IclockController::class, 'devicecmd'])->name('iclock.devicecmd');
