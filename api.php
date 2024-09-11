<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\v1\ReportController;
use App\Http\Controllers\API\v1\GraphController;
use App\Http\Controllers\API\v1\IOEndpointController;
use App\Http\Controllers\API\v1\PanelController;
use App\Http\Controllers\API\v1\ScheduledEventController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
Route::prefix('v1')->middleware(['auth:api', 'xrdiSales', 'verified', 'throttle:1200,1'])->group(function () {
    // GOTCHA: moving this section below the next prefix('v1') causes the deviceSeats and SIMcards routes to fail

    Route::patch('accounts/{accountId}/deviceSeats/{id}', 'API\v1\sales\DeviceSeatController@update');
    Route::patch('accounts/{accountId}/SIMcards/{id}', 'API\v1\sales\SIMCardController@update');
});
Route::prefix('v1')->group(function () {
     // Here are the API's that don't need authentication
     Route::middleware(['throttle:1200,1'])->group(function () {

        // ...

        // CellDataProvider callback URLs
        Route::post('/cellDataProvider/suspend', 'API\v1\sales\SIMCardController@suspend');
        Route::post('/cellDataProvider/resume', 'API\v1\sales\SIMCardController@resume');
        Route::post('/cellDataProvider/changeoffer','API\v1\sales\SIMCardController@changeOffer');
        Route::post('/cellDataProvider/reportusage','API\v1\sales\SIMCardController@reportUsage');
        Route::post('/cellDataProvider/dataUsageNotification', 'API\v1\sales\SIMCardController@sendDataUsageNotification');
        Route::post('/cellDataProvider/changePrimaryIccid', 'API\v1\sales\SIMCardController@changePrimaryIccid');
    });
    Route::middleware(['auth:api', 'verified', 'throttle:1200,1'])->group(function () {

        // ...

        if (env('APP_DEBUG', false) == true) {
            if (config('app.debug', false) == true) {   // We only enable this API while in development.
                Route::post('accounts/{AccountId}/devices', 'API\v1\DeviceController@store');
            }
        }
        Route::post('accounts/{AccountId}/devices', 'API\v1\DeviceController@add')->middleware('permission:Add Devices');
        Route::post('accounts/{AccountId}/devices/{DeviceId}', 'API\v1\DeviceController@update')->where('DeviceId', '[0-9]+')->middleware('permission:Edit Devices');
        Route::get('accounts/{AccountId}/devices/{DeviceId}/dataUsage/', 'API\v1\DeviceController@dataUsage')->where('DeviceId', '[0-9]+');
        Route::get('accounts/{AccountId}/devices/{DeviceId}/activateCell', 'API\v1\DeviceController@activateCell');
        Route::get('accounts/{AccountId}/devices/{DeviceId}/checkActiveStatus', 'API\v1\DeviceController@checkActiveStatus');

        // ...
        
    });
    
    // ...
    
});
Route::prefix('v1/admin')->middleware(['auth:api', 'xrdiAdmin', 'verified', 'throttle:600,1'])->group(function () {

    // ...

    Route::patch('devices/cell', 'API\v1\DeviceController@performCellAction');

    // ...
    
});

// ...
