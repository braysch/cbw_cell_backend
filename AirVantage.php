<?php

namespace App\CommonClasses;

use Illuminate\Support\Facades\Cache;
use CBWCloud\Models\Device;
use \DateTime;
use \DateInterval;
use \DateTimeZone;
use \Exception;
use Illuminate\Support\Facades\Log; 

// If we ever pick up a new cell data provider, we will want to create an interface and do the things done in this video.
// https://www.youtube.com/watch?v=_z9nzEUgro4

class AirVantage implements CellDataProvider
{
    protected $token = "";
    protected $refreshToken = "";
    protected $curlResponseCode = 0;

    private $apiKey = "";
    private $apiRefresh = "";
    private $apiUrl = "";
    private $apiUsername = "";
    private $apiPassword = "";
    private $apiClientID = "";
    private $apiClientSecret = "";

    private $offerID5MB = "";
    private $offerID10MB = "";
    private $offerID30MB = "";
    private $offerID50MB = "";

    private $operationsEndpoint = "";
    private $systemsEndpoint = "";
    private $activateEndpoint = "";
    private $suspendEndpoint = "";
    private $resumeEndpoint = "";
    private $changeOfferEndpoint = "";
    private $dataUsageEndpoint = "";
    private $networkDeatchEndpoint = "";
    private $tokenEndpoint = "";

    // Use this constructor to get the AirVantage token from the cache
    // https://laravel.com/docs/7.x/cache#retrieving-items-from-the-cache
    
    public function __construct(
        $apiKey,
        $apiRefresh,
        $apiUrl,
        $apiUsername,
        $apiPassword,
        $apiClientID,
        $apiClientSecret,
        $offerID5MB,
        $offerID10MB,
        $offerID30MB,
        $offerID50MB,
        $operationsEndpoint,
        $systemsEndpoint,
        $activateEndpoint,
        $suspendEndpoint,
        $resumeEndpoint,
        $changeOfferEndpoint,
        $reportUsageEndpoint,
        $networkDeatchEndpoint,
        $tokenEndpoint)
    {
        $this->apiKey = $apiKey;
        $this->apiRefresh = $apiRefresh;
        $this->apiUrl = $apiUrl;
        $this->apiUsername = $apiUsername;
        $this->apiPassword = $apiPassword;
        $this->apiClientID = $apiClientID;
        $this->apiClientSecret = $apiClientSecret;

        $this->offerID5MB = $offerID5MB;
        $this->offerID10MB = $offerID10MB;
        $this->offerID30MB = $offerID30MB;
        $this->offerID50MB = $offerID50MB;

        $this->operationsEndpoint = $operationsEndpoint;
        $this->systemsEndpoint = $systemsEndpoint;
        $this->activateEndpoint = $activateEndpoint;
        $this->suspendEndpoint = $suspendEndpoint;
        $this->resumeEndpoint = $resumeEndpoint;
        $this->changeOfferEndpoint = $changeOfferEndpoint;
        $this->dataUsageEndpoint = $reportUsageEndpoint;
        $this->networkDeatchEndpoint = $networkDeatchEndpoint;
        $this->tokenEndpoint = $tokenEndpoint;

        if (!Cache::has($apiKey)) {
            if (!Cache::has($apiRefresh)) {
                $this->fullAuthRefresh();
            } else {
                $refreshToken = Cache::get($apiRefresh);
                $this->authWithRefreshToken();
            }
        } else {
            $token = Cache::get($apiKey);
            $refreshToken = Cache::get($apiRefresh);
        }
    }

	public function getCurlResponseCode() { return $this->curlResponseCode;	}

    public function postRequest($fullUrl, $params, $authRequest, $count)
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $fullUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
        ));

        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

        // see if we need to include the bearer token
        if ($authRequest == true) {
            $auth = "Authorization: Bearer " . Cache::get($apiKey);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array($auth));
        }

        $output = curl_exec($ch);
        $this->curlResponseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($count == 0){
            // we need to handle unauthorized responses and invalid_token responses
            // they should have a response code of 401

            if ($this->curlResponseCode == 401) {
                // we need to get a new access token and then resend the initial request
                $this->fullAuthRefresh();
                $this->postRequest($fullUrl, $params, $authRequest, $count++);
            }
        }

        return $output;
    }

		public function postJsonRequest($fullUrl, $params, $authRequest, $count)
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $fullUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
        ));

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));

        // see if we need to include the bearer token
				$headers = ['Content-Type: application/json'];
        if ($authRequest == true) {
            array_push($headers, "Authorization: Bearer " . Cache::get($this->apiKey));
        }

				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $output = curl_exec($ch);
        $this->curlResponseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($count == 0){
             // we need to handle unauthorized responses and invalid_token responses
            // they should have a response code of 401 

            if ($this->curlResponseCode == 401) {
                // we need to get a new access token and then resend the initial request
                $this->fullAuthRefresh();
                $this->postJsonRequest($fullUrl, $params, $authRequest, $count++);
            }
        }

        return $output;
    }

    public function getRequest($fullUrl, $params, $authRequest, $count)
    {
        if ($params) {
            $fullUrl = $fullUrl . "?" . http_build_query($params);
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $fullUrl,
            CURLOPT_POST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
        ));

        // see if we need to include the bearer token
        if ($authRequest == true) {
            $auth = "Authorization: Bearer " . Cache::get($this->apiKey);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array($auth));
        }

        $output = curl_exec($ch);

        $this->curlResponseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($count == 0){
            // we need to handle unauthorized responses and invalid_token responses
            // they should have a response code of 401

            if ($this->curlResponseCode == 401) {
                // we need to get a new access token and then resend the initial request
                $this->fullAuthRefresh();
                $this->getRequest($fullUrl, $params, $authRequest, $count++);
            }
        }

        return $output;
    }

    public function fullAuthRefresh()
    {
        $fullUrl = config($this->apiUrl) . $this->tokenEndpoint;

        // these are the parameters required for the api call
        // get AirVantage credentials from the .env file
        $params = [
            'grant_type' => 'password',
            'username' => config($this->apiUsername),
            'password' => config($this->apiPassword),
            'client_id' => config($this->apiClientID),
            'client_secret' => config($this->apiClientSecret)
        ];

        $result = $this->postRequest($fullUrl, $params, false, 0);

        if ($this->curlResponseCode == 200) {
            // decode the json response and grab the token and refreshToken
            $data = json_decode($result, true);

            if ($data["access_token"] !== null) {
                // After we perform the full auth, store the new values in the cache.
                Cache::put($this->apiKey, $data['access_token'],$data['expires_in']);
                Cache::put($this->apiRefresh, $data['refresh_token'],$data['expires_in']); 
            }
        }
    }

    // We use this one when we have a refresh token we can use.
    public function authWithRefreshToken()
    {
        // for now we will just do a full refresh using the username/password
        $this->fullAuthRefresh();

        // TODO: We need to perform the authentication here using the refresh token

        // After we perform the full auth, store the new values in the cache.
        //Cache::put($apiKey, $token);
        //Cache::put($apiRefresh, $refreshToken);
    }
    public function getOfferIDbyPlan($plan=null){
        switch ($plan) {
            case "5MB":
                $offerID = $this->offerID5MB;
                break;
            case "10MB":
                $offerID = $this->offerID10MB;
                break;
            case "30MB":
                $offerID = $this->offerID30MB;
                break;
            case "50MB":
                $offerID = $this->offerID50MB;
                break;
            default:
                // default is the 10MB plan
                $offerID = $this->offerID10MB;
                break;
        }
        return $offerID;
    }
    public function getPlanByOfferID($offerID=null){
        switch ($offerID) {
            case $this->offerID5MB:
                $plan = "5MB";
                break;
            case $this->offerID10MB:
                $plan = "10MB";
                break;
            case $this->offerID30MB:
                $plan = "30MB";
                break;
            case $this->offerID50MB:
                $plan = "50MB";
                break;
            default:
                // default is the 10MB plan
                $plan = "10MB";
                break;
        }
        return $plan;
    }
    
    // Get data usage using the AirVantage data/aggregated api
    // The time stamps are in GMT time, but we don't want to convert them to local time
    // I think they use GTM time for billing purposes.
    public function getDataUsage(Device $device, $request, $path)
    {
        // first get the params that were passed to us from our api
        // we'll use these over the defaults
        $params = $request->all();
        $fullUrl = config($this->apiUrl) . $this->dataUsageEndpoint;

        // check for from timestamp
        if (array_key_exists('from', $params) !== true) {
            $d = new DateTime('first day of this month midnight', new \DateTimeZone("UTC"));
            $d = $d->format('U');
            $params['from'] = $d . '000';
        }

        // check for to timestamp
        if (array_key_exists('to', $params) !== true) {
            $d = new DateTime('last day of this month midnight', new \DateTimeZone("UTC"));
            $d->add(new DateInterval('P1D'));
            $d = $d->format('U');
            $params['to'] = $d . '000';
        }

        // check to make sure this is a cell device
        if (empty($device->iccid)) {
            return response()->json(['message' => 'non-cell device']);
        }

        // see if we need to get the air vantage uid
        if (empty($device->air_vantage_uid)) {
            $result = $this->getUIDFromICCID($device->iccid);

            if ($result !== null) {
                $device->air_vantage_uid = $result;
                $device->save();
            } else {
                return response()->json(['message' => 'could not obtain uid']);
            }
        }

        // we need to grab the air vantage sim card uid (not the same as iccid)
        $params['targetIds'] = $device->air_vantage_uid;

        if (array_key_exists('dataIds', $params) == false) {
            $params['dataIds'] = 'DATA_ROUNDED_BYTES_TOTAL';
        }

        if (array_key_exists('interval', $params) == false) {
            $params['interval'] = '1day';
        }


        if (array_key_exists('fn', $params) == false) {
            $params['fn'] = 'sum';
        }

        // example api request
        // https://eu.airvantage.net/api/v1/systems/data/aggregated?timestamp=1589824697128&fn=sum&targetIds=b3dcf24b51e746ce959332c5f2956725&dataIds=DATA_ROUNDED_BYTES_TOTAL&from=1588291200000&to=1590969600000&interval=1day
        // https://{{hostname}}/api/v1/systems/data/aggregated?timestamp=1589824697128&fn=sum&targetIds=b3dcf24b51e746ce959332c5f2956725&dataIds=DATA_ROUNDED_BYTES_TOTAL&from=1588291200000&to=1590969600000&interval=1day

        return $this->getRequest($fullUrl, $params, true, 0);

        if ($this->curlResponseCode == 200) {
            return $result;
        } else {
            // check for a bad token
            if (strpos($result, 'invalid token') !== false) {
                // we need to get a new token
                $this->authWithRefreshToken();
                // try request again
                $result = $this->getRequest($fullUrl, $params, true, 0);

                if ($this->curlResponseCode == 200) {
                    return $result;
                }
            }

            // if we get here then return the error message we got from AirVantage api
            return response()->json(['message' => $result]);
        }
    }
    /*
       $air_vantage_uids = array of air_vantage_uids
       $request  - the request object
       $path 
    */

    public function getDataUsageForUIDs($air_vantage_uids, $request) // get data usage for array of air_vantage_uid
    {
        // first get the params that were passed to us from our api
        // we'll use these over the defaults
        $params = $request->all();
        $fullUrl = config($this->apiUrl) . $this->dataUsageEndpoint;

        // check for from timestamp
        if (array_key_exists('from', $params) !== true) {
            $d = new DateTime('first day of this month midnight', new \DateTimeZone("UTC"));
            $d = $d->format('U');
            $params['from'] = $d . '000';
        }

        // check for to timestamp
        if (array_key_exists('to', $params) !== true) {
            $d = new DateTime('last day of this month midnight', new \DateTimeZone("UTC"));
            $d->add(new DateInterval('P1D'));
            $d = $d->format('U');
            $params['to'] = $d . '000';
        }

        // we need to grab the air vantage sim card uid (not the same as iccid)
        $params['targetIds'] = implode(',',$air_vantage_uids);

        if (array_key_exists('dataIds', $params) == false) {
            $params['dataIds'] = 'DATA_ROUNDED_BYTES_TOTAL';
        }

        if (array_key_exists('interval', $params) == false) {
            $params['interval'] = '1month';
        }

        if (array_key_exists('fn', $params) == false) {
            $params['fn'] = 'sum';
        }

        // example api request
        //https://{{hostname}}/api/v1/systems/data/aggregated?timestamp=1589824697128&fn=sum&targetIds=b3dcf24b51e746ce959332c5f2956725&dataIds=DATA_ROUNDED_BYTES_TOTAL&from=1588291200000&to=1590969600000&interval=1day

        $result = $this->getRequest($fullUrl, $params, true, 0);

        if ($this->curlResponseCode == 200) {
            return $result;
        } else {
            // check for a bad token
            if (strpos($result, 'invalid token') !== false) {
                // we need to get a new token
                $this->authWithRefreshToken();
                // try request again
                $result = $this->getRequest($fullUrl, $params, true, 0);

                if ($this->curlResponseCode == 200) {
                    return $result;
                }
            }

            // if we get here then return the error message we got from AirVantage api
            return response()->json(['message' => $result]);
        }
    }

    public function activateRequestBody(array $uids, string $account, string $plan = "10MB", $includeOperatorParameters)
    {
        // create activate request
       $offerID = $this->getOfferIDbyPlan($plan);
        $data = array(
            "offerId" => $offerID,
            "scheduledTime" => null,
            "timeout" => null,
            "notify" => false,
            "systems" => array(
                "uids" => $uids
            ),

        );

        if ($includeOperatorParameters == true){
            $data[] = array(
                "operatorParameters" => array(
                    array(
                        "account" => $account,
                        "parameters" => (object)null,
                    )
                )
            );
        }
        
        return json_encode($data);
    }
    public function suspend (array $devices, $accountId = "") {
			$body = [
				'systems' => [
					'uids' => []	
				],
				'operatorparameters' => [
					[
						'account' => $accountId,
						'parameters' => (object)null
					]	
				]
			];

			foreach($devices as $device) {
				if(empty($device->air_vantage_uid)) {
					$air_vantage_uid = $this->getUIDFromICCID($device->iccid);
					if($air_vantage_uid !== null) {
						$device->air_vantage_uid = $air_vantage_uid;	
						$device->save();
					}
				}
				array_push($body['systems']['uids'], $device->air_vantage_uid);
			}

			$fullUrl = config($this->apiUrl) . $this->suspendEndpoint;
			$result = $this->postJsonRequest($fullUrl, $body, true, 0);
			return json_decode($result);
    }

		/**
		 * resume service for a list of devices
		 *
		 * @param array $devices
		 * @param string $avaccountid
		 * @return array
		 */
		public function resume($devices, $accountId = '') {
			$body = [
				'systems' => [
					'uids' => []	
				],
				'operatorparameters' => [
					[
						'account' => $accountId,
						'parameters' => (object)null
					]
				]
			];

			foreach($devices as $device) {
				if(empty($device->air_vantage_uid)) {
					$air_vantage_uid = $this->getUIDFromICCID($device->iccid);
					if($air_vantage_uid !== null) {
						$device->air_vantage_uid = $air_vantage_uid;	
						$device->save();
					}
				}
				array_push($body['systems']['uids'], $device->air_vantage_uid);
			}

			$fullUrl = config($this->apiUrl) . $this->resumeEndpoint;
			$result = $this->postJsonRequest($fullUrl, $body, true, 0);
			return json_decode($result);
		}
    public function changeOffer(array $devices, $accountId = "", $plan = "10MB")
    {
        //$this->fullAuthRefresh();
        $uids = [];
        foreach($devices as $device){
            // see if we need to get the air vantage uid
            if (empty($device->air_vantage_uid)) {
                $result = $this->getUIDFromICCID($device->iccid);

                if ($result !== null) {
                    $device->air_vantage_uid = $result;
                    $device->save();
                    $uids[] = $device->air_vantage_uid;
                } else {
                    return 'UNKNOWN';
                }
            } else {
                $uids[] = $device->air_vantage_uid;
            }
        }

				$body = json_decode($this->activateRequestBody($uids, $accountId, $plan, false));
                $fullUrl = config($this->apiUrl) . $this->changeOfferEndpoint;

				$result = $this->postJsonRequest($fullUrl, $body, true, 0);
				return json_decode($result);
    }
    public function activate(Device $device, $accountId, $plan = "10MB")
    {
        $uids = [];
        // see if we need to get the air vantage uid
        if (empty($device->air_vantage_uid)) {
            $result = $this->getUIDFromICCID($device->iccid);

            if ($result !== null) {
                $device->air_vantage_uid = $result;
                $device->save();
                $uids[] = $device->air_vantage_uid;
            } else {
                return 'UNKNOWN';
            }
        } else {
            $uids[] = $device->air_vantage_uid;
        }

        $url = config($this->apiUrl);
        $endpoint = $this->activateEndpoint;
        $ch = curl_init($url . $endpoint);

        $headers = array(
            'Content-type: application/json',
            "Authorization: Bearer " . Cache::get($this->apiKey)
        );


        $includeOperatorParameters = true;
        $body = $this->activateRequestBody($uids, $accountId, $plan, $includeOperatorParameters);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);
        $response = json_decode($response);

        return $response;
    }

    public function networkDetach(Device $device){
       $uid = null;
        if (empty($device->air_vantage_uid)) {
            $result = $this->getUIDFromICCID($device->iccid);

            if ($result !== null) {
                $device->air_vantage_uid = $result;
                $device->save();
                $uid = $device->air_vantage_uid;
            } else {
                return 'UNKNOWN';
            }
        } else {
            $uid = $device->air_vantage_uid;
        }
        $url = config($this->apiUrl);
        $endpoint = $this->networkDeatchEndpoint;
        $ch = curl_init($url . $endpoint);

        $headers = array(
            'Content-type: application/json',
            "Authorization: Bearer " . Cache::get($this->apiKey)
        );

        
        // TODO: make this work with more than 1 uid up to 100
        $body = array(
            "systems" => array(                
                "uids" => array ($uid)
            )
        );
    
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $this->curlResponseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				if ($this->curlResponseCode == 401) {
					// we need to get a new access token and then resend the initial request
					$this->fullAuthRefresh();
					return $this->networkDetach($device);
				}
        return $this->getCurlResponseCode();
    }

    public function getOperation(string $operation)
    {
        $fullUrl = config($this->apiUrl) . $this->operationsEndpoint . $this->operation;
        return $this->getRequest($fullUrl, null, true, 0);
    }

    public function getLifeCycleState(Device $device)
    {
        $uid = $device->air_vantage_uid;

        // see if we need to get the air vantage uid
        if (empty($device->air_vantage_uid)) {

            $result = $this->getUIDFromICCID($device->iccid);

            if ($result !== null) {
                $device->air_vantage_uid = $result;
                //$device->save();
                $uid = $device->air_vantage_uid;
            } else {
                return 'UNKNOWN';
            }
        }

        $fullUrl = config($this->apiUrl) . $this->systemsEndpoint . $uid;
        $response = $this->getRequest($fullUrl, null, true, 0);
        $response = json_decode($response);

        if (property_exists($response, 'lifeCycleState')) {
            return $response->lifeCycleState;
        }
        return 'UNKNOWN';
    }

    public function getUIDFromICCID(string $iccid)
    {
        $params['fields'] = "uid,name";
        $params['name'] = $iccid;
        $fullUrl = config($this->apiUrl) . $this->systemsEndpoint;
        $response = $this->getRequest($fullUrl, $params, true, 0);
        $response = json_decode($response);

        if ($response === null) {
            return null;
        }
        if (property_exists($response, 'items')) {
            if (isset($response->items[0]->uid))
                return $response->items[0]->uid;
        }

        return null;
    }

    public function setActiveProfile(string $eid, string $iccid)
    {
        // this function is specific to Telit for now
        // but it still must be included in this file
        // even if it does nothing
        // because it's included in the interface
    }
}