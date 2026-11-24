<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FlagController;
use App\Http\Controllers\Api\SdkFlagsController;
use App\Http\Middleware\AuthenticateAdminKey;
use App\Http\Middleware\AuthenticateEnvironmentKey;
use App\Http\Middleware\ScrubCredentials;
use App\Http\Middleware\ThrottleManagement;
use App\Http\Middleware\ThrottleSdkRequests;
use App\Http\Middleware\ValidateJsonRequest;
use App\Support\FlagpostConfig;
use Illuminate\Support\Facades\Route;

Route::middleware([
    ThrottleManagement::class,
    AuthenticateAdminKey::class,
    ScrubCredentials::class,
    ValidateJsonRequest::class,
])->group(function (): void {
    Route::post('/flags', [FlagController::class, 'store']);
    Route::get('/flags', [FlagController::class, 'index']);
    Route::get('/flags/{key}', [FlagController::class, 'show']);
    Route::patch('/flags/{key}', [FlagController::class, 'update']);
    Route::delete('/flags/{key}', [FlagController::class, 'destroy']);

    Route::put('/flags/{key}/environments/{env}', [FlagController::class, 'replaceEnvironment'])
        ->whereIn('env', FlagpostConfig::environments());

    Route::post('/flags/{key}/environments/{env}/explain', [FlagController::class, 'explain'])
        ->whereIn('env', FlagpostConfig::environments());
});

Route::middleware([
    AuthenticateEnvironmentKey::class,
    ScrubCredentials::class,
    ThrottleSdkRequests::class,
])->get('/sdk/flags', SdkFlagsController::class);
