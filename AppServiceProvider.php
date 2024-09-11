<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use App\CommonClasses\AirVantage;
use App\CommonClasses\Telit;
use App\CommonClasses\CellDataProvider;
use CBWCloud\Models\Device;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\DB;
use App\CommonClasses\CellDataProviderFactory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Bind in the CellDataProvider as a singleton so if it is invoked multiple times throughout
        // a single request, it won't waste time and resources on new objects.
        $this->app->singleton(CellDataProvider::class, function ($app) {
            $devices = Request::get('devices', []);
            $service_provider = "";

            foreach ($devices as $deviceModel) {
                    try {
                        if ($deviceModel) {
                            $iccid = $deviceModel->iccid; // get the iccid from the devices table
                            $simCard = DB::table("SIM_cards")->where("iccid", $iccid)->first();
                            if ($simCard) {
                                $service_provider = $simCard->service_provider; // get the service_provider from the sim card table
                            } else {
                                throw new \Exception("SIM card with ICCID $iccid not found.");
                            }
                        } else {
                            throw new \Exception("Device model is null");
                        }
                    } catch (\Exception $e) {
                        Log::channel('testing')->info($e->getMessage());
                        $service_provider = "error";
                    }
                // Log::channel('testing')->info("Service provider identified as " . $service_provider);
                return CellDataProviderFactory::create($service_provider);
            }
            return CellDataProviderFactory::create('air_vantage'); // default
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);
    }
}