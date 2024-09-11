<?php

namespace App\CommonClasses;

use Illuminate\Support\Facades\Cache;
use CBWCloud\Models\Device;
use \DateTime;
use \DateInterval;
use \DateTimeZone;
use \Exception;
use Illuminate\Support\Facades\Log; 

// This interface for multiple cell data providers was created based on the instructions in this video:
// https://www.youtube.com/watch?v=_z9nzEUgro4

interface CellDataProvider
{
    // The interface declares the required functions for classes which implement it.
    // these functions are implemented in AirVantage.php and Telit.php.

	public function getCurlResponseCode();

    public function postRequest($fullUrl, $params, $authRequest, $count);

	public function postJsonRequest($fullUrl, $params, $authRequest, $count);

    public function getRequest($fullUrl, $params, $authRequest, $count);

    public function fullAuthRefresh();

    public function authWithRefreshToken();

    public function getOfferIDbyPlan($plan);

    public function getPlanByOfferID($offerID);
    
    public function getDataUsage(Device $device, $request, $path);

    public function getDataUsageForUIDs($air_vantage_uids, $request);

    // activateRequestBody is only called within AirVantageTest.php and AirVantage.php and could be refactored out.
    public function activateRequestBody(array $uids, string $account, string $plan, $includeOperatorParameters);

    public function suspend(array $devices, $accountId);

	public function resume(array $devices, $accountId);

    public function changeOffer(array $devices, $accountId, $plan);

    public function activate(Device $device, $accountId, $plan);

    public function networkDetach(Device $device);

    // getOperation is only called within AirVantageTest.php and could be refactored out.
    public function getOperation(string $operation);

    public function getLifeCycleState(Device $device);

    public function getUIDFromICCID(string $iccid);

    public function setActiveProfile(string $eid, string $iccid);
}