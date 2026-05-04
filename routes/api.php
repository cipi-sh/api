<?php

use CipiApi\Http\Controllers\ActivityController;
use CipiApi\Http\Controllers\AliasController;
use CipiApi\Http\Controllers\AppController;
use CipiApi\Http\Controllers\DbController;
use CipiApi\Http\Controllers\DeployController;
use CipiApi\Http\Controllers\DeployHistoryController;
use CipiApi\Http\Controllers\DeviceController;
use CipiApi\Http\Controllers\JobController;
use CipiApi\Http\Controllers\PingController;
use CipiApi\Http\Controllers\SearchController;
use CipiApi\Http\Controllers\ServerController;
use CipiApi\Http\Controllers\SslController;
use Illuminate\Support\Facades\Route;

// Public health probe — used by mobile apps to validate the URL before login.
Route::get('/api/ping', PingController::class)->middleware('throttle:60,1');

Route::prefix('api')->middleware(['auth:sanctum'])->group(function () {
    // Apps
    Route::get('/apps', [AppController::class, 'list'])->middleware('ability:apps-view');
    Route::get('/apps/{name}', [AppController::class, 'show'])->middleware('ability:apps-view');
    Route::post('/apps', [AppController::class, 'create'])->middleware('ability:apps-create');
    Route::put('/apps/{name}', [AppController::class, 'edit'])->middleware('ability:apps-edit');
    Route::delete('/apps/{name}', [AppController::class, 'delete'])->middleware('ability:apps-delete');

    // Aliases
    Route::get('/apps/{name}/aliases', [AliasController::class, 'list'])->middleware('ability:aliases-view');
    Route::post('/apps/{name}/aliases/{alias}', [AliasController::class, 'create'])->middleware('ability:aliases-create');
    Route::delete('/apps/{name}/aliases/{alias}', [AliasController::class, 'delete'])->middleware('ability:aliases-delete');

    // Deploy
    Route::post('/apps/{name}/deploy', [DeployController::class, 'deploy'])->middleware('ability:deploy-manage');
    Route::post('/apps/{name}/deploy/rollback', [DeployController::class, 'rollback'])->middleware('ability:deploy-manage');
    Route::post('/apps/{name}/deploy/unlock', [DeployController::class, 'unlock'])->middleware('ability:deploy-manage');

    // Deploy history (read-only, mobile dashboard friendly)
    Route::get('/apps/{name}/deploys', [DeployHistoryController::class, 'list'])->middleware('ability:deploy-view');
    Route::get('/apps/{name}/deploys/{job}', [DeployHistoryController::class, 'show'])->middleware('ability:deploy-view');
    Route::get('/apps/{name}/deploys/{job}/log', [DeployHistoryController::class, 'log'])->middleware('ability:deploy-view');

    // SSL
    Route::post('/apps/{name}/ssl', [SslController::class, 'install'])->middleware('ability:ssl-manage');
    Route::get('/apps/{name}/ssl', [SslController::class, 'info'])->middleware('ability:ssl-view');
    Route::post('/apps/{name}/ssl/renew', [SslController::class, 'install'])->middleware('ability:ssl-manage');

    // Databases
    Route::get('/dbs', [DbController::class, 'list'])->middleware('ability:dbs-view');
    Route::post('/dbs', [DbController::class, 'create'])->middleware('ability:dbs-create');
    Route::delete('/dbs/{name}', [DbController::class, 'delete'])->middleware('ability:dbs-delete');
    Route::post('/dbs/{name}/backup', [DbController::class, 'backup'])->middleware('ability:dbs-manage');
    Route::post('/dbs/{name}/restore', [DbController::class, 'restore'])->middleware('ability:dbs-manage');
    Route::post('/dbs/{name}/password', [DbController::class, 'password'])->middleware('ability:dbs-manage');

    // Jobs
    Route::get('/jobs/{id}', [JobController::class, 'show']);
    Route::get('/jobs/{id}/log/tail', [JobController::class, 'logTail'])
        ->middleware('throttle:120,1');

    // Server status & metrics
    Route::get('/server/status', [ServerController::class, 'status'])
        ->middleware(['ability:server-view', 'throttle:120,1']);
    Route::get('/server/metrics', [ServerController::class, 'metrics'])
        ->middleware(['ability:server-view', 'throttle:60,1']);
    Route::get('/server/ssl/expiring', [ServerController::class, 'sslExpiring'])
        ->middleware(['ability:ssl-view', 'throttle:30,1']);

    // Devices for push notifications
    Route::get('/devices', [DeviceController::class, 'list']);
    Route::post('/devices', [DeviceController::class, 'create'])->middleware('throttle:30,1');
    Route::patch('/devices/{id}', [DeviceController::class, 'update']);
    Route::delete('/devices/{id}', [DeviceController::class, 'delete']);

    // Activity log + search
    Route::get('/activity', [ActivityController::class, 'list'])->middleware('ability:activity-view');
    Route::get('/search', SearchController::class)->middleware('throttle:60,1');
});
