<?php

namespace App\CommonClasses;

use App\CommonClasses\CellDataProvider;
use Illuminate\Support\Facades\Cache;
use CBWCloud\Models\Device;
use CBWCloud\Models\SimCard;
use \DateTime;
use \DateInterval;
use \DateTimeZone;
use \Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Notifications\DataUsageNotification; 

// Log::channel('testing')->info("message");

// The Telit class makes multiple references to a device attribute called 'air_vantage_uid'.
// Despite its name, 'air_vantage_uid' just refers to the unique identifier for our cell data provider. It is not specific to AirVantage!
// In Telit's case, 'air_vantage_uid' represents Telit's connection ID (which is different from the ICCID.)

class Telit implements CellDataProvider
{
    protected $curlResponseCode = 0;

    private $sessionId = "";
    private $apiUrl = "";
    private $apiUsername = "";
    private $apiPassword = "";

    private $offerID5MB = "";
    private $offerID10MB = "";
    private $offerID30MB = "";
    private $offerID50MB = "";
    
    public function __construct(
        $sessionId,
        $apiUrl,
        $apiUsername,
        $apiPassword,
        $offerID5MB,
        $offerID10MB,
        $offerID30MB,
        $offerID50MB)
    {
        $this->sessionId = $sessionId;
        $this->apiUrl = $apiUrl;
        $this->apiUsername = $apiUsername;
        $this->apiPassword = $apiPassword;

        $this->offerID5MB = $offerID5MB;
        $this->offerID10MB = $offerID10MB;
        $this->offerID30MB = $offerID30MB;
        $this->offerID50MB = $offerID50MB;

        if (!Cache::has($sessionId)) {
            $this->fullAuthRefresh();
        } else {
            $sessionId = Cache::get($sessionId);
        }
    }

    public function getCurlResponseCode()
    {
        return $this->curlResponseCode;
    }

    public function postRequest($fullUrl, $params, $authRequest = false, $count)
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
        // Initialize a cURL session
        $ch = curl_init();

        // Set cURL options for a POST request
        curl_setopt_array($ch, array(
            CURLOPT_URL => $fullUrl,
            CURLOPT_POST => true, // Set to true for a POST request
            CURLOPT_RETURNTRANSFER => true, // Return the response as a string
            CURLOPT_CONNECTTIMEOUT => 5, // Timeout for the connection
            CURLOPT_TIMEOUT => 5, // Timeout for the request
            CURLOPT_POSTFIELDS => json_encode($params), // Send parameters as JSON body
            CURLOPT_HTTPHEADER => array('Content-Type: application/json') // Set content type to JSON
        ));

        // Execute the cURL request and capture the response
        $output = curl_exec($ch);

        // Get the HTTP response code
        $this->curlResponseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Close the cURL session
        curl_close($ch);

        // If this is the first attempt (count is 0) and the response code is 401 (Unauthorized)
        if ($count == 0 && $this->curlResponseCode == 401) {
            // Refresh the session ID (implementation of fullAuthRefresh not provided, so adapt as needed)
            $this->fullAuthRefresh();
            // Retry the request with the new session ID
            return $this->getRequest($fullUrl, $params, $authRequest, $count + 1);
        }

        // Return the output from the cURL request
        return $output;
    }


    public function fullAuthRefresh()
    {
        // https://docs.devicewise.com/Content/Products/IoT_Portal_API_Reference_Guide/TR50_Interface/TR50-Interface.htm

        // REQUEST EXAMPLE
        // {
        //     "auth" : {
        //       "command" : "api.authenticate",
        //       "params" : {
        //         "username": "********",
        //         "password": "********"
        //       }
        //    }
        // }

        // RESPONSE EXAMPLE
        // {
        //     "auth": {
        //         "success": true,
        //         "params": {
        //             "orgKey": "CONTROL_BY_WEB",
        //             "sessionId": "abcd1234"
        //         }
        //     }
        // }

        $fullUrl = config($this->apiUrl);

        $body = [
            'auth' => [
                'command' => 'api.authenticate',
                'params' => [
                    'username' => config($this->apiUsername),
                    'password' => config($this->apiPassword)
                ]
            ]
        ];

        $result = $this->getRequest($fullUrl, $body, false, 0);

        if ($this->curlResponseCode == 200) {
            // decode the json response and grab the token and refreshToken
            $data = json_decode($result, true);

            $id = isset($data['auth']['params']['sessionId']) ? $data['auth']['params']['sessionId'] : null;
            if ($id !== null) {
                // Log::channel('testing')->info("New session ID: " . $id);
                Cache::put($this->sessionId, $id);
            }
        }
    }

    public function authWithRefreshToken() {
        // Telit does not use a refresh token, so this function is never called in this file.
        // This function must exist, even if it has no implementation, because it is required by the CellDataProvider interface.
    }

    public function getOfferIDbyPlan($plan=null) {
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

    public function getPlanByOfferID($offerID=null) {
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

    public function getDataUsage(Device $device, $request, $path)
    {
        // https://docs.devicewise.com/Content/Products/IoT_Portal_API_Reference_Guide/APIs/CDP/cdp.usage.data.aggregate.htm?Highlight=data%20usage

        $iccid = '';
        $uid = '';

        // check to make sure this is a cell device
        if (empty($device->iccid)) {
            return response()->json(['errorMessages' => 'non-cell device']);
        }

        $sim = SimCard::where('iccid', $device->iccid)->firstOrFail();

        // Check for alternate ICCID
        if($sim->alt_iccid != null) {
            $iccid = $sim->alt_iccid;
            $uid = $this->getUIDFromICCID($iccid);
        } else {
            $iccid = $device->iccid;
            $uid = $device->air_vantage_uid;
        }

        $fullUrl = config($this->apiUrl);

        // start timestamp
        $s = new DateTime('first day of this month midnight', new \DateTimeZone("UTC"));
        $start = $s->format('Y-m-d\TH:i:s\Z');

        // end timestamp
        $e = new DateTime('last day of this month midnight', new \DateTimeZone("UTC"));
        $end = $e->format('Y-m-d\TH:i:s\Z');

        $body = [
            'auth' => [
                "sessionId" => Cache::get($this->sessionId)
            ],
            'cmd' => [
                'command' => 'cdp.usage.data.aggregate',
                'params' => [
                    'iccid' => $iccid,
                    'start' => $start,
                    'end' => $end,
                ]
            ]
        ];
        
        $result = $this->getRequest($fullUrl, $body, false, 0);
        $result = json_decode($result, true);
        $success = isset($result['cmd']['success']) && $result['cmd']['success'] == true;

        if ($this->curlResponseCode == 200 && $success) {
            return $this->formatResult($result, $uid);
        } else {
                // retry authentication
                $this->fullAuthRefresh();
                // try request again
                $result = $this->getRequest($fullUrl, $body, false, 0);
                $result = json_decode($result, true);
                $success = isset($result['cmd']['success']) && $result['cmd']['success'] == true;

                if ($this->curlResponseCode == 200 && $success) {
                    return $this->formatResult($result, $uid);
                }

            // if we get here then return the error message we got from Telit api
            return response()->json(['errorMessages' => $result]);
        }
    }

    // The key difference between the Telit result and the AirVantage result (after reformatting)
    // is that while AV's results go to the end of the month, Telit's only go to the current day.
    // Telit data is measured in KB.

    private function formatResult($result, $connectionId)
    {
        // We need to make our result look just like the AirVantage result

        // Decode the JSON result if it's not already decoded
        if (is_string($result)) {
            $result = json_decode($result, true);
        }

        // Validate the structure of the result
        if (!isset($result['cmd']['params']['values'])) {
            return response()->json(['errorMessages' => 'Invalid response structure']);
        }

        $deviceId = $connectionId;
        $formattedResult = [
            $deviceId => [
                "DATA_ROUNDED_BYTES_TOTAL" => []
            ]
        ];

        $values = $result['cmd']['params']['values'];
        foreach ($values as $value) {
            $timestamp = strtotime($value['ts']) * 1000;
            $formattedResult[$deviceId]["DATA_ROUNDED_BYTES_TOTAL"][] = [
                "ts" => $timestamp,
                "v" => 1000*($value['value']) // convert from KB to B
            ];
        }

        // Reverse the list so that the first day of the month is at the bottom
        $formattedResult[$deviceId]["DATA_ROUNDED_BYTES_TOTAL"] = array_reverse($formattedResult[$deviceId]["DATA_ROUNDED_BYTES_TOTAL"]);

        return response()->json($formattedResult);
    }

    private function getConnection($iccid) {
        // https://docs.devicewise.com/Content/Products/IoT_Portal_API_Reference_Guide/APIs/CDP/cdp.connection.find.htm?Highlight=connection%20find
        // returns an object with details about the connection

        $fullUrl = config($this->apiUrl);
        $body = [
            'auth' => [
                "sessionId" => Cache::get($this->sessionId)
            ],
            'cmd' => [
                'command' => 'cdp.connection.find',
                'params' => [
                    'iccid' => $iccid
                ]
            ]
        ];

        $result = $this->getRequest($fullUrl, $body, false, 0);

        return $result;
    }

    private function getConnectionWithUID($uid) {
        // https://docs.devicewise.com/Content/Products/IoT_Portal_API_Reference_Guide/APIs/CDP/cdp.connection.find.htm?Highlight=connection%20find
        // returns an object with details about the connection

        $fullUrl = config($this->apiUrl);
        $body = [
            'auth' => [
                "sessionId" => Cache::get($this->sessionId)
            ],
            'cmd' => [
                'command' => 'cdp.connection.find',
                'params' => [
                    'id' => $uid
                ]
            ]
        ];

        $result = $this->getRequest($fullUrl, $body, false, 0);

        return $result;
    }

    public function getDataUsageForUIDs($air_vantage_uids, $request) // get data usage for array of connection IDs
    {
        // https://docs.devicewise.com/Content/Products/IoT_Portal_API_Reference_Guide/APIs/CDP/cdp.usage.data.aggregate.htm?Highlight=data%20usage
        // Data usage for AirVantage UIDs is summated for the entire month.
        // First use cdp.connection.find to get the monthly data usage,
        // If that's not available, then sum the data usage manually. This data is rounded.

        $fullUrl = config($this->apiUrl);

        $s = new DateTime('first day of this month midnight', new \DateTimeZone("UTC"));
        $start = $s->format('Y-m-d\TH:i:s\Z');
        $from = $s->format('U');

        $e = new DateTime('last day of this month midnight', new \DateTimeZone("UTC"));
        $end = $e->format('Y-m-d\TH:i:s\Z');

        $finalResult = [];

        foreach ($air_vantage_uids as $connectionId) {
            $result = "";
            $success = false;
            $dataUsage = null;
            $params = [];
            $connection = $this->getConnectionWithUID($connectionId);
            $connection = json_decode($connection, true);

            if (isset($connection['cmd']['params']['estimatedMonthData'])) {
                $dataUsage = 1000000*($connection['cmd']['params']['estimatedMonthData']); // convert from MB to B
            }
            else {
                $params = [
                    'auth' => [
                        "sessionId" => Cache::get($this->sessionId)
                    ],
                    'cmd' => [
                        'command' => 'cdp.usage.data.aggregate',
                        'params' => [
                            'connectionId' => $connectionId,
                            'start' => $start,
                            'end' => $end,
                        ]
                    ]
                ];
                $result = $this->getRequest($fullUrl, $params, false, 0);
                $result = json_decode($result, true);
                $success = isset($result['cmd']['success']) && $result['cmd']['success'] == true;
            }

            // reformat the result to look like the AirVantage result
            if (($this->curlResponseCode == 200 && $success) || $dataUsage != null) {
                $finalResult[$connectionId] = [
                    "DATA_ROUNDED_BYTES_TOTAL" => [
                        [
                            "ts" => intval($from),
                            "v" => $dataUsage ?? ($this->summate($result, $connectionId, $from))*1000 // covert from KB to B
                        ]
                    ]
                ];
            } else {
                // retry authentication
                $this->fullAuthRefresh();
                // try request again
                $connection = $this->getConnectionWithUID($connectionId);
                $connection = json_decode($connection, true);

                if (isset($connection['cmd']['params']['usageMonthData'])) {
                    $dataUsage = 1000000*($connection['cmd']['params']['usageMonthData']); // convert from MB to B
                } else {
                    $result = $this->getRequest($fullUrl, $params, true, 0);
                    $result = json_decode($result, true);
                    $success = isset($result['cmd']['success']) && $result['cmd']['success'] == true;
                }

                if (($this->curlResponseCode == 200 && $success) || $dataUsage != null) {
                    $finalResult[$connectionId] = [
                        "DATA_ROUNDED_BYTES_TOTAL" => [
                            [
                                "ts" => intval($from),
                                "v" => $dataUsage ?? ($this->summate($result, $connectionId, $from))*1000 // covert from KB to B
                            ]
                        ]
                    ];
                } else {
                    // if we get here then return the error message we got from Telit api
                    return response()->json(['errorMessages' => $result]);
                }
            }
        }
        return $finalResult;
    }

    public function summate($result, $connectionId, $timeStamp) {
        // Summate all of the data usage for the entire month

        // Decode the JSON result if it's not already decoded
        if (is_string($result)) {
            $result = json_decode($result, true);
        }

        // Validate the structure of the result
        if (!isset($result['cmd']['params']['values'])) {
            return response()->json(['errorMessages' => 'Invalid response structure']);
        }

        $monthlyDataUsage = 0.0;
        foreach ($result['cmd']['params']['values'] as $value) {
            $monthlyDataUsage += $value['value'];
        }
        return $monthlyDataUsage;
    }

    public function activateRequestBody(array $uids, string $account, string $plan = "10MB", $includeOperatorParameters)
    {
        // Telit does not implement this function.
        // This function must exist, even if it has no implementation, because it is required by the CellDataProvider interface.
    }

    private function setStatus($device, $status, $plan, $saveSerialNumber) {
        // https://docs.devicewise.com/Content/Products/IoT_Portal_API_Reference_Guide/APIs/CDP/cdp.connection.update.htm
   
        // Telit does not return an operation like AirVantage does when changing the status.

        // "You will experience a delay while using cdp.connection.update, especially while updating status and ratePlan parameters.
        // When status or ratePlan is changed, the CDP needs to authorize and synchronize the update for the change to appear.
        // Sometimes, it will take up to 24 hours to view the change, but can be hasten[ed] by forcing a refresh of the connection."

        // Here's now to refresh --> https://docs.devicewise.com/Content/Products/IoT_Portal_API_Reference_Guide/APIs/CDP/cdp.connection.refresh.htm

        // REQUEST EXAMPLE
        // {
        //     "auth": {
        //         "sessionId": "3fbb0a0e349395de2e6e01c6a918fb3b"
        //       },
        //      "cmd" : {
        //      "command": "cdp.connection.update",
        //      "params": {
        //        "id": "65cd060e7898dd5e915e7640",
        //        "status": "activated",
        //        "tags": ["tag1","tag2"]
        //      }
        //   }
        // }
        // RESPONSE EXAMPLE
        // {
        //     "cmd": {
        //         "success": true,
        //         "params": {
        //             "count": 1
        //         }
        //     }
        // }

        $fullUrl = config($this->apiUrl);

        if (empty($device->air_vantage_uid)) {
            $air_vantage_uid = $this->getUIDFromICCID($device->iccid);
            if($air_vantage_uid !== null) {
                $device->air_vantage_uid = $air_vantage_uid;	
                $device->save();
            }
        }

        $params = [
            'id' => $device->air_vantage_uid,
        ];

        if (!is_null($status)) {
            $params['status'] = $status;
        }

        if (!is_null($plan)) {
            $params['tags'] = [$this->getOfferIDbyPlan($plan)];
        }

        if ($saveSerialNumber) {
            $params['custom1'] = $device->serial_number; // save serial number to Telit's custom1 field.
        }

        $body = [
            'auth' => [
                "sessionId" => Cache::get($this->sessionId)
            ],
            'cmd' => [
                'command' => 'cdp.connection.update',
                'params' => $params
            ]
        ];

        $result = $this->getRequest($fullUrl, $body, false, 0);
        $result = json_decode($result, true);
        $success = isset($result['cmd']['success']) && $result['cmd']['success'] == true; // The Telit response code will be 200, even if it fails.

        if ($this->curlResponseCode == 200 && $success) {
            return $result;
        } else {
            // retry authentication
            $this->fullAuthRefresh();
            // try request again
            $result = $this->getRequest($fullUrl, $body, false, 0);
            $result = json_decode($result, true);
            $success = isset($result['cmd']['success']) && $result['cmd']['success'] == true;
            if ($this->curlResponseCode == 200 && $success) {
                return $result;
            }

            // if we get here then return the error message we got from Telit api
            return response()->json(['errorMessages' => $result]);
        }
    }

    public function suspend (array $devices, $accountId = "") {
        // 'suspend(ed)' is not a supported Telit status. We are interpreting this as 'deactivated' in Telit.
        $results = [];
        foreach($devices as $device) {
            $result = $this->setStatus($device, "deactivated", null, false);
            array_push($results, $result);
        }
        return $results;
    }

    public function resume(array $devices, $accountId = "") {
        // 'resume(d)' is not a supported Telit status. We are interpreting this as 'activated' in Telit.
        $results = [];
        foreach($devices as $device) {
            $result = $this->setStatus($device, "activated", null, false);
            array_push($results, $result);
        }
        return $results;
    }

    public function changeOffer(array $devices, $accoundId = "", $plan = "10MB") {
        // We do not change the rate plan in Telit, but we can set a cutoff using a trigger.
        $results = [];
        foreach($devices as $device) {
            $result = $this->setStatus($device, null, $plan, false);
            array_push($results, $result);
        }
        return $results;
    }

    public function activate(Device $device, $accountId, $plan = "10MB")
    {
        $results = [];
        // note the difference in purpose and content between this and the resume function
        $statusResult = $this->setStatus($device, "activated", null, true); // this fails if it's already activated,
        array_push($results, $statusResult);
        $planResult = $this->changeOffer([$device], $accountId, $plan); // which is why we call setStatus twice.
        array_push($results, $planResult);
        return $planResult[0];
    }

    public function networkDetach(Device $device){
        // Telit does not have an aparent networkDetach feature, so we are using the connection refresh.
        // https://docs.devicewise.com/Content/Products/IoT_Portal_API_Reference_Guide/APIs/CDP/cdp.connection.refresh.htm
        $fullUrl = config($this->apiUrl);

        if (empty($device->air_vantage_uid)) {
            $air_vantage_uid = $this->getUIDFromICCID($device->iccid);
            if($air_vantage_uid !== null) {
                $device->air_vantage_uid = $air_vantage_uid;	
                $device->save();
            }
        }

        $params = [
            'auth' => [
                "sessionId" => Cache::get($this->sessionId)
            ],
            'cmd' => [
                'command' => 'cdp.connection.refresh',
                'params' => [
                    'id' => $device->air_vantage_uid,
                    'priority' => 'high'
                ]
            ]
        ];

        $result = $this->getRequest($fullUrl, $params, false, 0);
        $result = json_decode($result, true);
        $success = isset($result['cmd']['success']) && $result['cmd']['success'] == true;

        if ($this->curlResponseCode == 200 && $success) {
            return $result;
        }
        else {
            $this->fullAuthRefresh();
            $result = $this->getRequest($fullUrl, $params, false, 0);
            $result = json_decode($result, true);
            $success = isset($result['cmd']['success']) && $result['cmd']['success'] == true;
            if ($this->curlResponseCode == 200 && $success) {
                return $result;
            } else {
                return response()->json(['errorMessages' => $result]);
            }
        }
    }

    public function getOperation(string $operation)
    {
        // Telit does not support operations
        // This function is only ever used in the AirVantageTest.php
        // This function must exist, even if it has no implementation, because it is required by the CellDataProvider interface.
    }

    public function getLifeCycleState(Device $device)
    {
        $response = $this->getConnection($device->iccid);
        $data = json_decode($response, true);

        if (isset($data['cmd']['params']['status'])) {
            $status = $data['cmd']['params']['status'];
        } else {
            $this->fullAuthRefresh();
            if (isset($data['cmd']['params']['status'])) {
                $status = $data['cmd']['params']['status'];
            }
            else {
                $status = "UNKOWN";
            }
        }

        // I've only returned ACTIVE, RETIRED, and TEST_READY because these are the only values compared elsewhere in this project.
        if ($status != null) {
            if ($status == 'activated') { return 'ACTIVE'; }
            else if ($status == 'retired') { return 'RETIRED'; }
            else if ($status == 'testing' || $status == 'ready') { return 'TEST_READY'; }
            else return $status;
        }
        return 'NULL';
    }

    public function getUIDFromICCID(string $iccid)
    {
        $response = $this->getConnection($iccid);
        $data = json_decode($response, true);

        // Check if decoding was successful and the necessary structure exists
        if (isset($data['cmd']['params']['id'])) {
            $connectionId = $data['cmd']['params']['id'];
            return $connectionId;
        } else {
            return null;
        }
    }

    public function setActiveProfile(string $eid, string $iccid) {
        $fullUrl = config($this->apiUrl);

        $params = [
            'auth' => [
                "sessionId" => Cache::get($this->sessionId)
            ],
            'cmd' => [
                'command' => 'cdp.euicc.profile.set_active',
                'params' => [
                    'eid' => $eid,
                    'iccid' => $iccid
                ]
            ]
        ];

        $result = $this->getRequest($fullUrl, $params, false, 0);
        $result = json_decode($result, true);
        $success = isset($result['cmd']['success']) && $result['cmd']['success'] == true;

        if ($this->curlResponseCode == 200 && $success) {
            return true;
        }
        else {
            $this->fullAuthRefresh();
            $result = $this->getRequest($fullUrl, $params, false, 0);
            $result = json_decode($result, true);
            $success = isset($result['cmd']['success']) && $result['cmd']['success'] == true;
            if ($this->curlResponseCode == 200 && $success) {
                return true;
            } else {
                return response()->json(['errorMessages' => $result]);
            }
        }
    }
}