<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\v1\ReportController;
use App\Http\Controllers\API\v1\GraphController;
use App\Http\Controllers\API\v1\IOEndpointController;
use App\Http\Controllers\API\v1\PanelController;
use App\Http\Controllers\API\v1\ScheduledEventController;

/// ...

Route::prefix('v1')->group(function () {
     // Here are the API's that don't need authentication
     Route::middleware(['throttle:1200,1'])->group(function () {
        // CellDataProvider callback URLs
        Route::post('/cellDataProvider/suspend', 'API\v1\sales\SIMCardController@suspend');
        Route::post('/cellDataProvider/resume', 'API\v1\sales\SIMCardController@resume');
        Route::post('/cellDataProvider/changeoffer','API\v1\sales\SIMCardController@changeOffer');
        Route::post('/cellDataProvider/reportusage','API\v1\sales\SIMCardController@reportUsage');
        Route::post('/cellDataProvider/dataUsageNotification', 'API\v1\sales\SIMCardController@sendDataUsageNotification');
        Route::post('/cellDataProvider/changePrimaryIccid', 'API\v1\sales\SIMCardController@changePrimaryIccid');
    });

    /// ...

});

/// ...